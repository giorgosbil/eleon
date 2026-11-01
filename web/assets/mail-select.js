/**
 * Select several emails in the list and act on them together — in every folder,
 * and in Drafts (discard only).
 *
 *   Ctrl/Cmd+click   add or remove one row
 *   Shift+click      select the range from the last picked row
 *   small round mark at the row's left edge (shows on hover): same as Ctrl+click
 *   X on a focused row toggles it, Esc clears, Delete deletes the selection
 *
 * While something is selected a slim bar replaces the list header. Right-click
 * on a selected row (mail-context.js) shows the same bulk actions.
 * Talks to app.js only through window.ecwMail. Text is written with textContent.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const list = $('threadList');
  const header = document.querySelector('.list-header');
  if (!list || !header) return;

  const picked = new Set();   // 't12' = thread 12, 'd5' = local draft 5
  let anchorKey = null, scope = '';
  const keyOf = (row) => (row._thread ? 't' + row._thread.id : row._draft && !row._draft.from_mailbox_only ? 'd' + row._draft.id : null);
  const rows = () => [...list.querySelectorAll('.thread-row')].filter((r) => keyOf(r) && !r.closest('li')?.hidden && !r.closest('li')?.classList.contains('group-folded'));
  const scopeNow = () => [M.state.mailboxId, M.state.folderId, M.state.folderType, M.state.draftsView ? 1 : 0, M.state.query || '', M.state.snoozed ? 1 : 0].join('|');
  const draftsMode = () => [...picked].some((k) => k[0] === 'd');

  // ------------------------------------------------------------ row marks
  function decorate(row) {
    if (row.querySelector('.row-check') || !keyOf(row)) return;
    const c = el('span', 'row-check');
    c.setAttribute('aria-hidden', 'true');   // the row itself announces aria-pressed
    c.title = 'Select';
    row.prepend(c);
  }
  function paint() {
    rows().forEach((r) => {
      decorate(r);
      const on = picked.has(keyOf(r));
      r.classList.toggle('selected', on);
      if (on) r.setAttribute('aria-pressed', 'true'); else r.removeAttribute('aria-pressed');
    });
    list.classList.toggle('selecting', picked.size > 0);
    drawBar();
  }

  function toggle(row, range) {
    const key = keyOf(row);
    if (!key) return;
    if (!picked.size) scope = scopeNow();
    if (range && anchorKey) {
      const all = rows(), keys = all.map(keyOf);
      const a = keys.indexOf(anchorKey), b = keys.indexOf(key);
      if (a >= 0 && b >= 0) {
        keys.slice(Math.min(a, b), Math.max(a, b) + 1).forEach((k) => picked.add(k));
        paint();
        return;
      }
    }
    if (picked.has(key)) picked.delete(key); else picked.add(key);
    anchorKey = key;
    paint();
  }
  function clear() { picked.clear(); anchorKey = null; paint(); }

  // Capture phase: a selecting click must not also open the email.
  list.addEventListener('click', (e) => {
    const row = e.target.closest('.thread-row');
    if (!row || !keyOf(row)) return;
    const onMark = !!e.target.closest('.row-check');
    if (!onMark && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
      if (picked.size) clear();   // a plain click goes back to reading one email
      return;
    }
    e.preventDefault();
    e.stopImmediatePropagation();
    toggle(row, e.shiftKey);
  }, true);
  // Shift+click would otherwise highlight text across rows.
  list.addEventListener('mousedown', (e) => { if (e.shiftKey && e.target.closest('.thread-row')) e.preventDefault(); });

  // Live refreshes redraw the list: keep the marks. Another folder: start over.
  new MutationObserver(() => {
    if (picked.size && scope !== scopeNow()) { picked.clear(); anchorKey = null; }
    if (picked.size) {
      const live = new Set(rows().map(keyOf));
      // Only drop vanished rows once the list has finished drawing.
      if (live.size && !M.state.loading) [...picked].forEach((k) => { if (!live.has(k)) picked.delete(k); });
    }
    paint();
  }).observe(list, { childList: true });

  // ------------------------------------------------------------ bar
  const bar = el('div', 'select-bar');
  bar.setAttribute('role', 'toolbar');
  bar.setAttribute('aria-label', 'Selected emails');
  bar.hidden = true;
  header.appendChild(bar);
  const btn = (icon, label, run, cls) => {
    const b = el('button', 'select-btn' + (cls ? ' ' + cls : ''));
    b.type = 'button';
    b.title = label;
    b.setAttribute('aria-label', label);
    const i = el('i', 'fa ' + icon);
    i.setAttribute('aria-hidden', 'true');
    b.appendChild(i);
    b.addEventListener('click', (e) => run(e.currentTarget));
    return b;
  };

  function drawBar() {
    bar.hidden = !picked.size;
    header.classList.toggle('has-selection', picked.size > 0);
    if (!picked.size) { bar.replaceChildren(); return; }
    const count = el('span', 'select-count', picked.size + ' selected');
    count.setAttribute('aria-live', 'polite');
    const all = el('button', 'select-all', 'All');
    all.type = 'button';
    all.title = 'Select every email shown';
    all.addEventListener('click', () => { rows().forEach((r) => picked.add(keyOf(r))); paint(); });
    const tools = el('div', 'select-tools');
    actions().forEach((a) => tools.appendChild(a === '-' ? el('span', 'select-sep') : btn(a.icon, a.label, a.run, a.cls)));
    bar.replaceChildren(btn('fa-xmark', 'Clear selection (Esc)', clear, 'select-close'), count, all, tools);
  }

  function actions() {
    if (draftsMode()) return [{ icon: 'fa-trash-can', label: 'Discard drafts', cls: 'danger', run: discardDrafts }];
    const A = window.ecwMailActions;
    const out = [
      { icon: 'fa-envelope-open', label: 'Mark as read', run: () => bulk('read') },
      { icon: 'fa-envelope', label: 'Mark as unread', run: () => bulk('unread') },
      { icon: 'fa-flag', label: 'Flag', run: () => bulk('flag') },
      { icon: 'fa-regular fa-flag', label: 'Clear flag', run: () => bulk('unflag') },
      '-',
      { icon: 'fa-box-archive', label: 'Archive', run: () => bulk('move', 'archive') },
      { icon: 'fa-folder-tree', label: 'Move to folder…', run: (b) => moveTo(b) },
    ];
    if (A?.inJunkFolder()) out.push({ icon: 'fa-inbox', label: 'Not junk', run: () => bulk('move', 'inbox') });
    else out.push({ icon: 'fa-ban', label: 'Report as junk', run: () => bulk('junk') });
    out.push(A?.inDeletedFolder()
      ? { icon: 'fa-trash-can', label: 'Delete permanently (Del)', cls: 'danger', run: deleteSelected }
      : { icon: 'fa-trash-can', label: 'Delete (Del)', cls: 'danger', run: deleteSelected });
    return out;
  }
  /** In Deleted Items, Delete is for good: confirm first. */
  function deleteSelected() {
    const A = window.ecwMailActions;
    if (A?.inDeletedFolder()) A.askForever(threadIds(), () => bulk('move', 'forever'));
    else bulk('move', 'deleted');
  }

  // ------------------------------------------------------------ doing it
  const threadIds = () => [...picked].filter((k) => k[0] === 't').map((k) => Number(k.slice(1)));
  const VERB = { read: 'Marked as read', unread: 'Marked as unread', flag: 'Flagged', unflag: 'Flag cleared', junk: 'Moved to Junk' };
  let busy = false;

  async function bulk(op, target) {
    const ids = threadIds();
    if (!ids.length || busy) return;
    busy = true;
    bar.classList.add('busy');
    const moving = op === 'move' || op === 'junk';
    if (moving) ids.forEach((id) => { const li = list.querySelector('.thread-row[data-thread-id="' + id + '"]')?.closest('li'); if (li) li.hidden = true; });
    if (moving && ids.includes(M.state.threadId)) M.showEmpty();
    try {
      const r = await M.api('threads_bulk', {}, { method: 'POST', body: { thread_ids: ids, op, target: target || '' } });
      const n = Array.isArray(r.threads) ? r.threads.length : ids.length;
      const what = n + ' conversation' + (n === 1 ? '' : 's');
      const label = target === 'forever' ? 'Deleted ' + (r.deleted || 0) + ' email' + (r.deleted === 1 ? '' : 's') + ' permanently'
        : op === 'move'
        ? (target === 'deleted' ? 'Deleted ' : target === 'archive' ? 'Archived ' : 'Moved ') + what + (target === 'deleted' || target === 'archive' ? '' : ' to ' + (r.to_name || 'folder'))
        : VERB[op] + ' — ' + what;
      picked.clear(); anchorKey = null;
      await (M.refreshList || M.resetList)();   // the list stays where it was scrolled to
      M.reloadFolders().catch(() => {});
      document.dispatchEvent(new CustomEvent('ecw:unread-changed'));
      const undo = r.moved && r.moved.length ? async () => {
        try {
          await M.api('messages_restore', {}, { method: 'POST', body: { moves: r.moved } });
          await (M.refreshList || M.resetList)(); M.reloadFolders().catch(() => {});   // where the list is scrolled to
          document.dispatchEvent(new CustomEvent('ecw:unread-changed'));
          (window.ecwMailActions?.undoToast || M.toast)('Moved back');
        } catch (e) { M.toast(e.message, true); }
      } : null;
      const msg = label + (r.failed ? ' (' + r.failed + ' failed)' : '');
      if (window.ecwMailActions?.undoToast) window.ecwMailActions.undoToast(msg, undo); else M.toast(msg);
    } catch (e) {
      if (moving) ids.forEach((id) => { const li = list.querySelector('.thread-row[data-thread-id="' + id + '"]')?.closest('li'); if (li) li.hidden = false; });
      M.toast(e.message, true);
    } finally {
      busy = false;
      bar.classList.remove('busy');
      paint();
    }
  }

  function moveTo(anchor) {
    const A = window.ecwMailActions;
    if (!A) return;
    A.pickFolder({ id: 0, count: threadIds().length, subject: '' }, anchor, (f) => bulk('move', String(f.id)));
  }

  async function discardDrafts() {
    const ids = [...picked].filter((k) => k[0] === 'd').map((k) => Number(k.slice(1)));
    if (!ids.length || busy) return;
    const b = bar.querySelector('.select-btn.danger');
    if (b && b.dataset.confirm !== '1') {   // second click confirms, like elsewhere
      b.dataset.confirm = '1';
      b.title = 'Click again to discard ' + ids.length + ' draft' + (ids.length === 1 ? '' : 's');
      b.setAttribute('aria-label', b.title);
      b.appendChild(el('span', 'select-confirm', 'Discard ' + ids.length + '?'));
      return;
    }
    busy = true;
    let done = 0;
    for (const id of ids) {
      try { await M.api('draft_discard', {}, { method: 'POST', body: { draft_id: id } }); window.ecwDraftDock?.remove(id); done++; }
      catch (e) { M.toast(e.message, true); }
    }
    busy = false;
    picked.clear(); anchorKey = null;
    await M.resetList();
    M.reloadFolders().catch(() => {});
    M.toast(done + ' draft' + (done === 1 ? '' : 's') + ' discarded');
  }

  // ------------------------------------------------------------ keys
  document.addEventListener('keydown', (e) => {
    if (e.target.closest?.('input, textarea, select, [contenteditable="true"], .composer-window, dialog')) return;
    if (!$('composerOverlay')?.hidden) return;
    if ((e.key === 'x' || e.key === 'X') && !e.ctrlKey && !e.metaKey && !e.altKey) {
      const row = e.target.closest?.('.thread-row');
      if (row && keyOf(row)) { e.preventDefault(); toggle(row, e.shiftKey); }
      return;
    }
    if (!picked.size) return;
    if (e.key === 'Escape') { e.preventDefault(); clear(); return; }
    if (e.key === 'Delete' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      e.stopImmediatePropagation();   // mail-actions.js would delete just the open one
      if (draftsMode()) discardDrafts(); else deleteSelected();
    }
  }, true);

  // ------------------------------------------------------------ right-click
  /** Bulk menu when right-clicking a row that is part of a selection of 2+. */
  function menuFor(row) {
    const key = keyOf(row);
    if (!key || !picked.has(key) || picked.size < 2) return null;
    const n = picked.size;
    if (draftsMode()) {
      return [{ icon: 'fa-trash-can', label: 'Discard ' + n + ' drafts', danger: true, confirm: 'Click again to discard ' + n, run: async () => {
        const b = bar.querySelector('.select-btn.danger');
        if (b) b.dataset.confirm = '1';   // already confirmed in the menu
        discardDrafts();
      } }];
    }
    const out = [];
    actions().forEach((a) => {
      if (a === '-') { out.push('-'); return; }
      out.push({ icon: a.icon.replace('fa-regular ', ''), label: a.label.replace(' (Del)', '') + ' (' + n + ')', danger: a.cls === 'danger', run: () => a.run(row) });
    });
    out.push('-', { icon: 'fa-xmark', label: 'Clear selection', run: clear });
    return out;
  }

  window.ecwMailSelect = { menuFor, clear, size: () => picked.size };
})();
