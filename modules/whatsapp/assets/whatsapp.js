/**
 * The WhatsApp tab (web/whatsapp.php in the module): chat list, conversation, the 24-hour
 * window, templates. Everything is textContent; message text
 * is the customer's and never HTML.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  if (!$('waPage')) return;

  const base = window.ecwWebBase || '';
  const csrf = () => document.body.dataset.csrf || '';
  let chats = [];
  let active = null;
  let lastSig = '';
  let templates = null;

  async function api(action, params, body) {
    const url = new URL(base + 'api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    let init = {};
    if (body instanceof FormData) init = { method: 'POST', headers: { 'X-ECW-CSRF': csrf() }, body };
    else if (body !== undefined) init = { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body) };
    const res = await fetch(url, init);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'WhatsApp request failed (' + res.status + ')');
    return data;
  }

  function notice(msg) {
    let n = document.querySelector('.teams-notice');
    if (!n) { n = document.createElement('div'); n.className = 'teams-notice'; n.setAttribute('role', 'status'); document.body.appendChild(n); }
    n.textContent = msg; n.hidden = false;
    clearTimeout(n._t); n._t = setTimeout(() => { n.hidden = true; }, 8000);
  }

  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  function timeOf(iso) {
    const d = new Date(iso); const now = new Date();
    return d.toDateString() === now.toDateString() ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString([], { day: 'numeric', month: 'short' });
  }
  function hoursLeft(iso) { return Math.max(0, Math.round((new Date(iso) - Date.now()) / 36e5)); }

  // ---------------------------------------------------------------- list
  function renderList() {
    const q = $('waFilter').value.trim().toLowerCase();
    const list = $('waList');
    list.textContent = '';
    const shown = chats.filter((c) => !q || c.title.toLowerCase().includes(q) || c.phone.replace(/\D/g, '').includes(q.replace(/\D/g, '') || '~'));
    if (!shown.length) list.appendChild(el('li', 'muted teams-empty', q ? 'No chats found.' : 'No WhatsApp chats yet. They appear here when a customer writes to your number, or press New.'));
    shown.forEach((c) => {
      const li = el('li');
      const b = el('button', 'teams-chat-item' + (c.unread ? ' unread' : '') + (active && active.id === c.id ? ' active' : ''));
      b.type = 'button';
      b.appendChild(window.ecwAvatar ? window.ecwAvatar({ name: c.name || c.phone, email: c.phone }, 34) : el('span'));
      const t = el('span', 'teams-chat-text');
      const top = el('span', 'teams-chat-top');
      top.append(el('span', 'teams-chat-name', c.title), el('span', 'teams-chat-time', c.last_at ? timeOf(c.last_at) : ''));
      t.append(top, el('span', 'teams-chat-preview', c.preview));
      b.appendChild(t);
      b.addEventListener('click', () => open(c));
      li.appendChild(b);
      list.appendChild(li);
    });
  }

  async function loadChats() {
    try {
      chats = (await api('wa_chats')).chats;
      if (active) active = chats.find((c) => c.id === active.id) || active;
      renderList();
    } catch (err) {
      $('waList').textContent = '';
      $('waList').appendChild(el('li', 'teams-error', err.message));
    }
  }

  // --------------------------------------------------------- conversation
  function renderMessages(messages) {
    const box = $('waMessages');
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.textContent = '';
    if (!messages.length) box.appendChild(el('p', 'muted teams-empty', 'No messages yet.'));
    let day = '';
    messages.forEach((m) => {
      const d = new Date(m.at).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
      if (d !== day) { day = d; box.appendChild(el('div', 'wa-day', d)); }
      const row = el('div', 'teams-msg wa-msg' + (m.direction === 'out' ? ' mine' : ''));
      row.dataset.id = m.id;
      if (m.direction === 'out' && m.sender) row.appendChild(el('span', 'teams-msg-from', m.sender));
      const bubble = el('div', 'teams-msg-bubble' + (m.kind === 'template' ? ' wa-template' : ''));
      if (m.has_media) bubble.appendChild(media(m));
      if (m.kind === 'reaction') bubble.appendChild(el('div', 'teams-msg-body', 'Reacted ' + m.body));
      else if (m.body) {
        const body = el('div', 'teams-msg-body wa-text');
        // Links (a shared location) become clickable; the rest stays text.
        m.body.split(/(https:\/\/maps\.google\.com\/\?q=[\d.,-]+)/).forEach((part, i) => {
          if (i % 2) { const a = el('a', '', 'Open the map'); a.href = part; a.target = '_blank'; a.rel = 'noopener'; body.appendChild(a); } else body.append(part);
        });
        bubble.appendChild(body);
      }
      const meta = el('div', 'teams-msg-time', timeOf(m.at));
      if (m.direction === 'out') meta.append(' ', el('span', 'wa-tick wa-' + m.status, { sending: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠ not delivered' }[m.status] || ''));
      bubble.appendChild(meta);
      if (m.status === 'failed' && m.error) bubble.appendChild(el('div', 'wa-fail', m.error));
      row.appendChild(bubble);
      box.appendChild(row);
    });
    if (atBottom || box.dataset.first === '1') { box.scrollTop = box.scrollHeight; box.dataset.first = ''; }
    // Modules (linking messages to a case) mark the rows again after each redraw.
    box.dispatchEvent(new CustomEvent('ecw:chat-rendered', { bubbles: true }));
  }

  function media(m) {
    const src = base + 'api/index.php?action=wa_media&message_id=' + m.id;
    if (m.kind === 'image' || m.kind === 'sticker') {
      const a = el('a'); a.href = src; a.target = '_blank'; a.rel = 'noopener';
      const img = el('img', 'wa-img'); img.src = src; img.alt = 'Photo'; img.loading = 'lazy';
      img.dataset.mime = m.mime || ''; if (m.filename) img.dataset.name = m.filename;   // for the preview (teams-dock.js)
      img.addEventListener('load', () => { const b = $('waMessages'); if (b.scrollHeight - b.scrollTop - b.clientHeight < 400) b.scrollTop = b.scrollHeight; });
      a.appendChild(img); return a;
    }
    if (m.kind === 'audio') { const au = el('audio'); au.controls = true; au.preload = 'none'; au.src = src; return au; }
    if (m.kind === 'video') { const v = el('video', 'wa-img'); v.controls = true; v.preload = 'none'; v.src = src; return v; }
    const a = el('a', 'teams-msg-file', '📄 ' + (m.filename || 'Document')); a.href = src; return a;
  }

  function drawHead() {
    const c = active;
    $('waTitle').textContent = c.title;
    $('waPhone').textContent = c.name ? c.phone : '';
    const open = c.window_open;
    $('waSendForm').hidden = !open;
    $('waClosed').hidden = open;
    $('waWindow').hidden = !open;
    if (open && c.window_closes) {
      const h = hoursLeft(c.window_closes);
      $('waWindow').textContent = h <= 1 ? 'Less than an hour left to reply with free text; after that, only a template.' : 'You can reply freely for ' + h + ' more hours.';
    }
  }

  async function loadMessages() {
    if (!active || document.hidden) return;
    const id = active.id;
    try {
      const r = await api('wa_messages', { chat_id: id });
      if (!active || active.id !== id) return;
      const sig = r.messages.length + ':' + r.messages.map((m) => m.status).join('');
      Object.assign(active, r.chat);
      drawHead();
      if (sig !== lastSig) { lastSig = sig; renderMessages(r.messages); }
      const inList = chats.find((c) => c.id === id);
      if (inList && inList.unread) { inList.unread = 0; renderList(); }
    } catch (err) {
      if (active && active.id === id) $('waMessages').textContent = err.message;
    }
  }

  function open(c) {
    active = c; lastSig = '';
    $('waPage').classList.add('chat-open');
    $('waPlaceholder').hidden = true;
    $('waHead').hidden = false; $('waMessages').hidden = false; $('waCompose').hidden = false;
    $('waMessages').textContent = 'Loading…';
    $('waMessages').dataset.first = '1';
    // Which chat the list shows (modules use it, e.g. to link messages to a case).
    Object.assign($('waMessages').dataset, { source: 'whatsapp', chat: c.id, title: c.title });
    $('waMessages').dispatchEvent(new CustomEvent('ecw:chat-opened', { bubbles: true }));
    drawHead(); renderList(); loadMessages();
    history.replaceState(null, '', '?m=whatsapp&p=whatsapp&chat=' + c.id);
    if (c.window_open) $('waText').focus();
  }

  // A photo or file to send with the next message (button, or a pasted screenshot).
  let attached = null;
  function setAttached(f) {
    attached = f || null;
    $('waAttached').hidden = !attached;
    $('waAttachedName').textContent = attached ? '📎 ' + attached.name + ' (' + Math.max(1, Math.round(attached.size / 1024)) + ' KB)' : '';
    if (!attached) $('waFile').value = '';
  }
  $('waFile').addEventListener('change', () => {
    const f = $('waFile').files[0];
    if (f && f.size > 8 * 1024 * 1024) { notice('Files up to 8 MB can be sent from here; send larger ones from WhatsApp on the phone.'); setAttached(null); return; }
    setAttached(f);
    $('waText').focus();
  });
  $('waAttachedRemove').addEventListener('click', () => setAttached(null));
  $('waText').addEventListener('paste', (e) => {
    const f = [...(e.clipboardData && e.clipboardData.files || [])][0];
    if (f) { e.preventDefault(); setAttached(new File([f], f.name || 'screenshot.png', { type: f.type })); }
  });

  $('waSendForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = $('waText').value.trim();
    if ((!text && !attached) || !active) return;
    const btn = $('waSendForm').querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      if (attached) {
        const fd = new FormData();
        fd.append('chat_id', String(active.id)); fd.append('text', text); fd.append('file', attached, attached.name);
        await api('wa_send', {}, fd);
      } else {
        await api('wa_send', {}, { chat_id: active.id, text });
      }
      $('waText').value = ''; $('waText').style.height = ''; setAttached(null);
      lastSig = ''; await loadMessages(); loadChats();
    } catch (err) { notice(err.message); } finally { btn.disabled = false; $('waText').focus(); }
  });
  $('waText').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('waSendForm').requestSubmit(); } });
  $('waText').addEventListener('input', () => { const t = $('waText'); t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 140) + 'px'; });
  $('waBack').addEventListener('click', () => $('waPage').classList.remove('chat-open'));
  $('waFilter').addEventListener('input', renderList);

  // ------------------------------------------------------------ templates
  const tplDlg = $('waTplDialog');
  let tplForNew = false;
  function tplPreview() {
    const t = templates && templates[$('waTplPick').value];
    const vals = [...$('waTplParams').querySelectorAll('input')].map((i) => i.value);
    $('waTplPreview').textContent = t ? t.body.replace(/\{\{(\d+)\}\}/g, (m, n) => vals[n - 1] || m) : '';
  }
  function tplParams() {
    const box = $('waTplParams');
    box.textContent = '';
    const t = templates && templates[$('waTplPick').value];
    for (let i = 1; t && i <= t.params; i++) {
      const lab = el('label', '', 'Fill in {{' + i + '}}'); lab.htmlFor = 'waP' + i;
      const inp = el('input'); inp.type = 'text'; inp.id = 'waP' + i; inp.maxLength = 1000;
      if (i === 1 && active && !tplForNew && active.name) inp.value = active.name.split(' ')[0];
      inp.addEventListener('input', tplPreview);
      box.append(lab, inp);
    }
    tplPreview();
  }
  async function openTemplates(forNew) {
    tplForNew = forNew;
    $('waTplPhoneRow').hidden = !forNew;
    $('waTplTitle').textContent = forNew ? 'Message a new number' : 'Send a template';
    $('waTplError').hidden = true;
    $('waTplPhone').value = '';
    tplDlg.showModal();
    (forNew ? $('waTplPhone') : $('waTplPick')).focus();
    const pick = $('waTplPick');
    try {
      if (!templates) templates = (await api('wa_templates')).templates;
      pick.textContent = '';
      if (!templates.length) { pick.appendChild(el('option', '', 'No approved templates yet')); pick.value = ''; $('waTplPreview').textContent = 'Create one in Meta → WhatsApp Manager → Message templates. Approval usually takes minutes.'; return; }
      templates.forEach((t, i) => { const o = el('option', '', t.name.replace(/_/g, ' ') + ' (' + t.language + ')'); o.value = String(i); pick.appendChild(o); });
      tplParams();
    } catch (err) { pick.textContent = ''; pick.appendChild(el('option', '', 'Could not load templates')); $('waTplPreview').textContent = err.message; }
  }
  $('waTplPick').addEventListener('change', tplParams);
  $('waTplBtn').addEventListener('click', () => openTemplates(false));
  $('waNewBtn').addEventListener('click', () => openTemplates(true));
  $('waTplCancel').addEventListener('click', () => tplDlg.close());
  $('waTplForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const t = templates && templates[$('waTplPick').value];
    if (!t) return;
    const btn = $('waTplSend');
    btn.disabled = true; btn.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> Sending…';
    $('waTplError').hidden = true;
    try {
      const body = { name: t.name, language: t.language, body: t.body, params: [...$('waTplParams').querySelectorAll('input')].map((i) => i.value) };
      if (tplForNew) body.phone = $('waTplPhone').value; else body.chat_id = active.id;
      const r = await api('wa_send_template', {}, body);
      tplDlg.close();
      await loadChats();
      const c = chats.find((x) => x.id === r.chat_id);
      if (c) open(c); else { lastSig = ''; loadMessages(); }
    } catch (err) {
      $('waTplError').textContent = err.message; $('waTplError').hidden = false;
    } finally { btn.disabled = false; btn.textContent = 'Send'; }
  });

  // ------------------------------------------------------------- start
  loadChats().then(() => {
    const want = Number(new URLSearchParams(location.search).get('chat'));
    const c = want && chats.find((x) => x.id === want);
    if (c) open(c);
  });
  setInterval(loadMessages, 5000);
  setInterval(() => { if (!document.hidden) loadChats(); }, 20000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { loadMessages(); loadChats(); } });
})();
