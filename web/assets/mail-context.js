/**
 * Right-click menu on the email list, like Outlook's, plus the Read/Unread
 * button in the reading pane.
 *
 * Opens on right-click, long-press (touch browsers fire contextmenu), or the
 * keyboard's Menu key / Shift+F10 on a focused row. Arrow keys move, Enter
 * picks, Esc closes. Talks to app.js only through window.ecwMail. All text is
 * written with textContent.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const $ = (id) => document.getElementById(id);
  const list = $('threadList');

  const menu = document.createElement('div');
  menu.className = 'ctx-menu';
  menu.setAttribute('role', 'menu');
  menu.hidden = true;
  document.body.appendChild(menu);

  function at(kind) {
    const d = new Date();
    if (kind === 'later') d.setHours(d.getHours() + 3, 0, 0, 0);
    if (kind === 'tomorrow') { d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); }
    if (kind === 'week') { d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(9, 0, 0, 0); }
    return d;
  }

  async function openAndThen(thread, fn) {
    if (M.state.threadId !== thread.id || !M.state.thread) await M.openThread(thread.id);
    if (M.state.thread && M.state.threadId === thread.id) fn(M.state.thread);
  }
  /** Reply to the latest email from the other side (or the latest at all). */
  function replyTarget(t) {
    const incoming = t.messages.filter((m) => m.direction === 'incoming');
    return M.latestMessageId(incoming.length ? incoming : t.messages);
  }

  function items(thread) {
    const link = new URL('app.php?thread=' + thread.id + '&mailbox=' + M.state.mailboxId, location.href).href;
    const out = [
      { icon: 'fa-envelope-open', label: 'Open', run: () => M.openThread(thread.id) },
      { icon: 'fa-window-restore', label: 'Open in pop-up window', run: () => window.ecwOpenThreadPopup?.({ threadId: thread.id, mailboxId: M.state.mailboxId, subject: thread.subject }) },
      { icon: 'fa-arrow-up-right-from-square', label: 'Open in new tab', run: () => window.open(link, '_blank', 'noopener') },
      '-',
      { icon: 'fa-reply', label: 'Reply', run: () => openAndThen(thread, (t) => M.openComposer('reply', replyTarget(t))) },
      { icon: 'fa-reply-all', label: 'Reply all', run: () => openAndThen(thread, (t) => M.openComposer('reply_all', replyTarget(t))) },
      { icon: 'fa-share', label: 'Forward', run: () => openAndThen(thread, (t) => M.openComposer('forward', M.latestMessageId(t.messages))) },
      '-',
      thread.unread
        ? { icon: 'fa-envelope-open', label: 'Mark as read', run: () => M.setThreadRead(thread.id, true) }
        : { icon: 'fa-envelope', label: 'Mark as unread', run: () => M.setThreadRead(thread.id, false) },
      thread.flagged
        ? { icon: 'fa-flag', label: 'Clear flag', run: () => window.ecwMailActions?.flag(thread, false) }
        : { icon: 'fa-flag', label: 'Flag', run: () => window.ecwMailActions?.flag(thread, true) },
      { icon: 'fa-box-archive', label: 'Archive', run: () => window.ecwMailActions?.move(thread, 'archive') },
      { icon: 'fa-folder-tree', label: 'Move to folder…', run: () => window.ecwMailActions?.pickFolder(thread, document.querySelector('.thread-row[data-thread-id="' + thread.id + '"]')) },
      { icon: 'fa-trash-can', label: window.ecwMailActions?.inDeletedFolder() ? 'Delete permanently' : 'Delete', danger: !!window.ecwMailActions?.inDeletedFolder(), run: () => window.ecwMailActions?.move(thread, 'deleted') },
      ...(window.ecwMailActions?.inJunkFolder()
        ? [{ icon: 'fa-inbox', label: 'Not junk', run: () => window.ecwMailActions.junk(thread, { not: true }) }]
        : [{ icon: 'fa-ban', label: 'Report as junk', run: () => window.ecwMailActions?.junk(thread) },
           { icon: 'fa-user-slash', label: 'Block sender', run: () => window.ecwMailActions?.junk(thread, { block: true }) }]),
      '-',
      { icon: 'fa-clock', label: 'Snooze until later today', run: () => snooze(thread, 'later') },
      { icon: 'fa-sun', label: 'Snooze until tomorrow', run: () => snooze(thread, 'tomorrow') },
      { icon: 'fa-calendar-week', label: 'Snooze until next week', run: () => snooze(thread, 'week') },
      '-',
    ];
    if (thread.case) {
      out.push({ icon: 'fa-folder-open', label: 'Open case ' + thread.case.number, run: () => { location.href = 'case.php?id=' + thread.case.id; } });
      out.push({ icon: 'fa-up-right-from-square', label: 'Open case in a new tab', run: () => { window.open('case.php?id=' + thread.case.id, '_blank', 'noopener'); } });
    } else {
      out.push({ icon: 'fa-folder-plus', label: 'Add to case…', run: () => openAndThen(thread, () => $('addToCaseBtn').click()) });
    }
    if (window.ecwTeams && !document.documentElement.classList.contains('no-feature-teams')) {
      out.push({ icon: 'ecw-teams-icon', label: 'Chat in Teams', run: () => openAndThen(thread, () => $('teamsThreadBtn').click()) });
    }
    if (window.ecwPrintThread && !document.documentElement.classList.contains('no-feature-export')) {
      out.push({ icon: 'fa-print', label: 'Print / Save as PDF', run: () => window.ecwPrintThread(thread.id, M.state.mailboxId) });
    }
    out.push({ icon: 'fa-link', label: 'Copy link', run: async () => {
      try { await navigator.clipboard.writeText(link); M.toast('Link copied'); } catch (_) { M.toast(link); }
    } });
    return out;
  }

  async function snooze(thread, kind) {
    try {
      const r = await M.api('thread_snooze', {}, { method: 'POST', body: { thread_id: thread.id, until: at(kind).toISOString() } });
      M.toast('Snoozed until ' + new Date(String(r.snoozed_until).replace(' ', 'T') + 'Z').toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }));
      await (M.refreshList || M.resetList)();   // the list stays where it was scrolled to
    } catch (e) { M.toast(e.message, true); }
  }

  let returnFocus = null;
  function close() {
    if (menu.hidden) return;
    menu.hidden = true;
    menu.textContent = '';
    document.querySelectorAll('.thread-row.ctx-target').forEach((r) => r.classList.remove('ctx-target'));
    returnFocus?.focus({ preventScroll: true });
    returnFocus = null;
  }

  /** Right-click on a draft in the Drafts list. */
  function draftItems(d, row) {
    const openIt = async () => {
      if (!d.from_mailbox_only) { M.openDraft(d.id); return; }
      try { const r = await M.api('draft_import', { mailbox_id: d.mailbox_id }, { method: 'POST', body: { provider_id: d.provider_id }, noMailbox: true }); M.openDraft(r.draft_id); }
      catch (e) { M.toast(e.message, true); M.reloadDrafts?.(); }
    };
    if (d.from_mailbox_only) return [{ icon: 'fa-pen-to-square', label: 'Open (bring in from ' + (d.provider_label || 'mailbox') + ')', run: openIt }, '-',
      { icon: 'fa-eye-slash', label: 'Remove from the list (it stays in ' + (d.provider_label || 'the mailbox') + ')', run: async () => {
        try { await M.api('draft_forget', {}, { method: 'POST', body: { provider_id: d.provider_id }, noMailbox: true }); row.closest('li')?.remove(); M.toast('Removed from the list'); }
        catch (e) { M.toast(e.message, true); }
      } }];
    const out = [{ icon: 'fa-pen-to-square', label: 'Open', run: openIt }];
    if (d.send_state === 'scheduled') {
      out.push({ icon: 'fa-clock-rotate-left', label: 'Cancel scheduled send', run: async () => {
        try { const r = await M.api('send_cancel', {}, { method: 'POST', body: { draft_id: d.id } }); M.toast(r.cancelled ? 'Schedule cancelled' : 'It was already sent'); M.resetList(); }
        catch (e) { M.toast(e.message, true); }
      } });
    } else if ((d.to || []).length) {
      out.push({ icon: 'fa-paper-plane', label: 'Send now', run: async () => {
        try { await M.api('send', { mailbox_id: d.mailbox_id }, { method: 'POST', body: { draft_id: d.id }, noMailbox: true }); M.toast('Sending in a few seconds'); M.resetList(); }
        catch (e) { M.toast(e.message, true); }
      } });
    }
    out.push('-', { icon: 'fa-trash-can', label: 'Discard draft', confirm: 'Click again to discard', danger: true, run: async () => {
      try {
        await M.api('draft_discard', {}, { method: 'POST', body: { draft_id: d.id } });
        window.ecwDraftDock?.remove(d.id);
        row.closest('li')?.remove();
        M.toast('Draft discarded');
      } catch (e) { M.toast(e.message, true); }
    } });
    return out;
  }

  function open(row, x, y) {
    const list = window.ecwMailSelect?.menuFor(row)
      || (row._draft ? draftItems(row._draft, row) : row._thread ? items(row._thread) : null);
    if (!list) return;
    menu.textContent = '';
    list.forEach((it) => {
      if (it === '-') { const hr = document.createElement('div'); hr.className = 'ctx-sep'; hr.setAttribute('role', 'separator'); menu.appendChild(hr); return; }
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'ctx-item';
      b.setAttribute('role', 'menuitem');
      const i = document.createElement('i');
      i.className = 'fa ' + it.icon;
      i.setAttribute('aria-hidden', 'true');
      const span = document.createElement('span');
      span.textContent = it.label;
      b.append(i, span);
      if (it.danger) b.classList.add('danger');
      b.addEventListener('click', () => {
        // Destructive items ask for a second click, the same red "armed" style as elsewhere.
        if (it.confirm && b.dataset.confirm !== '1') { b.dataset.confirm = '1'; span.textContent = it.confirm; return; }
        close(); it.run();
      });
      menu.appendChild(b);
    });
    row.classList.add('ctx-target');
    returnFocus = row;
    menu.hidden = false;
    // Keep it on screen.
    const r = menu.getBoundingClientRect();
    menu.style.left = Math.max(6, Math.min(x, window.innerWidth - r.width - 6)) + 'px';
    menu.style.top = Math.max(6, Math.min(y, window.innerHeight - r.height - 6)) + 'px';
    menu.querySelector('.ctx-item')?.focus({ preventScroll: true });
  }

  list.addEventListener('contextmenu', (e) => {
    const row = e.target.closest('.thread-row');
    if (!row || (!row._thread && !row._draft)) return;
    e.preventDefault();
    if (e.clientX === 0 && e.clientY === 0) {   // from the keyboard
      const r = row.getBoundingClientRect();
      open(row, r.left + 24, r.top + r.height / 2);
    } else {
      open(row, e.clientX, e.clientY);
    }
  });
  list.addEventListener('keydown', (e) => {
    if (e.key === 'ContextMenu' || (e.key === 'F10' && e.shiftKey)) {
      const row = e.target.closest('.thread-row');
      if (!row) return;
      e.preventDefault();
      const r = row.getBoundingClientRect();
      open(row, r.left + 24, r.top + r.height / 2);
    }
  });
  menu.addEventListener('keydown', (e) => {
    const all = [...menu.querySelectorAll('.ctx-item')];
    const i = all.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      all[(i + (e.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length].focus();
    } else if (e.key === 'Escape' || e.key === 'Tab') {
      e.preventDefault();
      close();
    }
  });
  document.addEventListener('pointerdown', (e) => { if (!menu.hidden && !menu.contains(e.target)) close(); }, true);
  window.addEventListener('blur', close);
  window.addEventListener('resize', close);
  document.addEventListener('scroll', close, true);

  // ------------------------------------------- Read / Unread in the reading pane
  const toggle = $('threadUnreadBtn');
  if (toggle) {
    const paint = () => {
      const row = document.querySelector('.thread-row[data-thread-id="' + M.state.threadId + '"]');
      const unread = !!row?.classList.contains('unread');
      toggle.innerHTML = '';
      const i = document.createElement('i');
      i.className = 'fa ' + (unread ? 'fa-envelope-open' : 'fa-envelope');
      i.setAttribute('aria-hidden', 'true');
      toggle.append(i, document.createTextNode(unread ? ' Mark read' : ' Mark unread'));
      toggle.title = unread ? 'Mark this conversation as read' : 'Mark this conversation as unread (in Outlook too)';
    };
    toggle.addEventListener('click', async () => {
      if (!M.state.threadId) return;
      const row = document.querySelector('.thread-row[data-thread-id="' + M.state.threadId + '"]');
      await M.setThreadRead(M.state.threadId, !!row?.classList.contains('unread'));
      paint();
    });
    document.addEventListener('ecw:thread-open', () => setTimeout(paint, 0));
    document.addEventListener('ecw:unread-changed', paint);
  }
})();
