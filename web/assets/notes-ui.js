/**
 * The notes panel, like comments in Word. Shared by the mail page (in the
 * right-hand pane) and the case page (the side panel's Notes tab).
 *
 * A "conversation" is a first note plus its replies, about one email (or
 * "General": about the whole conversation / case). An email can have several.
 * Views:
 *   all      every conversation, newest activity first, Open / Resolved
 *   message  the conversations on one email, expanded, + start a new one
 *   general  the conversations not about one email
 *
 *   const ui = window.ecwNotesUI(container, {
 *     api: { add(body, {message_id, parent_id}), remove(id), resolve(id, on), seen(id) },  // each resolves to { groups, ... }
 *     users: () => [...], me: id,
 *     jumpTo(messageId), onGroups(groups), onClose(),
 *     generalLabel: 'this conversation',
 *   });
 *   ui.setGroups(groups); ui.showAll(); ui.showMessage(id, {from, at}); ui.showGeneral(); ui.showGroup(id)
 *
 * XSS: note HTML comes escaped from lib/notes.php (ecw_note_render) and is the
 * only innerHTML here; everything else is textContent.
 */
(function () {
  'use strict';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (name) => { const i = el('i', 'fa ' + name); i.setAttribute('aria-hidden', 'true'); return i; };
  const local = (utc) => { const d = new Date(String(utc || '').replace(' ', 'T').replace(/Z?$/, 'Z')); return Number.isNaN(d.getTime()) ? '' : d.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  const ago = (utc) => {
    const d = new Date(String(utc || '').replace(' ', 'T').replace(/Z?$/, 'Z'));
    const s = (Date.now() - d.getTime()) / 1000;
    if (Number.isNaN(s)) return '';
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    if (s < 7 * 86400) return Math.floor(s / 86400) + ' d ago';
    return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
  };
  const plain = (html) => { const d = document.createElement('div'); d.innerHTML = html; return (d.textContent || '').replace(/\s+/g, ' ').trim(); };

  window.ecwNotesUI = function (root, opts) {
    let groups = [];
    let view = { kind: 'all' };
    let filter = 'open';
    let focusId = null;
    const seenAsked = new Set();
    const aboutCache = new Map();   // message id -> {from, at}, for an email with no notes yet

    root.classList.add('notes-ui');
    const head = el('div', 'np-head');
    const back = el('button', 'np-back');
    back.type = 'button';
    back.append(icon('fa-arrow-left'), el('span', '', 'All notes'));
    back.addEventListener('click', () => showAll());
    const title = el('strong', 'np-title', 'Notes');
    const close = el('button', 'np-close');
    close.type = 'button'; close.title = 'Close notes'; close.setAttribute('aria-label', 'Close notes');
    close.appendChild(icon('fa-xmark'));
    close.addEventListener('click', () => opts.onClose?.());
    head.append(back, title, close);
    const body = el('div', 'np-body');
    const status = el('p', 'np-status muted');
    status.setAttribute('role', 'status');
    root.replaceChildren(head, body, status);

    const say = (text) => { status.textContent = text || ''; if (text) setTimeout(() => { if (status.textContent === text) status.textContent = ''; }, 5000); };
    const groupsFor = (messageId) => groups.filter((g) => (messageId === null ? g.message_id === null : g.message_id === messageId));
    const aboutOf = (messageId) => groupsFor(messageId)[0]?.about || aboutCache.get(messageId) || null;

    /** New data. A live refresh never redraws over a reply someone is typing (force: after our own change). */
    function setGroups(next, force) {
      const typing = [...root.querySelectorAll('textarea')].some((t) => t.value.trim());
      if (typing && !force) { quietGroups(next); return; }
      groups = Array.isArray(next) ? next : [];
      opts.onGroups?.(groups);
      render();
    }
    /** Update the data (bubbles) without redrawing the panel — keeps half-typed replies. */
    function quietGroups(next) {
      groups = Array.isArray(next) ? next : groups;
      opts.onGroups?.(groups);
      body.querySelectorAll('[data-group-id]').forEach((n) => {
        const g = groups.find((x) => x.id === Number(n.dataset.groupId));
        if (g && !g.unread) n.classList.remove('unread');
      });
    }

    async function run(work, okText) {
      try { const d = await work(); if (d?.groups) setGroups(d.groups, true); if (okText) say(typeof okText === 'function' ? okText(d) : okText); return d; }
      catch (e) { say(e.message); return null; }
    }
    const notified = (d) => (d?.mentioned?.length ? d.mentioned.length + ' colleague' + (d.mentioned.length === 1 ? '' : 's') + ' notified.' : '');

    // ------------------------------------------------------------ pieces

    /** A textarea with @mentions and a submit button. */
    function composer(placeholder, buttonText, onSubmit, autofocus) {
      const form = el('form', 'np-compose');
      form.autocomplete = 'off';
      const wrap = el('div', 'mention-wrap');
      const ta = el('textarea');
      ta.rows = 2; ta.placeholder = placeholder; ta.setAttribute('aria-label', placeholder);
      const menu = el('ul', 'mention-menu');
      menu.setAttribute('role', 'listbox'); menu.hidden = true;
      wrap.append(ta, menu);
      const row = el('div', 'np-compose-row');
      const hint = el('span', 'muted', 'Ctrl+Enter to send');
      const btn = el('button', 'primary', buttonText);
      btn.type = 'submit';
      row.append(hint, btn);
      form.append(wrap, row);
      const mention = window.ecwMentionInput ? window.ecwMentionInput(ta, menu, { users: opts.users, me: opts.me, submit: () => form.requestSubmit() })
        : { serialize: () => ta.value.trim(), reset: () => { ta.value = ''; } };
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = mention.serialize();
        if (!text) { ta.focus(); return; }
        btn.disabled = true;
        const d = await onSubmit(text);
        btn.disabled = false;
        if (d) mention.reset();
      });
      if (autofocus) setTimeout(() => ta.focus(), 30);
      return form;
    }

    function noteItem(n, g) {
      const li = el('li', 'np-note' + (n.mine ? ' mine' : ''));
      li.id = 'note-' + n.id;
      const h = el('div', 'np-note-head');
      if (window.ecwAvatar) h.appendChild(window.ecwAvatar(n.author, 24));
      const who = el('span', 'np-who');
      who.append(el('strong', '', n.author.name || n.author.email), el('span', 'muted', local(n.created_at)));
      h.appendChild(who);
      if (n.mine) {
        const del = el('button', 'np-del');
        del.type = 'button';
        const first = n.id === g.id;
        del.title = first ? 'Delete this conversation' : 'Delete reply';
        del.setAttribute('aria-label', del.title);
        del.appendChild(icon('fa-trash-can'));
        del.addEventListener('click', async () => {
          if (del.dataset.confirm !== '1') {
            del.dataset.confirm = '1'; del.classList.add('armed');
            del.title = first && g.notes.length > 1 ? 'Click again — deletes the replies too' : 'Click again to delete';
            setTimeout(() => { del.dataset.confirm = ''; del.classList.remove('armed'); }, 3500);
            return;
          }
          await run(() => opts.api.remove(n.id), first ? 'Conversation deleted.' : 'Reply deleted.');
        });
        h.appendChild(del);
      }
      const b = el('div', 'np-note-body');
      b.innerHTML = n.html; // XSS: escaped server-side by ecw_note_render()
      li.append(h, b);
      return li;
    }

    /** One conversation, expanded (message / general views). */
    function thread(g) {
      const card = el('article', 'np-thread' + (g.resolved ? ' resolved' : '') + (g.unread ? ' unread' : '') + (g.id === focusId ? ' focus' : ''));
      card.dataset.groupId = String(g.id);
      if (g.resolved) {
        // Resolved: one line, like a collapsed Word comment. Click to read.
        const bar = el('button', 'np-resolved-bar');
        bar.type = 'button';
        bar.append(icon('fa-circle-check'), el('span', '', 'Resolved' + (g.resolved_by ? ' by ' + g.resolved_by : '') + ' · ' + plain(g.notes[0].html).slice(0, 60)));
        bar.setAttribute('aria-expanded', 'false');
        bar.addEventListener('click', () => { const open = card.classList.toggle('expanded'); bar.setAttribute('aria-expanded', String(open)); });
        card.appendChild(bar);
      }
      const list = el('ol', 'np-notes');
      g.notes.forEach((n) => list.appendChild(noteItem(n, g)));
      card.appendChild(list);

      const foot = el('div', 'np-thread-foot');
      const replyBtn = el('button', 'np-link');
      replyBtn.type = 'button';
      replyBtn.append(icon('fa-reply'), el('span', '', 'Reply'));
      const resolveBtn = el('button', 'np-link');
      resolveBtn.type = 'button';
      resolveBtn.append(icon(g.resolved ? 'fa-rotate-left' : 'fa-check'), el('span', '', g.resolved ? 'Reopen' : 'Resolve'));
      resolveBtn.addEventListener('click', () => run(() => opts.api.resolve(g.id, !g.resolved), g.resolved ? 'Conversation reopened.' : 'Marked as resolved.'));
      foot.append(replyBtn, resolveBtn);
      card.appendChild(foot);

      let box = null;
      replyBtn.addEventListener('click', () => {
        if (box) { box.querySelector('textarea').focus(); return; }
        box = composer('Reply… type @ to mention', 'Reply', (text) => run(() => opts.api.add(text, { parent_id: g.id }), notified), true);
        card.insertBefore(box, foot);
        replyBtn.hidden = true;
      });
      // Someone else wrote here since you last looked: it is now read.
      if (g.unread && !seenAsked.has(g.id + ':' + g.last_at)) {
        seenAsked.add(g.id + ':' + g.last_at);
        opts.api.seen(g.id).then((d) => quietGroups(d?.groups)).catch(() => {});
      }
      return card;
    }

    /** One conversation as a card in the "all" list. */
    function card(g) {
      const c = el('button', 'np-card' + (g.resolved ? ' resolved' : '') + (g.unread ? ' unread' : ''));
      c.type = 'button';
      c.dataset.groupId = String(g.id);
      const about = el('span', 'np-card-about');
      if (g.message_id) about.append(icon('fa-envelope'), el('span', '', (g.about?.from || 'Email') + ' · ' + local(g.about?.at)));
      else about.append(icon('fa-thumbtack'), el('span', '', 'General · ' + (opts.generalLabel || 'whole conversation')));
      if (g.unread) about.appendChild(el('span', 'np-dot', ''));
      const first = g.notes[0];
      const line = el('span', 'np-card-first');
      if (window.ecwAvatar) line.appendChild(window.ecwAvatar(first.author, 20));
      const txt = el('span', 'np-card-text');
      txt.append(el('strong', '', (first.author.name || first.author.email) + ' '), document.createTextNode(plain(first.html)));
      line.appendChild(txt);
      const meta = el('span', 'np-card-meta');
      const replies = g.notes.length - 1;
      meta.append(el('span', '', replies ? replies + (replies === 1 ? ' reply' : ' replies') : 'No replies'));
      if (g.participants.length > 1) {
        const stack = el('span', 'np-avatars');
        g.participants.slice(0, 4).forEach((p) => { if (window.ecwAvatar) stack.appendChild(window.ecwAvatar(p, 18)); });
        meta.appendChild(stack);
      }
      meta.appendChild(el('span', 'np-when', g.resolved ? 'Resolved' : ago(g.last_at)));
      c.append(about, line, meta);
      c.addEventListener('click', () => showGroup(g.id));
      return c;
    }

    // ------------------------------------------------------------ views

    function render() {
      body.textContent = '';
      back.hidden = view.kind === 'all';
      if (view.kind === 'all') return renderAll();
      const messageId = view.kind === 'message' ? view.id : null;
      const list = groupsFor(messageId);

      const top = el('div', 'np-about');
      if (messageId) {
        const a = aboutOf(messageId);
        top.append(icon('fa-envelope'), el('span', '', 'On ' + (a?.from ? a.from + '’s' : 'this') + ' email' + (a?.at ? ' · ' + local(a.at) : '')));
        const jump = el('button', 'np-link');
        jump.type = 'button'; jump.textContent = 'Show email';
        jump.addEventListener('click', () => opts.jumpTo?.(messageId));
        top.appendChild(jump);
      } else {
        top.append(icon('fa-thumbtack'), el('span', '', 'General notes · ' + (opts.generalLabel || 'whole conversation')));
      }
      body.appendChild(top);
      title.textContent = messageId ? 'Notes on this email' : 'General notes';

      // Open conversations first, resolved ones after; each oldest first.
      [...list.filter((g) => !g.resolved), ...list.filter((g) => g.resolved)].forEach((g) => body.appendChild(thread(g)));
      const label = list.length ? 'Start another conversation' : 'Start a conversation';
      const newBox = el('section', 'np-new');
      newBox.appendChild(el('span', 'np-new-label', label));
      newBox.appendChild(composer(messageId ? 'Write a note about this email… type @ to mention' : 'Write a general note… type @ to mention', 'Add note',
        (text) => run(() => opts.api.add(text, messageId ? { message_id: messageId } : {}), (d) => 'Note added. ' + notified(d)), !list.length));
      body.appendChild(newBox);
      const focused = focusId && body.querySelector('[data-group-id="' + focusId + '"]');
      if (focused) setTimeout(() => focused.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), 30);
    }

    function renderAll() {
      title.textContent = 'Notes';
      const open = groups.filter((g) => !g.resolved), done = groups.filter((g) => g.resolved);
      const bar = el('div', 'np-toolbar');
      const tabs = el('div', 'np-tabs');
      tabs.setAttribute('role', 'tablist');
      tabs.dataset.i18nCtx = 'notes';   // "Open" here means open conversations, not the action (assets/i18n.js)
      [['open', 'Open', open.length], ['resolved', 'Resolved', done.length]].forEach(([key, label, n]) => {
        const t = el('button', 'np-tab' + (filter === key ? ' active' : ''));
        t.type = 'button'; t.setAttribute('role', 'tab'); t.setAttribute('aria-selected', String(filter === key));
        t.append(el('span', '', label), el('span', 'np-count', String(n)));
        t.addEventListener('click', () => { filter = key; render(); });
        tabs.appendChild(t);
      });
      const general = el('button', 'np-link np-general');
      general.type = 'button';
      general.append(icon('fa-plus'), el('span', '', 'General note'));
      general.title = 'A note about ' + (opts.generalLabel || 'the whole conversation') + ', not one email';
      general.addEventListener('click', () => showGeneral());
      bar.append(tabs, general);
      body.appendChild(bar);

      const shown = (filter === 'open' ? open : done).slice().sort((a, b) => (a.last_at < b.last_at ? 1 : -1));
      if (!shown.length) {
        const empty = el('div', 'np-empty');
        empty.append(icon(filter === 'open' ? 'fa-comments' : 'fa-circle-check'),
          el('p', '', filter === 'open' ? (groups.length ? 'No open notes. Everything is resolved.' : 'No notes yet.') : 'Nothing resolved yet.'));
        if (filter === 'open' && !groups.length) empty.appendChild(el('p', 'muted', 'Click 💬 at the bottom right of an email to start a conversation about it.'));
        body.appendChild(empty);
        return;
      }
      const list = el('div', 'np-cards');
      shown.forEach((g) => list.appendChild(card(g)));
      body.appendChild(list);
    }

    function showAll() { view = { kind: 'all' }; focusId = null; render(); }
    function showMessage(id, about) {
      id = Number(id);
      if (about) aboutCache.set(id, about);
      view = { kind: 'message', id }; focusId = null; render();
    }
    function showGeneral() { view = { kind: 'general' }; focusId = null; render(); }
    function showGroup(id) {
      const g = groups.find((x) => x.id === Number(id));
      if (!g) { showAll(); return; }
      view = g.message_id ? { kind: 'message', id: g.message_id } : { kind: 'general' };
      focusId = g.id;
      render();
      if (g.message_id) opts.jumpTo?.(g.message_id, true);
    }
    render();
    return { setGroups, showAll, showMessage, showGeneral, showGroup, groups: () => groups, view: () => view };
  };

  /**
   * The 💬 button at the bottom right of an email. Both pages draw it with this
   * and repaint it with paintNoteBubbles() whenever the conversations change.
   */
  window.ecwNoteBubble = function (messageId, onClick) {
    const b = el('button', 'note-bubble');
    b.type = 'button';
    b.dataset.noteMsg = String(messageId);
    b.title = 'Internal notes about this email';
    b.setAttribute('aria-label', b.title);
    b.append(icon('fa-comment'), el('span', 'note-bubble-count', ''));
    b.addEventListener('click', (e) => { e.stopPropagation(); onClick(Number(messageId)); });
    return b;
  };
  window.ecwPaintNoteBubbles = function (scope, groups) {
    scope.querySelectorAll('.note-bubble[data-note-msg]').forEach((b) => {
      const mine = groups.filter((g) => g.message_id === Number(b.dataset.noteMsg));
      const open = mine.filter((g) => !g.resolved);
      const notes = mine.reduce((n, g) => n + g.notes.length, 0);
      b.classList.toggle('has-notes', mine.length > 0);
      b.classList.toggle('all-resolved', mine.length > 0 && !open.length);
      b.classList.toggle('unread', mine.some((g) => g.unread));
      b.querySelector('.note-bubble-count').textContent = mine.length ? String(open.length || mine.length) : '';
      b.title = !mine.length ? 'Add an internal note about this email'
        : (open.length ? open.length + ' open conversation' + (open.length === 1 ? '' : 's') : 'All resolved') + ' · ' + notes + ' note' + (notes === 1 ? '' : 's');
      b.setAttribute('aria-label', b.title);
    });
  };
})();
