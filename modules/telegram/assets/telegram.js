/**
 * The Telegram tab (web/telegram.php in the module): connecting the person's
 * own Telegram (phone → code → password), then their chats: conversation,
 * photos and files both ways, new chats with contacts or a phone number. Everything is textContent; message text is never HTML.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  if (!$('tgPage')) return;

  const base = window.ecwWebBase || '';
  const csrf = () => document.body.dataset.csrf || '';
  let status = null;
  let chats = [];
  let active = null;
  let lastSig = '';
  let attached = null;
  let chatsStarted = false;

  async function api(action, params, body) {
    const url = new URL(base + 'api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    let init = {};
    if (body instanceof FormData) init = { method: 'POST', headers: { 'X-ECW-CSRF': csrf() }, body };
    else if (body !== undefined) init = { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body) };
    const res = await fetch(url, init);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { const e = new Error(data.error || 'Telegram request failed (' + res.status + ')'); e.notConnected = !!data.not_connected; throw e; }
    return data;
  }

  function notice(msg) {
    let n = document.querySelector('.teams-notice');
    if (!n) { n = document.createElement('div'); n.className = 'teams-notice'; n.setAttribute('role', 'status'); document.body.appendChild(n); }
    n.textContent = msg; n.hidden = false;
    clearTimeout(n._t); n._t = setTimeout(() => { n.hidden = true; }, 8000);
  }
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  function timeOf(iso) {
    const d = new Date(iso); const now = new Date();
    return d.toDateString() === now.toDateString() ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString([], { day: 'numeric', month: 'short' });
  }

  // ============================================================ connecting
  const steps = ['tgStepPhone', 'tgStepCode', 'tgStepPassword', 'tgWorking'];
  function showStep(id) { steps.forEach((s) => { $(s).hidden = s !== id; }); }
  function connectError(msg) { $('tgConnectError').textContent = msg || ''; $('tgConnectError').hidden = !msg; }

  function drawConnect(s) {
    status = s;
    if (s.status === 'ready') { $('tgConnect').hidden = true; $('tgPage').hidden = false; startChats(); return; }
    $('tgConnect').hidden = false;
    if (!chatsStarted) $('tgPage').hidden = true;
    $('tgOldChats').hidden = !(s.synced && (s.status === 'logged_out' || s.status === 'error'));
    if (s.busy || s.status === 'new') {
      showStep('tgWorking');
      $('tgWorkingText').textContent = s.status === 'code' ? 'Checking the code…' : s.status === 'password' ? 'Checking the password…' : s.status === 'new' ? 'Asking Telegram to send you a code…' : 'Working…';
      connectError(s.daemon_ok ? '' : 'The Telegram service of this server is not running. An admin starts it with docker compose up -d.');
      return;
    }
    connectError(s.error);
    if (s.status === 'code') { showStep('tgStepCode'); $('tgCodeWhere').textContent = s.hint || 'Telegram sent you a code.'; $('tgCode').focus(); return; }
    if (s.status === 'password') { showStep('tgStepPassword'); $('tgPwHint').textContent = s.hint ? 'Hint: ' + s.hint : ''; $('tgPassword').focus(); return; }
    showStep('tgStepPhone');
    $('tgApiBox').hidden = !s.needs_api;
  }

  async function pollStatus() {
    for (;;) {
      let s;
      try { s = (await api('tg_status')).status; } catch (err) { connectError(err.message); await sleep(3000); continue; }
      drawConnect(s);
      if (s.status === 'ready' || !(s.busy || s.status === 'new')) return;
      await sleep(1500);
    }
  }

  async function step(action, body, btn) {
    connectError('');
    if (btn) btn.disabled = true;
    try { drawConnect((await api(action, {}, body)).status); await pollStatus(); }
    catch (err) { connectError(err.message); }
    finally { if (btn) btn.disabled = false; }
  }

  $('tgStepPhone').addEventListener('submit', (e) => {
    e.preventDefault();
    const body = { phone: $('tgPhone').value };
    if (!$('tgApiBox').hidden && ($('tgApiId').value || $('tgApiHash').value)) Object.assign(body, { api_id: $('tgApiId').value, api_hash: $('tgApiHash').value });
    step('tg_login_start', body, e.submitter);
  });
  $('tgStepCode').addEventListener('submit', (e) => { e.preventDefault(); step('tg_login_code', { code: $('tgCode').value }, e.submitter).then(() => { $('tgCode').value = ''; }); });
  $('tgStepPassword').addEventListener('submit', (e) => { e.preventDefault(); const pw = $('tgPassword').value; $('tgPassword').value = ''; step('tg_login_password', { password: pw }, e.submitter); });
  document.querySelectorAll('.tg-restart').forEach((b) => b.addEventListener('click', () => { connectError(''); showStep('tgStepPhone'); $('tgApiBox').hidden = !(status && status.needs_api); }));
  $('tgShowOld').addEventListener('click', () => { $('tgPage').hidden = false; startChats(); $('tgPage').scrollIntoView(); });
  $('tgDisconnect').addEventListener('click', async (e) => {
    if (window.ecwArm && !window.ecwArm(e.currentTarget, 'Click again to disconnect')) return;
    try { const s = (await api('tg_disconnect', {}, {})).status; active = null; chatsStarted = false; drawConnect(s); notice('Disconnected. Your chats stay readable here.'); }
    catch (err) { notice(err.message); }
  });

  // ================================================================ chats
  function canSend() { return status && status.status === 'ready' && status.here; }

  function renderList() {
    const q = $('tgFilter').value.trim().toLowerCase();
    const list = $('tgList');
    list.textContent = '';
    const shown = chats.filter((c) => !q || c.title.toLowerCase().includes(q) || c.username.toLowerCase().includes(q.replace(/^@/, '')) || (q.replace(/\D/g, '') && c.phone.replace(/\D/g, '').includes(q.replace(/\D/g, ''))));
    if (!shown.length) list.appendChild(el('li', 'muted teams-empty', q ? 'No chats found.' : (status && !status.synced ? 'Loading your chats from Telegram…' : 'No chats yet. Press New to write to a contact.')));
    shown.forEach((c) => {
      const li = el('li');
      const b = el('button', 'teams-chat-item' + (c.unread ? ' unread' : '') + (active && active.id === c.id ? ' active' : ''));
      b.type = 'button';
      b.appendChild(window.ecwAvatar ? window.ecwAvatar({ name: c.title, email: 'tg' + c.id }, 34) : el('span'));
      const t = el('span', 'teams-chat-text');
      const top = el('span', 'teams-chat-top');
      top.append(el('span', 'teams-chat-name', c.title), el('span', 'teams-chat-time', c.last_at ? timeOf(c.last_at) : ''));
      t.append(top, el('span', 'teams-chat-preview', (c.unread ? '(' + c.unread + ') ' : '') + c.preview));
      b.appendChild(t);
      b.addEventListener('click', () => open(c));
      li.appendChild(b);
      list.appendChild(li);
    });
  }

  async function loadChats() {
    try {
      const r = await api('tg_chats');
      chats = r.chats; status = r.status;
      // Signed out meanwhile (e.g. from the phone): show how to connect again, above the chats.
      if (status.status !== 'ready' && $('tgConnect').hidden) drawConnect(status);
      if (active) active = chats.find((c) => c.id === active.id) || active;
      renderList();
    } catch (err) {
      $('tgList').textContent = '';
      $('tgList').appendChild(el('li', 'teams-error', err.message));
    }
  }

  function renderMessages(messages, loading) {
    const box = $('tgMessages');
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.textContent = '';
    if (!messages.length) box.appendChild(el('p', 'muted teams-empty', loading ? 'Loading the chat from Telegram…' : 'No messages yet. Say hello.'));
    else if (loading) box.appendChild(el('p', 'muted teams-empty', 'Loading earlier messages…'));
    let day = '';
    messages.forEach((m) => {
      const d = new Date(m.at).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
      if (d !== day) { day = d; box.appendChild(el('div', 'tg-day', d)); }
      const row = el('div', 'teams-msg tg-msg' + (m.direction === 'out' ? ' mine' : ''));
      row.dataset.id = m.id;
      if (m.direction === 'out' && m.sender) row.appendChild(el('span', 'teams-msg-from', m.sender));
      const bubble = el('div', 'teams-msg-bubble');
      if (m.has_media) bubble.appendChild(media(m));
      else if (m.filename && m.direction === 'out' && m.status === 'sending') bubble.appendChild(el('div', 'teams-msg-file', '📄 ' + m.filename));
      if (m.body) {
        const body = el('div', 'teams-msg-body tg-text');
        m.body.split(/(https:\/\/maps\.google\.com\/\?q=[\d.,-]+)/).forEach((part, i) => {
          if (i % 2) { const a = el('a', '', 'Open the map'); a.href = part; a.target = '_blank'; a.rel = 'noopener'; body.appendChild(a); } else body.append(part);
        });
        bubble.appendChild(body);
      }
      const meta = el('div', 'teams-msg-time', timeOf(m.at) + (m.edited ? ' · edited' : ''));
      if (m.direction === 'out') meta.append(' ', el('span', 'tg-tick tg-' + m.status, { sending: '🕓', sent: '✓', read: '✓✓', failed: '⚠ not sent' }[m.status] || ''));
      bubble.appendChild(meta);
      if (m.status === 'failed' && m.error) bubble.appendChild(el('div', 'tg-fail', m.error));
      row.appendChild(bubble);
      box.appendChild(row);
    });
    if (atBottom || box.dataset.first === '1') { box.scrollTop = box.scrollHeight; box.dataset.first = ''; }
    // Modules (linking messages to a case) mark the rows again after each redraw.
    box.dispatchEvent(new CustomEvent('ecw:chat-rendered', { bubbles: true }));
  }

  function media(m) {
    const src = base + 'api/index.php?action=tg_media&message_id=' + m.id;
    if (m.kind === 'image' || m.kind === 'sticker') {
      const a = el('a'); a.href = src; a.target = '_blank'; a.rel = 'noopener';
      const img = el('img', 'tg-img'); img.src = src; img.alt = m.kind === 'sticker' ? 'Sticker' : 'Photo'; img.loading = 'lazy';
      img.dataset.mime = m.mime || ''; if (m.filename) img.dataset.name = m.filename;   // for the preview (teams-dock.js)
      img.addEventListener('load', () => { const b = $('tgMessages'); if (b.scrollHeight - b.scrollTop - b.clientHeight < 400) b.scrollTop = b.scrollHeight; });
      a.appendChild(img); return a;
    }
    if (m.kind === 'audio') { const au = el('audio'); au.controls = true; au.preload = 'none'; au.src = src; return au; }
    if (m.kind === 'video') { const v = el('video', 'tg-img'); v.controls = true; v.preload = 'none'; v.src = src; return v; }
    const a = el('a', 'teams-msg-file', '📄 ' + (m.filename || 'File') + (m.size ? ' (' + Math.max(1, Math.round(m.size / 1024)) + ' KB)' : '')); a.href = src; return a;
  }

  function drawHead() {
    const c = active;
    $('tgTitle').textContent = c.title;
    $('tgSub').textContent = [c.username ? '@' + c.username : '', c.phone].filter(Boolean).join(' · ');
    $('tgCompose').hidden = !canSend();
  }

  async function loadMessages() {
    if (!active || document.hidden) return;
    const id = active.id;
    try {
      const r = await api('tg_messages', { chat_id: id });
      if (!active || active.id !== id) return;
      const sig = r.chat.loading + ':' + r.messages.map((m) => m.id + m.status + (m.edited ? 'e' : '') + (m.has_media ? 'f' : '')).join(',');
      Object.assign(active, r.chat);
      drawHead();
      if (sig !== lastSig) { lastSig = sig; renderMessages(r.messages, r.chat.loading); }
      const inList = chats.find((c) => c.id === id);
      if (inList && inList.unread) { inList.unread = 0; renderList(); }
    } catch (err) {
      if (active && active.id === id) $('tgMessages').textContent = err.message;
    }
  }

  function open(c) {
    active = c; lastSig = '';
    $('tgPage').classList.add('chat-open');
    $('tgPlaceholder').hidden = true;
    $('tgHead').hidden = false; $('tgMessages').hidden = false;
    $('tgMessages').textContent = 'Loading…';
    $('tgMessages').dataset.first = '1';
    // Which chat the list shows (modules use it, e.g. to link messages to a case).
    Object.assign($('tgMessages').dataset, { source: 'telegram', chat: c.id, title: c.title });
    $('tgMessages').dispatchEvent(new CustomEvent('ecw:chat-opened', { bubbles: true }));
    drawHead(); renderList(); loadMessages();
    history.replaceState(null, '', '?m=telegram&p=telegram&chat=' + c.id);
    if (canSend()) $('tgText').focus();
  }

  function setAttached(f) {
    attached = f || null;
    $('tgAttached').hidden = !attached;
    $('tgAttachedName').textContent = attached ? '📎 ' + attached.name + ' (' + Math.max(1, Math.round(attached.size / 1024)) + ' KB)' : '';
    if (!attached) $('tgFile').value = '';
  }
  $('tgFile').addEventListener('change', () => {
    const f = $('tgFile').files[0];
    if (f && f.size > 8 * 1024 * 1024) { notice('Files up to 8 MB can be sent from here; send larger ones from Telegram.'); setAttached(null); return; }
    setAttached(f);
    $('tgText').focus();
  });
  $('tgAttachedRemove').addEventListener('click', () => setAttached(null));
  // Paste a screenshot straight into the message.
  $('tgText').addEventListener('paste', (e) => {
    const f = [...(e.clipboardData && e.clipboardData.files || [])][0];
    if (f) { e.preventDefault(); setAttached(new File([f], f.name || 'screenshot.png', { type: f.type })); }
  });

  $('tgSendForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = $('tgText').value.trim();
    if ((!text && !attached) || !active) return;
    const btn = $('tgSendForm').querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      if (attached) {
        const fd = new FormData();
        fd.append('chat_id', String(active.id)); fd.append('text', text); fd.append('file', attached, attached.name);
        await api('tg_send', {}, fd);
      } else {
        await api('tg_send', {}, { chat_id: active.id, text });
      }
      $('tgText').value = ''; $('tgText').style.height = ''; setAttached(null);
      lastSig = ''; await loadMessages(); loadChats();
      // Watch it go from 🕓 to ✓.
      setTimeout(loadMessages, 1200); setTimeout(loadMessages, 3000);
    } catch (err) { notice(err.message); } finally { btn.disabled = false; $('tgText').focus(); }
  });
  $('tgText').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('tgSendForm').requestSubmit(); } });
  $('tgText').addEventListener('input', () => { const t = $('tgText'); t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 140) + 'px'; });
  $('tgBack').addEventListener('click', () => $('tgPage').classList.remove('chat-open'));
  $('tgFilter').addEventListener('input', renderList);

  // ------------------------------------------------------------- new chat
  const newDlg = $('tgNewDialog');
  let newTimer = 0;
  function newError(msg) { $('tgNewError').textContent = msg || ''; $('tgNewError').hidden = !msg; }
  async function findContacts() {
    const list = $('tgNewResults');
    try {
      const { contacts } = await api('tg_contacts', { q: $('tgNewQ').value.trim() });
      list.textContent = '';
      if (!contacts.length) list.appendChild(el('li', 'muted', $('tgNewQ').value.trim() ? 'No contact found. Below you can write to a phone number.' : 'Your contacts appear here once Telegram has sent them (a minute after connecting).'));
      contacts.forEach((c) => {
        const li = el('li'); li.setAttribute('role', 'option');
        const w = el('span', 'nc-who');
        w.append(el('strong', '', c.name || (c.username ? '@' + c.username : c.phone)), el('span', 'muted', [c.username ? '@' + c.username : '', c.phone].filter(Boolean).join(' · ')));
        li.appendChild(w);
        li.addEventListener('click', async () => {
          try { const r = await api('tg_open_contact', {}, { peer_id: c.peer_id }); newDlg.close(); await openById(r.chat_id); }
          catch (err) { newError(err.message); }
        });
        list.appendChild(li);
      });
    } catch (err) { list.textContent = ''; list.appendChild(el('li', 'teams-error', err.message)); }
  }
  async function openById(chatId) {
    await loadChats();
    const c = chats.find((x) => x.id === chatId);
    if (c) open(c);
  }
  $('tgNewBtn').addEventListener('click', () => {
    if (!canSend()) { notice('Connect your Telegram to start chats.'); return; }
    $('tgNewQ').value = ''; newError(''); $('tgNewNumberBox').open = false;
    newDlg.showModal(); $('tgNewQ').focus(); findContacts();
  });
  $('tgNewQ').addEventListener('input', () => { clearTimeout(newTimer); newTimer = setTimeout(findContacts, 200); });
  $('tgNewCancel').addEventListener('click', () => newDlg.close());
  $('tgNewNumberGo').addEventListener('click', async () => {
    const btn = $('tgNewNumberGo');
    newError(''); btn.disabled = true; btn.textContent = 'Asking Telegram…';
    try {
      const { job } = await api('tg_new_number', {}, { phone: $('tgNewPhone').value, first: $('tgNewFirst').value, last: $('tgNewLast').value });
      for (let i = 0; i < 40; i++) {
        await sleep(1000);
        const j = await api('tg_job', { id: job });
        if (j.status === 'failed') throw new Error(j.error || 'Telegram did not find this number.');
        if (j.status === 'done') { newDlg.close(); await openById(j.result.chat_id); return; }
      }
      throw new Error('Telegram is taking long. Try again in a minute.');
    } catch (err) { newError(err.message); } finally { btn.disabled = false; btn.textContent = 'Start the chat'; }
  });

  // ------------------------------------------------------------- start
  function startChats() {
    if (chatsStarted) { loadChats(); return; }
    chatsStarted = true;
    loadChats().then(() => {
      const want = Number(new URLSearchParams(location.search).get('chat'));
      const c = want && chats.find((x) => x.id === want);
      if (c) open(c);
    });
  }
  setInterval(() => { if (chatsStarted) loadMessages(); }, 3000);
  setInterval(() => { if (chatsStarted && !document.hidden) loadChats(); }, 8000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && chatsStarted) { loadMessages(); loadChats(); } });

  pollStatus();
})();
