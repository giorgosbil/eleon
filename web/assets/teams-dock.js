/**
 * Email Case Workspace — floating Teams chat windows.
 *
 * Loaded on every signed-in page by partials/nav.php, so an open chat follows
 * the user between Mail, Cases and the Teams page. Open windows (and where they
 * were dragged to) live in sessionStorage and are restored on each page load.
 *
 *   window.ecwTeams.openChat({id, title})
 *   window.ecwTeams.chatWith(email, name)     → finds/creates the 1:1 chat
 *   window.ecwTeams.menu(button, people, topic) → "Chat with …" popover
 *   window.ecwTeams.status()                   → Promise<{connected, enabled, email}>
 *
 * XSS: message HTML is sanitized by the server (lib/teams.php →
 * ecw_sanitize_email_html) before it is written with innerHTML; marked below.
 * Everything else is textContent.
 */
(function () {
  'use strict';
  if (window.ecwTeams) return;

  const base = window.ecwWebBase || '/';
  const csrf = () => document.body?.dataset.csrf || window.ecwCsrf || '';
  const KEY = 'ecw.teams.windows';
  const MAX_OPEN = 3;
  const POLL_MS = 6000;
  const windows = new Map();   // chat id -> {el, list, lastSig, timer, state}
  let statusPromise = null;

  async function api(action, params, body) {
    const url = new URL(base + 'api/index.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const init = body === undefined ? {} : {
      method: 'POST', headers: { 'X-ECW-CSRF': csrf(), 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    };
    const response = await fetch(url.toString(), init);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const err = new Error(data.error || 'Teams request failed (' + response.status + ')');
      err.teamsDisabled = !!data.teams_disabled;
      err.noAccess = !!data.no_access;
      err.openInTeams = data.open_in_teams || null;
      throw err;
    }
    return data;
  }

  function status() {
    statusPromise = statusPromise || api('teams_status').catch(() => ({ connected: false, enabled: false }));
    return statusPromise;
  }

  // ------------------------------------------------------------------ cache
  // Chats and recent messages are kept in this browser (localStorage, per
  // workspace user) so the list and an opened chat appear instantly; a fresh
  // copy is fetched in the background and replaces it. Nothing here is
  // trusted: message HTML in the cache is the server-sanitized copy.

  const uid = String(window.ecwUserId || document.body?.dataset.user || '0');
  const CHATS_KEY = 'ecw.teams.chats.' + uid;
  const MSG_KEY = (id) => 'ecw.teams.msgs.' + uid + '.' + id;
  const MSG_INDEX = 'ecw.teams.msgindex.' + uid;
  const MAX_CACHED_CHATS = 30;
  const read = (k) => { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (_) { return null; } };
  const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (_) { /* quota: cache is optional */ } };

  function cachedChats() { return read(CHATS_KEY); }   // {at, chats} or null
  let chatsInFlight = null;
  /** Fresh chats from the server (one request at a time), stored in the cache. */
  function fetchChats() {
    chatsInFlight = chatsInFlight || api('teams_chats').then((d) => {
      write(CHATS_KEY, { at: Date.now(), chats: d.chats });
      document.dispatchEvent(new CustomEvent('ecw:teams-chats', { detail: d.chats }));
      return d.chats;
    }).finally(() => { chatsInFlight = null; });
    return chatsInFlight;
  }
  function cachedMessages(id) { return read(MSG_KEY(id)); }
  function storeMessages(id, messages) {
    write(MSG_KEY(id), messages.slice(-50));
    const index = (read(MSG_INDEX) || []).filter((x) => x !== id);
    index.push(id);
    while (index.length > MAX_CACHED_CHATS) { try { localStorage.removeItem(MSG_KEY(index.shift())); } catch (_) {} }
    write(MSG_INDEX, index);
  }
  async function fetchMessages(id) {
    const { messages } = await api('teams_messages', { chat_id: id });
    storeMessages(id, messages);
    return messages;
  }

  // ------------------------------------------------------------ persistence

  function loadSaved() {
    try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (_) { return []; }
  }
  function save() {
    const list = [...windows.values()].map((w) => w.state);
    try { sessionStorage.setItem(KEY, JSON.stringify(list)); } catch (_) {}
  }

  // ---------------------------------------------------------------- helpers

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function iconButton(icon, label) {
    const b = el('button', 'teams-win-btn');
    b.type = 'button';
    b.title = label;
    b.setAttribute('aria-label', label);
    const i = el('i', 'fa ' + icon);
    i.setAttribute('aria-hidden', 'true');
    b.appendChild(i);
    return b;
  }
  function timeOf(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const today = new Date().toDateString() === d.toDateString();
    return today ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  /** Render messages into a list element. Shared with the Teams page. */
  function renderMessages(list, messages) {
    const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
    list.textContent = '';
    if (!messages.length) list.appendChild(el('p', 'muted teams-empty', 'No messages yet. Say hello.'));
    let lastFrom = null;
    messages.forEach((m) => {
      if (m.system) return;
      const row = el('div', 'teams-msg' + (m.mine ? ' mine' : ''));
      row.dataset.id = m.id;
      if (!m.mine && m.from !== lastFrom) row.appendChild(el('div', 'teams-msg-from', m.from));
      const bubble = el('div', 'teams-msg-bubble');
      const body = el('div', 'teams-msg-body');
      body.innerHTML = m.html; // XSS: sanitized server-side (ecw_sanitize_email_html)
      bubble.appendChild(body);
      (m.files || []).forEach((f) => {
        const file = el(f.url ? 'a' : 'span', 'teams-msg-file');
        if (f.url) { file.href = f.url; file.target = '_blank'; file.rel = 'noopener'; }
        const i = el('i', 'fa fa-paperclip'); i.setAttribute('aria-hidden', 'true');
        file.append(i, document.createTextNode(' ' + f.name));
        bubble.appendChild(file);
      });
      bubble.appendChild(el('div', 'teams-msg-time', timeOf(m.at)));
      row.appendChild(bubble);
      list.appendChild(row);
      lastFrom = m.mine ? null : m.from;
    });
    if (nearBottom || list.dataset.first !== '0') { list.scrollTop = list.scrollHeight; list.dataset.first = '0'; }
    // Modules (linking messages to a case) mark the rows again after each redraw.
    list.dispatchEvent(new CustomEvent('ecw:chat-rendered', { bubbles: true }));
  }

  /** A message box: Enter sends, Shift+Enter is a new line. Shared with the Teams page. */
  function composer(onSend) {
    const form = el('form', 'teams-compose');
    const input = el('textarea');
    input.rows = 1;
    input.placeholder = 'Type a message';
    input.setAttribute('aria-label', 'Message');
    const send = iconButton('fa-paper-plane', 'Send');
    send.type = 'submit';
    form.append(input, send);
    const grow = () => { input.style.height = 'auto'; input.style.height = Math.min(120, input.scrollHeight) + 'px'; };
    input.addEventListener('input', grow);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      send.disabled = true;
      try { await onSend(text); input.value = ''; grow(); }
      catch (err) { alertIn(form, err.message); }
      finally { send.disabled = false; input.focus(); }
    });
    return form;
  }

  /** "Can't read this chat here" with a way out to Teams. Shared with the Teams page. */
  function noAccessNote(message, webUrl) {
    const box = el('div', 'teams-noaccess');
    const i = el('i', 'fa fa-lock'); i.setAttribute('aria-hidden', 'true');
    box.append(i, el('p', '', message));
    if (webUrl) {
      const a = el('a', 'button', 'Open in Teams');
      a.href = webUrl; a.target = '_blank'; a.rel = 'noopener';
      box.appendChild(a);
    }
    return box;
  }

  function alertIn(container, message) {
    let note = container.querySelector('.teams-error');
    if (!note) { note = el('p', 'teams-error'); container.prepend(note); }
    note.textContent = message;
    clearTimeout(note._t);
    note._t = setTimeout(() => note.remove(), 5000);
  }

  // ---------------------------------------------------------------- windows

  function layout() {
    // Undragged windows dock bottom-right, side by side, left of the launcher.
    let offset = document.querySelector('.teams-launcher') ? 84 : 16;
    [...windows.values()].reverse().forEach((w) => {
      if (w.state.left == null) {
        w.el.style.right = offset + 'px';
        w.el.style.bottom = '16px';
        w.el.style.left = 'auto';
        w.el.style.top = 'auto';
      }
      offset += (w.state.min ? 220 : 340) + 12;
    });
  }

  async function refresh(w) {
    if (w.state.min || document.hidden) return;
    try {
      const messages = await fetchMessages(w.state.id);
      const sig = messages.length + ':' + (messages.at(-1)?.id || '');
      if (sig !== w.lastSig) { w.lastSig = sig; renderMessages(w.list, messages); }
    } catch (err) {
      if (err.teamsDisabled) { close(w.state.id); return; }
      if (err.noAccess) {
        // Permanent: stop polling and say why, instead of an error every few seconds.
        clearInterval(w.timer);
        w.list.textContent = '';
        w.list.appendChild(noAccessNote(err.message, w.state.web_url));
        w.el.querySelector('.teams-compose')?.remove();
        return;
      }
      alertIn(w.list, err.message);
    }
  }

  function close(id) {
    const w = windows.get(id);
    if (!w) return;
    clearInterval(w.timer);
    w.el.remove();
    windows.delete(id);
    save();
    layout();
  }

  function openChat(chat, restored) {
    if (!chat || !chat.id) return;
    const existing = windows.get(chat.id);
    if (existing) {
      existing.state.min = false;
      existing.el.classList.remove('minimized');
      existing.el.querySelector('textarea')?.focus();
      save(); layout(); refresh(existing);
      return;
    }
    if (windows.size >= MAX_OPEN) close(windows.keys().next().value);

    const state = Object.assign({ id: chat.id, title: chat.title || 'Chat', web_url: chat.web_url || '', left: null, top: null, min: false }, restored || {});
    const win = el('section', 'teams-window' + (state.min ? ' minimized' : ''));
    win.setAttribute('role', 'dialog');
    win.setAttribute('aria-label', 'Teams chat with ' + state.title);

    const head = el('header', 'teams-win-head');
    const avatar = el('span', 'teams-win-avatar', (window.ecwInitials ? window.ecwInitials(state.title, '') : state.title.slice(0, 2)).slice(0, 2));
    const title = el('span', 'teams-win-title', state.title);
    title.title = state.title;
    const actions = el('span', 'teams-win-actions');
    const openTeams = iconButton('fa-arrow-up-right-from-square', 'Open in Teams');
    const minBtn = iconButton('fa-minus', 'Minimize');
    const closeBtn = iconButton('fa-xmark', 'Close');
    if (!state.web_url) openTeams.hidden = true;
    actions.append(openTeams, minBtn, closeBtn);
    head.append(avatar, title, actions);

    const list = el('div', 'teams-win-list');
    list.setAttribute('aria-live', 'polite');
    const cached = cachedMessages(state.id);
    if (cached) renderMessages(list, cached); else list.appendChild(el('p', 'muted teams-empty', 'Loading…'));

    const w = { el: win, list, lastSig: cached ? cached.length + ':' + (cached.at(-1)?.id || '') : '', timer: null, state };
    const form = composer(async (text) => {
      await api('teams_send', {}, { chat_id: state.id, text });
      w.lastSig = '';
      await refresh(w);
    });
    win.append(head, list, form);
    document.body.appendChild(win);
    windows.set(state.id, w);

    if (state.left != null) { win.style.left = state.left + 'px'; win.style.top = state.top + 'px'; win.style.right = 'auto'; win.style.bottom = 'auto'; }
    if (window.ecwFloatingWindow) window.ecwFloatingWindow(win, head);
    head.addEventListener('pointerup', () => {
      if (win.style.left && win.style.left !== 'auto') {
        state.left = parseInt(win.style.left, 10); state.top = parseInt(win.style.top, 10); save();
      }
    });
    head.addEventListener('dblclick', (e) => { if (!e.target.closest('button')) minBtn.click(); });
    minBtn.addEventListener('click', () => {
      state.min = !state.min;
      win.classList.toggle('minimized', state.min);
      minBtn.title = state.min ? 'Restore' : 'Minimize';
      save(); layout();
      if (!state.min) refresh(w);
    });
    closeBtn.addEventListener('click', () => close(state.id));
    openTeams.addEventListener('click', () => window.open(state.web_url, '_blank', 'noopener'));

    w.timer = setInterval(() => refresh(w), POLL_MS);
    save(); layout(); refresh(w);
    if (!restored) form.querySelector('textarea').focus();
  }

  // ------------------------------------------------------ chat with someone

  function deepLink(emails, topic) {
    const users = emails.filter(Boolean).join(',');
    let url = 'https://teams.microsoft.com/l/chat/0/0?users=' + encodeURIComponent(users);
    if (emails.length > 1 && topic) url += '&topicName=' + encodeURIComponent(topic.slice(0, 80));
    return url;
  }

  async function chatWith(email, name) {
    const s = await status();
    if (!s.enabled) { window.open(deepLink([email]), '_blank', 'noopener'); return; }
    try {
      const { chat } = await api('teams_chat_with', {}, { email });
      openChat({ id: chat.id, title: name || chat.title || email, web_url: chat.web_url || '' });
    } catch (err) {
      // Outside the organization (or Graph refused): Teams itself sends the first
      // message. A link to click, not window.open after a wait (browsers block that).
      notice(err.openInTeams ? err.message : err.message + ' You can still chat in Teams.', err.openInTeams || deepLink([email]));
    }
  }

  /** A short message near the launcher; with `link`, an "Open in Teams" button. */
  function notice(message, link) {
    let n = document.querySelector('.teams-notice');
    if (!n) { n = el('div', 'teams-notice'); n.setAttribute('role', 'status'); document.body.appendChild(n); }
    n.textContent = message;
    if (link) {
      const a = el('a', 'teams-notice-link', 'Open in Teams');
      a.href = link; a.target = '_blank'; a.rel = 'noopener';
      a.addEventListener('click', () => { n.hidden = true; });
      n.append(' ', a);
    }
    n.hidden = false;
    clearTimeout(n._t);
    n._t = setTimeout(() => { n.hidden = true; }, link ? 20000 : 9000);
  }

  /** Popover under `button`: chat with each person in-app, or a group chat in Teams. */
  async function menu(button, people, topic) {
    document.querySelector('.teams-menu')?.remove();
    const s = await status();
    const mine = (s.email || '').toLowerCase();
    const seen = new Set();
    people = people.filter((p) => {
      const e = (p.email || '').toLowerCase();
      if (!e || e === mine || seen.has(e)) return false;
      seen.add(e); return true;
    });

    const pop = el('div', 'teams-menu');
    pop.setAttribute('role', 'menu');
    if (!people.length) pop.appendChild(el('p', 'muted', 'Nobody else to chat with here.'));
    people.forEach((p) => {
      const item = el('button', 'teams-menu-item');
      item.type = 'button';
      item.setAttribute('role', 'menuitem');
      item.appendChild(window.ecwAvatar ? window.ecwAvatar(p, 24) : el('span', 'teams-win-avatar', '?'));
      const label = el('span', 'teams-menu-label');
      label.append(el('span', '', p.name || p.email), el('span', 'muted', p.email));
      item.appendChild(label);
      item.addEventListener('click', () => { pop.remove(); chatWith(p.email, p.name); });
      pop.appendChild(item);
    });
    if (people.length > 1) {
      const group = el('a', 'teams-menu-item teams-menu-group');
      group.href = deepLink(people.map((p) => p.email), topic);
      group.target = '_blank'; group.rel = 'noopener';
      const i = el('i', 'fa fa-users'); i.setAttribute('aria-hidden', 'true');
      group.append(i, document.createTextNode(' Group chat in Teams (' + people.length + ')'));
      pop.appendChild(group);
    }
    if (!s.enabled) {
      const hint = el('a', 'teams-menu-hint', s.connected ? 'Enable Teams to chat here instead of the Teams app →' : 'Connect Outlook to use Teams →');
      hint.href = base + (s.connected ? 'teams.php' : 'settings/mailbox.php');
      pop.appendChild(hint);
    }
    document.body.appendChild(pop);
    const r = button.getBoundingClientRect();
    pop.style.top = Math.min(window.innerHeight - pop.offsetHeight - 8, r.bottom + 6) + 'px';
    pop.style.left = Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, r.left)) + 'px';
    const dismiss = (e) => {
      if (!pop.contains(e.target) && e.target !== button) { pop.remove(); document.removeEventListener('pointerdown', dismiss); }
    };
    setTimeout(() => document.addEventListener('pointerdown', dismiss), 0);
    pop.addEventListener('keydown', (e) => { if (e.key === 'Escape') { pop.remove(); button.focus(); } });
    pop.querySelector('button, a')?.focus();
  }

  // A picture in a chat (Teams, Telegram, WhatsApp; the page or a floating window) opens in the
  // app's preview (assets/filePreview.js), with the chat's other pictures beside it. Without the
  // preview on the page it opens as before, in a new tab.
  const CHAT_PICTURE = 'img[src*="action=teams_image"], img[src*="action=tg_media"], img[src*="action=wa_media"]';
  const pictureInfo = (img, i) => {
    const mime = img.dataset.mime && /^image\//.test(img.dataset.mime) ? img.dataset.mime : 'image/png';
    return { url: img.getAttribute('src'), name: img.dataset.name || ('Picture ' + (i + 1) + '.' + (mime.split('/')[1] || 'png').replace('jpeg', 'jpg')), mime, size: 0 };
  };
  document.addEventListener('click', (e) => {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || typeof window.ecwOpenPreview !== 'function') return;
    const img = e.target.closest && e.target.closest(CHAT_PICTURE);
    const list = img && img.closest('.teams-win-list, .teams-msgs, [id$="Messages"]');
    if (!img || !list) return;
    e.preventDefault();
    const all = [...list.querySelectorAll(CHAT_PICTURE)];
    const siblings = all.map(pictureInfo);
    const at = all.indexOf(img);
    window.ecwOpenPreview({ ...siblings[at], siblings });
  });

  window.ecwTeams = {
    openChat: (chat) => openChat(chat), chatWith, menu, status, api, renderMessages, composer, timeOf, deepLink, noAccessNote,
    cachedChats, fetchChats, cachedMessages, fetchMessages, notice,
  };

  function restore() {
    const saved = loadSaved();
    if (!saved.length) return;
    status().then((s) => {
      if (!s.enabled) { try { sessionStorage.removeItem(KEY); } catch (_) {} return; }
      saved.slice(-MAX_OPEN).forEach((state) => openChat(state, state));
    });
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) windows.forEach((w) => refresh(w)); });
  window.addEventListener('resize', layout);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restore); else restore();
})();
