/**
 * The Teams launcher: a small icon fixed at the bottom-right of every page.
 * Hover (or focus) slides it out to show "Teams"; click opens a compact chat
 * list, drawn instantly from the browser cache and refreshed in the
 * background. Choosing a chat opens it in a floating window (teams-dock.js).
 *
 * Shown only to people with the Teams feature and a Microsoft connection.
 * Not shown on the Teams page itself, which has the full list.
 * Everything is written with textContent.
 */
(function () {
  'use strict';
  const T = window.ecwTeams;
  if (!T || document.body.classList.contains('teams-body')) return;
  const base = window.ecwWebBase || '/';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', 'fa ' + cls); i.setAttribute('aria-hidden', 'true'); return i; };
  const STALE_MS = 60000;

  T.status().then((s) => {
    if (!s.connected) return;   // no Microsoft mailbox: nothing to launch
    build(s);
  });

  function build(s) {
    const root = el('div', 'teams-launcher');
    const btn = el('button', 'teams-launcher-btn');
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-expanded', 'false');
    btn.title = 'Microsoft Teams';
    const badge = el('span', 'teams-launcher-badge');
    badge.hidden = true;
    const iconWrap = el('span', 'teams-launcher-icon');
    iconWrap.append(icon('ecw-teams-icon'), badge);
    btn.append(iconWrap, el('span', 'teams-launcher-label', 'Teams'));

    const panel = el('section', 'teams-launcher-panel');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Teams chats');
    const head = el('header', 'teams-launcher-head');
    const title = el('strong', '', 'Teams');
    const full = el('a', 'teams-win-btn');
    full.href = base + 'teams.php'; full.title = 'Open the Teams page'; full.setAttribute('aria-label', full.title);
    full.appendChild(icon('fa-up-right-and-down-left-from-center'));
    const close = el('button', 'teams-win-btn');
    close.type = 'button'; close.title = 'Close'; close.setAttribute('aria-label', 'Close');
    close.appendChild(icon('fa-xmark'));
    head.append(icon('ecw-teams-icon'), title, full, close);

    const body = el('div', 'teams-launcher-body');
    panel.append(head, body);
    root.append(panel, btn);
    document.body.appendChild(root);

    if (!s.enabled) {
      body.appendChild(el('p', 'muted small', 'Teams is not enabled for your account yet.'));
      const a = el('a', 'button primary', 'Enable Teams');
      a.href = base + 'teams.php';
      body.appendChild(a);
    } else {
      buildList(body, badge);
    }

    const setOpen = (open) => {
      panel.hidden = !open;
      root.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', String(open));
      if (open) { root.dispatchEvent(new Event('teams-launcher-open')); panel.querySelector('input')?.focus(); }
    };
    btn.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => { setOpen(false); btn.focus(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { setOpen(false); btn.focus(); } });
    document.addEventListener('pointerdown', (e) => { if (!panel.hidden && !root.contains(e.target)) setOpen(false); });
    T._launcherOpen = setOpen;
  }

  function buildList(body, badge) {
    const search = el('input', 'teams-launcher-search');
    search.type = 'search';
    search.placeholder = 'Search chats or type an email to start one';
    search.setAttribute('aria-label', 'Search chats or start a chat by email');
    const list = el('ul', 'teams-launcher-list');
    const foot = el('p', 'muted small teams-launcher-foot');
    body.append(search, list, foot);
    let chats = T.cachedChats()?.chats || [];

    function draw() {
      const q = search.value.trim().toLowerCase();
      list.textContent = '';
      const shown = chats.filter((c) => !c.limited && (!q || c.title.toLowerCase().includes(q)
        || c.members.some((m) => (m.email || '').toLowerCase().includes(q))));
      if (q.includes('@') && !shown.some((c) => c.members.some((m) => (m.email || '').toLowerCase() === q))) {
        const li = el('li');
        const b = el('button', 'teams-launcher-item');
        b.type = 'button';
        b.append(icon('fa-pen-to-square'), el('span', '', 'Start a chat with ' + q));
        b.addEventListener('click', () => { T.chatWith(q, ''); T._launcherOpen?.(false); });
        li.appendChild(b);
        list.appendChild(li);
      }
      if (!shown.length && !q.includes('@')) list.appendChild(el('li', 'muted small teams-launcher-empty', chats.length ? 'No match.' : 'Loading chats…'));
      shown.slice(0, 40).forEach((c) => {
        const li = el('li');
        const b = el('button', 'teams-launcher-item' + (c.unread ? ' unread' : ''));
        b.type = 'button';
        const person = c.members.length === 1 ? c.members[0] : { name: c.title, email: '' };
        b.appendChild(window.ecwAvatar ? window.ecwAvatar(person, 30) : el('span'));
        const text = el('span', 'teams-launcher-text');
        const top = el('span', 'teams-launcher-top');
        top.append(el('span', 'teams-launcher-name', c.title), el('span', 'teams-launcher-time', c.last_at ? T.timeOf(c.last_at) : ''));
        text.append(top, el('span', 'teams-launcher-preview', c.preview || ''));
        b.appendChild(text);
        b.addEventListener('click', () => { c.unread = false; T.openChat(c); T._launcherOpen?.(false); updateBadge(); });
        li.appendChild(b);
        list.appendChild(li);
      });
      const at = T.cachedChats()?.at;
      foot.textContent = at ? 'Updated ' + new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
    }
    function updateBadge() {
      const n = chats.filter((c) => c.unread && !c.limited).length;
      badge.hidden = n === 0;
      badge.textContent = n > 9 ? '9+' : String(n);
      document.dispatchEvent(new CustomEvent('ecw:teams-unread', { detail: n }));   // nav-badges.js: dot on the Teams tab
    }
    async function refresh(force) {
      const cached = T.cachedChats();
      if (!force && cached && Date.now() - cached.at < STALE_MS) return;
      try { chats = await T.fetchChats(); draw(); updateBadge(); } catch (_) { /* keep the cached list */ }
    }

    search.addEventListener('input', draw);
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') list.querySelector('button')?.click(); });
    // The Teams page or another tab refreshed the cache: follow it.
    document.addEventListener('ecw:teams-chats', (e) => { chats = e.detail; draw(); updateBadge(); });
    window.addEventListener('storage', (e) => { if (e.key && e.key.startsWith('ecw.teams.chats.')) { chats = T.cachedChats()?.chats || chats; draw(); updateBadge(); } });
    document.querySelector('.teams-launcher').addEventListener('teams-launcher-open', () => refresh(false));
    draw();
    updateBadge();
    refresh(false);
    // Keep the unread badge current, lightly: every 2 minutes while the tab is visible.
    setInterval(() => { if (!document.hidden) refresh(true); }, 120000);
  }
})();
