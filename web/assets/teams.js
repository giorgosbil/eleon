/** The Teams tab: chat list + conversation. Reuses teams-dock.js helpers. */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const T = window.ecwTeams;
  if (!T || !$('teamsPage')) return;

  let chats = [];
  let active = null;
  let lastSig = '';

  function chatItem(c) {
    const li = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'teams-chat-item' + (c.unread ? ' unread' : '') + (active && active.id === c.id ? ' active' : '')
      + (c.limited || c.noAccess ? ' limited' : '');
    if (c.limited || c.noAccess) btn.title = 'Teams does not let this app read this chat. It opens in Teams.';
    const person = c.members.length === 1 ? c.members[0] : { name: c.title, email: '' };
    btn.appendChild(window.ecwAvatar ? window.ecwAvatar(person, 34) : document.createElement('span'));
    const text = document.createElement('span');
    text.className = 'teams-chat-text';
    const top = document.createElement('span');
    top.className = 'teams-chat-top';
    const name = document.createElement('span');
    name.className = 'teams-chat-name';
    name.textContent = c.title;
    const time = document.createElement('span');
    time.className = 'teams-chat-time';
    time.textContent = c.last_at ? T.timeOf(c.last_at) : '';
    top.append(name, time);
    const preview = document.createElement('span');
    preview.className = 'teams-chat-preview';
    preview.textContent = (c.preview_from && c.type !== 'oneOnOne' ? c.preview_from + ': ' : '') + c.preview;
    text.append(top, preview);
    btn.appendChild(text);
    btn.addEventListener('click', () => open(c));
    li.appendChild(btn);
    return li;
  }

  function renderList() {
    const q = $('chatFilter').value.trim().toLowerCase();
    const list = $('chatList');
    list.textContent = '';
    const shown = chats.filter((c) => !q || c.title.toLowerCase().includes(q)
      || c.members.some((m) => (m.email || '').toLowerCase().includes(q)));
    if (!shown.length) {
      const li = document.createElement('li');
      li.className = 'muted teams-empty';
      li.textContent = q ? 'No chats found. Press Enter to start a new chat with “' + q + '”.' : 'No chats yet. Press New chat to start one.';
      list.appendChild(li);
    }
    shown.forEach((c) => list.appendChild(chatItem(c)));
  }

  async function loadChats() {
    try {
      const known = new Map(chats.map((c) => [c.id, c]));
      chats = await T.fetchChats();   // also refreshes the browser cache
      chats.forEach((c) => { if (known.get(c.id)?.noAccess) c.noAccess = true; });   // remember across refreshes
      if (active) active = chats.find((c) => c.id === active.id) || active;
      renderList();
    } catch (err) {
      $('chatList').textContent = '';
      const li = document.createElement('li');
      li.className = 'teams-error';
      li.textContent = err.message;
      $('chatList').appendChild(li);
    }
  }

  async function loadMessages() {
    if (!active || active.noAccess || document.hidden) return;
    const id = active.id;
    try {
      const messages = await T.fetchMessages(id);   // also refreshes the cache
      if (!active || active.id !== id) return;
      const sig = messages.length + ':' + (messages.at(-1)?.id || '');
      if (sig !== lastSig) { lastSig = sig; T.renderMessages($('chatMessages'), messages); }
    } catch (err) {
      if (!active || active.id !== id) return;
      $('chatMessages').textContent = '';
      if (err.noAccess) {
        active.noAccess = true;          // permanent for this chat: stop polling
        $('chatComposer').hidden = true;
        $('chatPopOut').hidden = true;
        $('chatMessages').appendChild(T.noAccessNote(err.message, active.web_url));
      } else {
        $('chatMessages').textContent = err.message;
      }
    }
  }

  function open(c) {
    active = c;
    lastSig = '';
    c.unread = false;
    $('teamsPage').classList.add('chat-open');
    $('chatPlaceholder').hidden = true;
    $('chatHead').hidden = false;
    $('chatMessages').hidden = false;
    $('chatComposer').hidden = !!c.noAccess;
    $('chatPopOut').hidden = !!c.noAccess;
    $('chatTitle').textContent = c.title;
    $('chatOpenTeams').hidden = !c.web_url;
    $('chatOpenTeams').href = c.web_url || '#';
    $('chatMessages').dataset.first = '1';
    // Which chat the list shows (modules use it, e.g. to link messages to a case).
    Object.assign($('chatMessages').dataset, { source: 'teams', chat: c.id, title: c.title });
    $('chatMessages').dispatchEvent(new CustomEvent('ecw:chat-opened', { bubbles: true }));
    const cached = T.cachedMessages(c.id);
    if (cached) {
      T.renderMessages($('chatMessages'), cached);   // instant, then refreshed below
      lastSig = cached.length + ':' + (cached.at(-1)?.id || '');
    } else {
      $('chatMessages').textContent = 'Loading…';
    }
    renderList();
    loadMessages();
    $('chatComposer').querySelector('textarea').focus();
  }

  $('chatComposer').appendChild(T.composer(async (text) => {
    await T.api('teams_send', {}, { chat_id: active.id, text });
    lastSig = '';
    await loadMessages();
    loadChats();
  }));
  $('chatComposer').firstChild.style.borderTop = '1px solid var(--border)';

  $('chatPopOut').addEventListener('click', () => { if (active) T.openChat(active); });
  $('chatBack').addEventListener('click', () => $('teamsPage').classList.remove('chat-open'));
  $('chatFilter').addEventListener('input', renderList);
  // ------------------------------------------------------------- New chat
  // Pick people (colleagues and people you email), a group name for several,
  // a first message. Outside the Microsoft 365 organization Teams has to start
  // the chat: the dialog then shows an "Open in Teams" link with everything
  // filled in (a link the user clicks, so the browser does not block it).
  const dlg = $('newChatDialog');
  let people = null;
  let picked = [];
  let hi = -1;
  const isEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
  const loadPeople = () => people || (people = T.api('teams_people').then((r) => r.people).catch(() => []));

  function drawChips() {
    const box = $('ncChips');
    box.textContent = '';
    picked.forEach((p, i) => {
      const chip = document.createElement('span');
      chip.className = 'nc-chip' + (p.inside === false ? ' outside' : '');
      chip.title = p.email + (p.inside === false ? ' — outside your organization: opens in Teams' : '');
      chip.append(p.name || p.email);
      const x = document.createElement('button');
      x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', 'Remove ' + (p.name || p.email));
      x.addEventListener('click', () => { picked.splice(i, 1); drawChips(); $('ncTo').focus(); });
      chip.appendChild(x);
      box.appendChild(chip);
    });
    $('ncTopicRow').hidden = picked.length < 2;
    $('ncHint').textContent = picked.some((p) => p.inside === false)
      ? 'Someone here is outside your organization: Teams will open with the chat ready to send.'
      : (picked.length > 1 ? 'A group chat with ' + picked.length + ' people.' : 'One person: a one-to-one chat. Several: a group chat.');
    $('ncResult').hidden = true;
  }

  function add(p) {
    if (!p || !p.email || picked.some((x) => x.email === p.email.toLowerCase())) return;
    picked.push({ email: p.email.toLowerCase(), name: p.name || '', inside: p.inside });
    $('ncTo').value = '';
    $('ncSuggest').hidden = true;
    drawChips();
  }

  async function suggest() {
    const q = $('ncTo').value.trim().toLowerCase();
    const list = $('ncSuggest');
    const all = await loadPeople();
    const hits = all.filter((p) => !picked.some((x) => x.email === p.email)
      && (!q || p.email.includes(q) || (p.name || '').toLowerCase().includes(q))).slice(0, 8);
    if (q && isEmail(q) && !hits.some((p) => p.email === q)) hits.unshift({ email: q, name: '', from: 'typed' });
    list.textContent = '';
    hi = hits.length ? 0 : -1;
    hits.forEach((p, i) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.className = i === hi ? 'hi' : '';
      li.appendChild(window.ecwAvatar ? window.ecwAvatar(p, 26) : document.createElement('span'));
      const t = document.createElement('span');
      t.className = 'nc-who';
      const n = document.createElement('strong'); n.textContent = p.name && p.name !== p.email ? p.name : p.email;
      const m = document.createElement('span'); m.className = 'muted';
      m.textContent = (p.name && p.name !== p.email ? p.email + ' · ' : '')
        + (p.from === 'colleague' ? 'colleague' : p.from === 'emailed' ? 'you email them' : 'add this address')
        + (p.inside === false ? ' · outside your organization' : '');
      t.append(n, m);
      li.appendChild(t);
      li.addEventListener('mousedown', (e) => { e.preventDefault(); add(p); });
      list.appendChild(li);
    });
    list.hidden = !hits.length || document.activeElement !== $('ncTo');
    $('ncTo').setAttribute('aria-expanded', String(!list.hidden));
    list._hits = hits;
  }

  function openNew(prefill) {
    picked = [];
    $('ncTopic').value = ''; $('ncMessage').value = ''; $('ncTo').value = '';
    $('ncResult').hidden = true; $('ncStart').disabled = false; $('ncStart').textContent = 'Start chat';
    drawChips();
    dlg.showModal();
    loadPeople().then((all) => {
      const q = (prefill || '').trim();
      if (!q) return;
      const exact = all.find((p) => p.email === q.toLowerCase());
      if (exact || isEmail(q)) add(exact || { email: q });
      else { $('ncTo').value = q; suggest(); }
    });
    $('ncTo').focus();
  }

  $('ncTo').addEventListener('input', suggest);
  $('ncTo').addEventListener('focus', suggest);
  $('ncTo').addEventListener('blur', () => { setTimeout(() => { $('ncSuggest').hidden = true; }, 100); });
  $('ncTo').addEventListener('keydown', (e) => {
    const hits = $('ncSuggest')._hits || [];
    const items = $('ncSuggest').children;
    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && hits.length) {
      e.preventDefault();
      hi = (hi + (e.key === 'ArrowDown' ? 1 : hits.length - 1)) % hits.length;
      [...items].forEach((li, i) => li.classList.toggle('hi', i === hi));
    } else if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
      const v = $('ncTo').value.trim().replace(/[,;]$/, '');
      if (!v && e.key !== 'Enter') return;
      if (!v) return;   // Enter on an empty field submits the form
      e.preventDefault();
      if (!$('ncSuggest').hidden && hits[hi]) add(hits[hi]);
      else if (isEmail(v)) add({ email: v });
      else T.notice('Pick someone from the list, or type a full email address.');
    } else if (e.key === 'Backspace' && !$('ncTo').value && picked.length) {
      picked.pop(); drawChips();
    }
  });
  $('ncToBox').addEventListener('click', () => $('ncTo').focus());
  $('ncCancel').addEventListener('click', () => dlg.close());

  $('newChatDialogForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const typed = $('ncTo').value.trim();
    if (typed && isEmail(typed)) add({ email: typed });
    if (!picked.length) { $('ncTo').focus(); T.notice('Add at least one person.'); return; }
    const btn = $('ncStart');
    btn.disabled = true; btn.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> Starting…';
    const result = $('ncResult');
    result.hidden = true;
    try {
      const { chat } = await T.api('teams_new_chat', {}, { emails: picked.map((p) => p.email), topic: $('ncTopic').value, message: $('ncMessage').value });
      dlg.close();
      await loadChats();
      open(chats.find((c) => c.id === chat.id) || { id: chat.id, title: chat.title || picked.map((p) => p.name || p.email).join(', '), members: [], web_url: chat.web_url || '' });
    } catch (err) {
      result.textContent = '';
      result.className = 'nc-result' + (err.openInTeams ? '' : ' error');
      result.append(err.message);
      if (err.openInTeams) {
        const a = document.createElement('a');
        a.className = 'button primary'; a.href = err.openInTeams; a.target = '_blank'; a.rel = 'noopener';
        a.innerHTML = '<i class="ecw-teams-icon" aria-hidden="true"></i> Open in Teams';
        a.addEventListener('click', () => setTimeout(() => dlg.close(), 300));
        result.append(a);
      }
      result.hidden = false;
    } finally {
      btn.disabled = false; btn.textContent = 'Start chat';
    }
  });

  $('newChatBtn').addEventListener('click', () => openNew(''));
  // Enter in the search box with no matching chat: start one with what was typed.
  $('newChatForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const q = $('chatFilter').value.trim();
    const ql = q.toLowerCase();
    const match = q && chats.find((c) => c.title.toLowerCase().includes(ql) || c.members.some((m) => (m.email || '').toLowerCase().includes(ql)));
    if (match) { open(match); return; }
    openNew(q);
    $('chatFilter').value = ''; renderList();
  });
  if (new URLSearchParams(location.search).has('new')) openNew(new URLSearchParams(location.search).get('new') || '');

  // Draw the cached list immediately; the network refresh replaces it quietly.
  const cachedList = T.cachedChats();
  if (cachedList) { chats = cachedList.chats; renderList(); }
  // teams.php?chat=<id> (e.g. from a case's Chats tab) opens that chat.
  const wantChat = new URLSearchParams(location.search).get('chat');
  Promise.resolve(loadChats()).then(() => {
    const c = wantChat && chats.find((x) => x.id === wantChat);
    if (c && (!active || active.id !== c.id)) open(c);
  });
  setInterval(loadMessages, 5000);
  setInterval(() => { if (!document.hidden) loadChats(); }, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { loadMessages(); loadChats(); } });
})();
