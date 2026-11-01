/**
 * Mail page extras, kept out of app.js: snooze and reminders on a thread,
 * "someone is replying" presence, reply templates, and case suggestions in
 * the Add-to-Case dialog. Talks to app.js only through window.ecwMail and the
 * ecw:* events it dispatches. Everything is written with textContent except
 * template bodies, which are sanitized on save (lib/templates.php) and pasted
 * through Quill's own clipboard.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const post = (action, params, body) => M.api(action, params, { method: 'POST', body });
  const local = (utc) => new Date(String(utc).replace(' ', 'T') + 'Z').toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

  // ------------------------------------------------- snooze and reminders

  const laterBtn = $('threadLaterBtn');
  const laterMenu = $('threadLaterMenu');
  function at(kind) {
    const d = new Date();
    if (kind === 'later') d.setHours(d.getHours() + 3, 0, 0, 0);
    if (kind === 'tomorrow') { d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); }
    if (kind === 'week') { d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(9, 0, 0, 0); }
    return d;
  }
  async function loadReminders() {
    const list = $('threadReminders');
    list.textContent = '';
    if (!M.state.threadId) return;
    try {
      const { followups } = await M.api('thread_followups', { thread_id: M.state.threadId });
      followups.forEach((f) => {
        const li = el('li', 'followup');
        const t = el('span', 'followup-text');
        t.append(el('strong', '', local(f.due_at)), el('span', '', (f.note || 'Reminder') + (f.if_no_reply ? ' · if no reply' : '')));
        const x = el('button', 'link-btn', 'Cancel');
        x.type = 'button';
        x.addEventListener('click', async () => { if (window.ecwArm && !window.ecwArm(x, 'Click again to cancel')) return; await post('followup_cancel', {}, { id: f.id }); loadReminders(); });
        li.append(t, x);
        list.appendChild(li);
      });
    } catch (_) {}
  }
  laterBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    laterMenu.hidden = !laterMenu.hidden;
    laterBtn.setAttribute('aria-expanded', String(!laterMenu.hidden));
    if (!laterMenu.hidden) {
      $('unsnoozeBtn').hidden = !M.state.snoozed;
      const d = at('tomorrow');
      const pad = (n) => String(n).padStart(2, '0');
      $('remindDue').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T09:00';
      loadReminders();
    }
  });
  document.addEventListener('click', (e) => { if (laterMenu && !laterMenu.hidden && !laterMenu.contains(e.target) && e.target !== laterBtn) laterMenu.hidden = true; });
  laterMenu?.querySelectorAll('[data-snooze]').forEach((b) => b.addEventListener('click', async () => {
    const kind = b.dataset.snooze;
    try {
      const r = await post('thread_snooze', {}, { thread_id: M.state.threadId, until: kind === 'unsnooze' ? '' : at(kind).toISOString() });
      laterMenu.hidden = true;
      M.toast(r.snoozed_until ? 'Snoozed until ' + local(r.snoozed_until) + '. It comes back sooner if someone replies.' : 'Back in your inbox');
      document.body.classList.remove('reading-open');
      await (M.refreshList || M.resetList)();
    } catch (err) { M.toast(err.message, true); }
  }));
  $('remindSave')?.addEventListener('click', async () => {
    const due = new Date($('remindDue').value);
    if (Number.isNaN(due.getTime())) { M.toast('Choose when to be reminded', true); return; }
    try {
      await post('followup_add', {}, { thread_id: M.state.threadId, due: due.toISOString(), note: $('remindNote').value, if_no_reply: $('remindNoReply').checked });
      $('remindNote').value = '';
      M.toast('Reminder set for ' + due.toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' }));
      loadReminders();
    } catch (err) { M.toast(err.message, true); }
  });

  // -------------------------------------------------------------- presence

  let beatTimer = null;
  let beatThread = null;
  let beatMailbox = null;
  function beat(active) {
    if (!beatThread || !beatMailbox) return;
    M.api('presence', { mailbox_id: beatMailbox }, { method: 'POST', body: { thread_id: beatThread, active } })
      .then((d) => { if (active) composerPresence(d.others || []); })
      .catch(() => {});
  }
  /** Inside the composer: "Ann is also replying to this email right now." */
  function composerPresence(names) {
    const box = $('composerPresence');
    if (!box || box.classList.contains('warn')) return;   // a Send warning is showing (app.js)
    box.hidden = !names.length;
    box.textContent = '';
    if (!names.length) return;
    const i = el('i', 'fa fa-pen-to-square');
    i.setAttribute('aria-hidden', 'true');
    box.append(i, el('span', '', names.join(' and ') + (names.length === 1 ? ' is' : ' are') + ' also replying to this email right now. Talk first, or share your draft to write it together.'));
  }
  document.addEventListener('ecw:composer-open', (e) => {
    clearInterval(beatTimer);
    beatThread = e.detail.threadId;
    beatMailbox = e.detail.mailboxId;
    composeContext = e.detail;
    composerPresence([]);
    if (!beatThread) return;
    beat(true);
    beatTimer = setInterval(() => beat(true), 10000);
  });
  document.addEventListener('ecw:composer-close', () => { clearInterval(beatTimer); beat(false); beatThread = null; });

  // ------------------------------------------------ shared drafts in the reading pane
  // "George's draft reply — shared": open it to read along or edit (app.js handles the lock).
  function drawSharedDrafts(thread) {
    const box = $('sharedDrafts');
    if (!box) return;
    // Drafts now show inside the conversation (app.js threadDraftCard); this banner stays for older answers.
    const list = thread?.drafts ? [] : (thread?.shared_drafts || []);
    box.hidden = !list.length;
    box.textContent = '';
    list.forEach((d) => {
      const card = el('div', 'shared-draft');
      const i = el('i', 'fa fa-user-pen');
      i.setAttribute('aria-hidden', 'true');
      const text = el('span', 'shared-draft-text');
      text.append(el('strong', '', d.mine ? 'Your shared draft reply' : d.author + '’s draft reply'),
        el('span', 'muted', ' · edited ' + local(d.updated_at) + (d.editing ? ' · ' + d.editing + ' is editing now' : '')));
      const open = el('button', 'shared-draft-open', d.editing ? 'Read along' : (d.mine ? 'Open' : 'Open and edit'));
      open.type = 'button';
      open.addEventListener('click', () => M.openDraft(d.id));
      card.append(i, text, open);
      box.appendChild(card);
    });
  }
  document.addEventListener('ecw:thread-open', (e) => drawSharedDrafts(e.detail));
  window.addEventListener('pagehide', () => { if (beatThread) beat(false); });
  document.addEventListener('ecw:updates', (e) => {
    const names = e.detail.replying || [];
    const banner = $('presenceBanner');
    if (!banner) return;
    banner.hidden = !names.length;
    $('presenceText').textContent = names.length
      ? names.join(' and ') + (names.length === 1 ? ' is' : ' are') + ' writing a reply to this email right now.'
      : '';
  });
  document.addEventListener('ecw:thread-open', () => { const b = $('presenceBanner'); if (b) b.hidden = true; });

  // ------------------------------------------------------------- templates

  let composeContext = {};
  let templates = null;
  // The first To address, and the name to greet them by. Mail often carries
  // the address itself as the display name, so names with an @ are skipped
  // and the server (contact card, then the most used real name) decides.
  const nameCache = new Map();
  async function recipient() {
    const first = ($('fieldTo').value.split(/[,;]/)[0] || '').trim();
    const email = ((first.match(/<([^>]+)>/) || [])[1] || first).trim().toLowerCase();
    if (!email) return { email: '', name: '', first: '' };
    if (!nameCache.has(email)) {
      const typed = first.includes('<') ? first.replace(/<[^>]*>/, '').replace(/["']/g, '').trim() : '';
      const seen = (M.state.thread?.messages || []).flatMap((m) => [m.from, ...(m.to || []), ...(m.cc || [])])
        .find((p) => p && p.email && p.email.toLowerCase() === email && p.name && !p.name.includes('@'));
      let r = { name: '', first_name: '' };
      try { r = await M.api('recipient_name', { email }); } catch (_) {}
      const name = r.name || typed || seen?.name?.trim() || '';
      const firstName = r.first_name || (name && !name.includes('@') ? name.replace(/^[^,]+,\s*/, '').split(/\s+/)[0] : '');
      nameCache.set(email, { email, name, first: firstName });
    }
    return nameCache.get(email);
  }
  async function fill(html) {
    const me = document.querySelector('.appnav-me')?.dataset.name || '';
    const who = /\{\{(first_name|name)\}\}/.test(html) ? await recipient() : { email: '', name: '', first: '' };
    if (/\{\{first_name\}\}/.test(html) && who.email && !who.first) {
      M.toast?.('No first name is known for ' + who.email + ' — check the greeting.', true);
    }
    const map = {
      '{{first_name}}': who.first, '{{name}}': who.name || who.email, '{{my_name}}': me,
      '{{case_number}}': composeContext.caseNumber || '', '{{case_name}}': composeContext.caseName || '',
      '{{date}}': new Date().toLocaleDateString(),
    };
    // Values are escaped: a name like <b>Bob</b> must stay text.
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    return Object.entries(map).reduce((h, [k, v]) => h.split(k).join(esc(v)), html);
  }
  async function openTemplates(btn) {
    document.querySelector('.template-menu')?.remove();
    const menu = el('div', 'template-menu');
    menu.setAttribute('role', 'menu');
    const search = el('input');
    search.type = 'search'; search.placeholder = 'Find a template';
    const list = el('div');
    menu.append(search, list);
    document.body.appendChild(menu);
    const r = btn.getBoundingClientRect();
    menu.style.position = 'fixed';   // the composer is a fixed window
    menu.style.top = Math.min(window.innerHeight - 200, r.bottom + 4) + 'px';
    menu.style.left = Math.max(8, Math.min(window.innerWidth - 310, r.left)) + 'px';
    try { templates = templates || (await M.api('templates', {})).templates; } catch (e) { list.textContent = e.message; return; }
    const draw = () => {
      list.textContent = '';
      const q = search.value.toLowerCase();
      const shown = templates.filter((t) => t.name.toLowerCase().includes(q));
      if (!shown.length) {
        list.appendChild(el('p', 'muted small', templates.length ? 'No match.' : 'No templates yet.'));
      }
      shown.forEach((t) => {
        const b = el('button');
        b.type = 'button';
        b.append(el('span', '', t.name), el('span', 'muted', t.shared ? 'Shared' : 'Personal'));
        b.addEventListener('click', async () => {
          const quill = M.editor();
          const range = quill.getSelection(true) || { index: quill.getLength() };
          menu.remove();
          quill.clipboard.dangerouslyPasteHTML(range.index, await fill(t.body_html), 'user');
          if (t.subject && !$('fieldSubject').value.trim()) $('fieldSubject').value = (await fill(t.subject)).replace(/&[a-z#0-9]+;/gi, (m) => { const d = document.createElement('textarea'); d.innerHTML = m; return d.value; });
          menu.remove();
        });
        list.appendChild(b);
      });
      const manage = el('a', 'small', 'Manage templates →');
      manage.href = 'settings/templates.php';
      manage.style.display = 'block'; manage.style.padding = '6px 8px';
      list.appendChild(manage);
    };
    search.addEventListener('input', draw);
    draw();
    search.focus();
    const dismiss = (e) => { if (!menu.contains(e.target) && e.target !== btn) { menu.remove(); document.removeEventListener('pointerdown', dismiss); } };
    setTimeout(() => document.addEventListener('pointerdown', dismiss), 0);
    menu.addEventListener('keydown', (e) => { if (e.key === 'Escape') { menu.remove(); btn.focus(); } });
  }
  $('templateBtn')?.addEventListener('click', (e) => openTemplates(e.currentTarget));

  // ------------------------------------- notes, like comments in Word
  // Each email has a 💬 at its bottom right (app.js draws it). Clicking it
  // opens the notes panel in the right-hand pane, on that email's note
  // conversations; "All notes" lists every conversation of this thread.

  let orgUsers = [];
  M.api('org_users', {}).then((d) => { orgUsers = d.users || []; }).catch(() => {});
  const me = Number(document.body.dataset.user);
  const notesPane = $('notesPane');
  const notesBtn = $('threadNotesBtn');
  let noteGroups = [];
  let notesThreadId = null;
  let wantNote = Number(new URLSearchParams(location.search).get('note') || 0);   // from a notification link
  const narrow = () => window.matchMedia('(max-width: 980px)').matches;

  function paintNotes() {
    window.ecwPaintNoteBubbles?.($('messageList'), noteGroups);
    const open = noteGroups.filter((g) => !g.resolved).length;
    const count = $('threadNotesCount');
    if (count) count.textContent = open ? String(open) : '';
    notesBtn?.classList.toggle('has-notes', open > 0);
    notesBtn?.classList.toggle('unread', noteGroups.some((g) => g.unread));
  }
  const notesOpen = () => notesPane && !notesPane.hidden;
  function openNotes() {
    if (!notesPane) return;
    notesPane.hidden = false;
    $('detailsBody').hidden = true;
    $('detailsPane').classList.add('notes-mode');
    $('detailsPane').hidden = false;
    window.ecwDetailsPaneResizer?.setVisible?.(true);
    window.ecwDetailsPaneResizer?.expand?.();
    if (narrow()) { document.body.classList.add('details-open'); }
    notesBtn?.setAttribute('aria-expanded', 'true');
    notesBtn?.classList.add('active');
  }
  function closeNotes() {
    if (!notesPane) return;
    notesPane.hidden = true;
    $('detailsBody').hidden = false;
    $('detailsPane').classList.remove('notes-mode');
    if (narrow()) document.body.classList.remove('details-open');
    notesBtn?.setAttribute('aria-expanded', 'false');
    notesBtn?.classList.remove('active');
  }
  const ui = notesPane && window.ecwNotesUI ? window.ecwNotesUI(notesPane, {
    api: {
      add: (body, o) => post('thread_note_add', {}, Object.assign({ thread_id: M.state.threadId, body }, o)),
      remove: (id) => post('thread_note_delete', {}, { thread_id: M.state.threadId, note_id: id }),
      resolve: (id, on) => post('thread_note_resolve', {}, { thread_id: M.state.threadId, note_id: id, resolved: on }),
      seen: (id) => post('thread_note_seen', {}, { thread_id: M.state.threadId, note_id: id }),
    },
    users: () => orgUsers, me,
    generalLabel: 'this conversation',
    jumpTo: (id) => {
      const a = document.querySelector('#messageList [data-message-id="' + id + '"]');
      if (!a) return;
      const head = a.querySelector('.message-head');
      if (head && !head.open) head.open = true;
      a.scrollIntoView({ block: 'center', behavior: 'smooth' });
      a.classList.add('note-target');
      setTimeout(() => a.classList.remove('note-target'), 1600);
    },
    onGroups: (g) => { noteGroups = g; if (M.state.thread) M.state.thread.note_groups = g; paintNotes(); },
    onClose: closeNotes,
  }) : null;

  document.addEventListener('ecw:notes-open', (e) => {
    if (!ui) return;
    openNotes();
    ui.showMessage(e.detail.message_id, { from: e.detail.from, at: e.detail.at });
  });
  notesBtn?.addEventListener('click', () => {
    if (!ui) return;
    if (notesOpen() && ui.view().kind === 'all') { closeNotes(); return; }
    openNotes();
    ui.showAll();
  });
  // The Details button brings Details back (the same pane shows one or the other).
  $('detailsToggleBtn')?.addEventListener('click', () => { if (notesOpen()) closeNotes(); }, true);
  $('detailsCloseBtn')?.addEventListener('click', () => { if (notesOpen()) closeNotes(); });

  document.addEventListener('ecw:thread-open', (e) => {
    if (!ui) return;
    const thread = e.detail || M.state.thread;
    const changed = notesThreadId !== thread?.id;
    notesThreadId = thread?.id ?? null;
    ui.setGroups(thread?.note_groups || []);
    if (changed) ui.showAll();
    if (wantNote) { openNotes(); ui.showGroup(wantNote); wantNote = 0; }
  });
  // Bodies load after the first draw and replace the email element: keep the 💬 counts on.
  const messageList = $('messageList');
  if (messageList) new MutationObserver(paintNotes).observe(messageList, { childList: true });
  // ------------------------------------- recipient suggestions (To/Cc/Bcc)
  // As you type, the address after the last comma is looked up (colleagues and
  // people you have emailed); arrows/Enter/Tab pick, Esc closes.

  const suggestBox = el('ul', 'address-suggest');
  suggestBox.setAttribute('role', 'listbox');
  suggestBox.hidden = true;
  document.body.appendChild(suggestBox);
  let suggestFor = null;
  let suggestIndex = 0;
  let suggestTimer = null;
  let suggestSeq = 0;

  // With recipient chips (recipient-chips.js) only the address being typed is in the box itself.
  const typed = (input) => (input._ecwRaw ? input._ecwRaw.get() : input.value);
  const currentToken = (input) => {
    const upto = typed(input).slice(0, input.selectionStart);
    const start = Math.max(upto.lastIndexOf(','), upto.lastIndexOf(';')) + 1;
    return { start, text: upto.slice(start).trim() };
  };
  const closeSuggest = () => { suggestBox.hidden = true; suggestBox.textContent = ''; suggestFor = null; };
  function pickSuggestion(input, s) {
    if (input._ecwChips) {   // the picked person becomes a chip; the typed part goes
      input._ecwRaw.set('');
      input._ecwChips.add(s.email);
      closeSuggest();
      input.focus();
      return;
    }
    const { start } = currentToken(input);
    const rest = input.value.slice(input.selectionStart).replace(/^[^,;]*/, '');
    const before = input.value.slice(0, start).replace(/\s*$/, '');
    const value = (before ? before + ' ' : '') + s.email + ', ';
    input.value = value + rest.replace(/^[,;]\s*/, '');
    input.setSelectionRange(value.length, value.length);
    input.dispatchEvent(new Event('input', { bubbles: true }));   // autosave
    closeSuggest();
    input.focus();
  }
  function drawSuggestions(input, list) {
    suggestBox.textContent = '';
    if (!list.length) { closeSuggest(); return; }
    suggestFor = input;
    suggestIndex = 0;
    list.forEach((s, i) => {
      const li = el('li', i === 0 ? 'active' : '');
      li.setAttribute('role', 'option');
      if (window.ecwAvatar) li.appendChild(window.ecwAvatar({ name: s.name || '', email: s.email }, 26));
      const text = el('span', 'address-suggest-text');
      text.append(el('span', 'address-suggest-name', s.name || s.email), el('span', 'muted', s.email));
      li.appendChild(text);
      if (s.colleague) li.appendChild(el('span', 'address-suggest-tag', 'Colleague'));
      li.addEventListener('mousedown', (e) => { e.preventDefault(); pickSuggestion(input, s); });
      li._s = s;
      suggestBox.appendChild(li);
    });
    const r = input.getBoundingClientRect();
    suggestBox.style.left = r.left + 'px';
    suggestBox.style.top = (r.bottom + 4) + 'px';
    suggestBox.style.width = Math.max(300, r.width) + 'px';
    suggestBox.hidden = false;
  }
  ['fieldTo', 'fieldCc', 'fieldBcc'].forEach((id) => {
    const input = $(id);
    if (!input) return;
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', (e) => {
      if (e.isTrusted === false && !e.inputType) return;   // our own change after a pick
      const { text } = currentToken(input);
      clearTimeout(suggestTimer);
      if (text.length < 1 || text.includes('@') && /\.[a-z]{2,}$/i.test(text)) { closeSuggest(); return; }
      const seq = ++suggestSeq;
      suggestTimer = setTimeout(async () => {
        try {
          const { suggestions } = await M.api('address_suggest', { q: text });
          if (seq === suggestSeq && document.activeElement === input) drawSuggestions(input, suggestions);
        } catch (_) { closeSuggest(); }
      }, 120);
    });
    input.addEventListener('keydown', (e) => {
      if (suggestBox.hidden || suggestFor !== input) return;
      const items = [...suggestBox.children];
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        items[suggestIndex]?.classList.remove('active');
        suggestIndex = (suggestIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items[suggestIndex].classList.add('active');
        items[suggestIndex].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        pickSuggestion(input, items[suggestIndex]._s);
      } else if (e.key === 'Escape') {
        e.stopPropagation();
        closeSuggest();
      }
    });
    input.addEventListener('blur', () => setTimeout(() => { if (suggestFor === input) closeSuggest(); }, 150));
  });
  window.addEventListener('resize', closeSuggest);
  document.addEventListener('ecw:composer-close', closeSuggest);

  // ------------------------------------------------------ case suggestions

  $('addToCaseBtn')?.addEventListener('click', async () => {
    const box = $('caseSuggest');
    const list = $('caseSuggestList');
    box.hidden = true;
    list.textContent = '';
    if (!M.state.threadId) return;
    const forThread = M.state.threadId;
    try {
      // The AI's confident pick only (lib/case_match.php): nothing when it is unsure or off.
      const { cases } = await M.api('case_suggest', { thread_id: forThread });
      if (M.state.threadId !== forThread || $('caseOverlay')?.hidden) return;   // closed or moved on meanwhile
      cases.forEach((c) => {
        const b = el('button');
        b.type = 'button';
        b.append(el('span', '', c.case_number + ' · ' + c.name), el('span', 'muted', c.why || ''));
        b.title = 'Add this conversation to ' + c.case_number;
        b.addEventListener('click', () => M.linkCase(c.id, ''));
        list.appendChild(b);
      });
      box.hidden = !cases.length;
    } catch (_) {}
  });

  // ------------------------------------------- find inside the open conversation
  // The 🔍 on the conversation, or Ctrl+F while one is open. A list search
  // ("invoice march") highlights its words in the conversation you open from it.
  const find = window.ecwFindInMail && $('threadFindHost') ? window.ecwFindInMail({
    root: () => $('messageList'),
    scope: '.message-body, .message-quoted:not([hidden]), .signature-content, .message-from, .message-attachments .attachment',
    host: $('threadFindHost'),
    expand: (hit) => {
      const sig = hit.closest('details.message-signature');
      if (sig && !sig.open) sig.open = true;
    },
  }) : null;
  const searchWords = (q) => String(q || '').replace(/\b[a-z]+:("[^"]*"|\S+)/gi, ' ').replace(/["()]/g, ' ').trim();
  if (find) {
    $('threadFindBtn')?.addEventListener('click', () => find.jump());   // open, or back to the highlighted match
    document.addEventListener('keydown', (e) => {
      if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'f' || e.shiftKey || e.altKey) return;
      if (!M.state.threadId || !$('composerOverlay')?.hidden) return;
      if (e.target.closest?.('textarea, [contenteditable="true"], input:not(.find-bar input)')) return;
      e.preventDefault();
      find.jump();
    });
    document.addEventListener('ecw:thread-open', () => {
      const words = searchWords(M.state.query);
      if (words) find.open(words, { words: true, quiet: true });
      else if (find.isOpen()) find.refresh();
    });
  }
})();
