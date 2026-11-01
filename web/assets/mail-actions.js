/**
 * Delete, Archive, Move to folder and Flag for a conversation — from the open
 * email's buttons, the right-click menu (mail-context.js) and the Delete key.
 * Every move offers Undo for a few seconds. Talks to app.js only through
 * window.ecwMail. Text is written with textContent.
 *
 *   window.ecwMailActions = { move(thread, target), pickFolder(thread), flag(thread, on) }
 *   thread: { id, subject, flagged } (a list row's thread object works)
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const post = (action, body) => M.api(action, {}, { method: 'POST', body });
  const rowOf = (id) => document.querySelector('.thread-row[data-thread-id="' + id + '"]');
  const current = () => (M.state.thread ? { id: M.state.threadId, subject: M.state.thread.subject, flagged: !!rowOf(M.state.threadId)?.classList.contains('flagged') } : null);

  // ---------------------------------------------------------------- undo toast
  let undoTimer = null;
  const toastEl = el('div', 'undo-toast');
  toastEl.setAttribute('role', 'status');
  toastEl.hidden = true;
  document.body.appendChild(toastEl);
  function undoToast(message, onUndo, buttonLabel) {
    clearTimeout(undoTimer);
    toastEl.replaceChildren(el('span', '', message));
    toastEl.classList.toggle('danger', buttonLabel === 'Delete');
    if (onUndo) {
      const b = el('button', '', buttonLabel || 'Undo');
      b.type = 'button';
      b.addEventListener('click', () => { toastEl.hidden = true; clearTimeout(undoTimer); onUndo(); });
      toastEl.appendChild(b);
    }
    toastEl.hidden = false;
    undoTimer = setTimeout(() => { toastEl.hidden = true; }, onUndo ? 8000 : 3000);
  }

  // ---------------------------------------------------------------- move
  const LABEL = { deleted: 'Deleted', archive: 'Archived', unarchive: 'Moved back to Inbox' };
  const inDeletedFolder = () => !!M.state.folderId && !M.state.query
    && document.querySelector('[data-folder-id="' + M.state.folderId + '"]')?.dataset.folderType === 'deleted';

  /** Delete in Deleted Items = delete for good, after one confirming click. */
  function askForever(threads, run) {
    const n = threads.length;
    undoToast('Delete ' + (n === 1 ? 'this conversation' : n + ' conversations') + ' permanently?', run, 'Delete');
  }
  async function forever(thread) {
    const li = rowOf(thread.id)?.closest('li');
    if (li) li.hidden = true;
    if (M.state.threadId === thread.id) M.showEmpty();
    try {
      const r = await post('thread_move', { thread_id: thread.id, target: 'forever' });
      if (li) li.remove();
      M.reloadFolders().catch(() => {});
      document.dispatchEvent(new CustomEvent('ecw:unread-changed'));
      undoToast(r.deleted ? 'Deleted permanently' + (r.failed ? ' (' + r.failed + ' could not be deleted)' : '') : 'Nothing here to delete.');
    } catch (e) { if (li) li.hidden = false; M.toast(e.message, true); }
  }

  /**
   * Undo: the conversation back in its place in the list (and open again, if it was), then the
   * list refreshed quietly where it is scrolled to. Before, the whole list was loaded again from
   * the top, which looked like the page reloading.
   */
  async function putBack(li, reopenId) {
    if (li && li.isConnected) li.hidden = false;
    if (reopenId) M.openThread(reopenId, { preserveScroll: true }).catch(() => {});
    await (M.refreshList || M.resetList)();
    M.reloadFolders().catch(() => {});
  }

  async function move(thread, target, folderName) {
    if (!thread || !thread.id) return;
    if (target === 'deleted' && inDeletedFolder()) { askForever([thread], () => forever(thread)); return; }
    const row = rowOf(thread.id);
    const li = row?.closest('li');
    const wasOpen = M.state.threadId === thread.id;
    // Show it gone at once; put it back if the mailbox refuses.
    // Back to Inbox (out of Archive): the conversation stays open, only its button changes.
    const keepOpen = target === 'unarchive' && wasOpen;
    if (li && !keepOpen) li.hidden = true;
    if (wasOpen && !keepOpen) M.showEmpty();
    // Back to Inbox shows at once; put back as it was if the mailbox refuses.
    const unarchived = keepOpen ? (M.state.thread?.messages || []).filter((m) => m.folder_type === 'archive') : [];
    const setFolder = (type) => { unarchived.forEach((m) => { m.folder_type = type; }); paintArchive(); };
    if (keepOpen) setFolder('inbox');
    try {
      const r = await post('thread_move', { thread_id: thread.id, target: String(target) }).catch((e) => { if (keepOpen) setFolder('archive'); throw e; });
      if (keepOpen && !r.moved.length) setFolder('archive');
      if (!r.moved.length) {
        if (li) li.hidden = false;
        // Already in Deleted Items (seen from All Mail or a search): offer to delete it for good.
        if (target === 'deleted') askForever([thread], () => forever(thread));
        else undoToast('Already in ' + (r.to_name || 'that folder') + '.');
        return;
      }
      const stays = M.state.folderId && r.to && Number(M.state.folderId) === Number(r.to);
      if (li && stays) li.hidden = false;   // gone from the list: kept hidden, so Undo puts it back in its place
      M.reloadFolders().catch(() => {});
      document.dispatchEvent(new CustomEvent('ecw:unread-changed'));
      const what = LABEL[target] || 'Moved to ' + (folderName || r.to_name || 'folder');
      undoToast(what + (r.failed ? ' (' + r.failed + ' could not be moved)' : ''), async () => {
        try {
          await post('messages_restore', { moves: r.moved });
          if (keepOpen && M.state.threadId === thread.id) setFolder('archive');
          await putBack(li, wasOpen && !keepOpen ? thread.id : null);
          document.dispatchEvent(new CustomEvent('ecw:unread-changed'));
          undoToast('Moved back');
        } catch (e) { M.toast(e.message, true); }
      });
    } catch (e) {
      if (li) li.hidden = false;
      M.toast(e.message, true);
    }
  }

  // ------------------------------------------------------- folder picker
  const picker = el('div', 'folder-picker');
  picker.setAttribute('role', 'dialog');
  picker.setAttribute('aria-label', 'Move to folder');
  picker.hidden = true;
  const pickTitle = el('strong', '', 'Move to');
  const pickSearch = el('input');
  pickSearch.type = 'search';
  pickSearch.placeholder = 'Find a folder…';
  pickSearch.setAttribute('aria-label', 'Find a folder');
  const pickList = el('ul', 'folder-picker-list');
  pickList.setAttribute('role', 'listbox');
  picker.append(pickTitle, pickSearch, pickList);
  document.body.appendChild(picker);
  let pickThread = null, folders = [];
  const ICON = { inbox: 'fa-inbox', deleted: 'fa-trash-can', archive: 'fa-box-archive', custom: 'fa-folder' };

  function drawFolders() {
    const q = pickSearch.value.trim().toLowerCase();
    pickList.replaceChildren();
    folders.filter((f) => !['sent', 'drafts'].includes(f.folder_type) && Number(f.id) !== Number(M.state.folderId) && (!q || f.name.toLowerCase().includes(q)))
      .forEach((f, i) => {
        const li = el('li');
        li.setAttribute('role', 'option');
        const b = el('button');
        b.type = 'button';
        const icon = el('i', 'fa ' + (ICON[f.folder_type] || 'fa-folder'));
        icon.setAttribute('aria-hidden', 'true');
        b.append(icon, el('span', '', f.name));
        b.addEventListener('click', () => { const cb = pickCallback, t = pickThread; closePicker(); if (cb) cb(f); else move(t, String(f.id), f.name); });
        if (i === 0) b.classList.add('active');
        li.appendChild(b);
        pickList.appendChild(li);
      });
    if (!pickList.children.length) pickList.appendChild(el('li', 'muted folder-picker-empty', 'No folder matches.'));
  }
  let pickCallback = null;
  /** onPick(folder): used by multi-select to move several conversations at once. */
  async function pickFolder(thread, anchor, onPick) {
    pickThread = thread;
    pickCallback = onPick || null;
    pickTitle.textContent = thread.count > 1 ? 'Move ' + thread.count + ' conversations to' : 'Move “' + (thread.subject || 'conversation').slice(0, 60) + '” to';
    picker.hidden = false;
    const r = (anchor || $('threadMoveBtn'))?.getBoundingClientRect();
    const x = r && r.width ? r.left : window.innerWidth / 2 - 150, y = r && r.height ? r.bottom + 6 : 120;
    picker.style.left = Math.max(8, Math.min(x, window.innerWidth - 320)) + 'px';
    picker.style.top = Math.max(8, Math.min(y, window.innerHeight - 380)) + 'px';
    pickSearch.value = '';
    pickList.replaceChildren(el('li', 'muted folder-picker-empty', 'Loading folders…'));
    pickSearch.focus();
    try { folders = (await M.api('folders', {})).folders || []; drawFolders(); }
    catch (e) { pickList.replaceChildren(el('li', 'muted folder-picker-empty', e.message)); }
  }
  function closePicker() { picker.hidden = true; pickThread = null; pickCallback = null; }
  pickSearch.addEventListener('input', drawFolders);
  pickSearch.addEventListener('keydown', (e) => {
    const items = [...pickList.querySelectorAll('button')];
    let i = items.findIndex((b) => b.classList.contains('active'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      items[i]?.classList.remove('active');
      i = (i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items[i]?.classList.add('active'); items[i]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') { e.preventDefault(); items[Math.max(0, i)]?.click(); }
    else if (e.key === 'Escape') closePicker();
  });
  document.addEventListener('pointerdown', (e) => { if (!picker.hidden && !picker.contains(e.target) && !e.target.closest('#threadMoveBtn')) closePicker(); }, true);

  // ---------------------------------------------------------------- flag
  async function flag(thread, on) {
    const row = rowOf(thread.id);
    row?.classList.toggle('flagged', on);
    if (row?._thread) row._thread.flagged = on;
    if (M.state.thread && M.state.threadId === thread.id && !on) M.state.thread.messages.forEach((m) => { m.is_flagged = false; });
    if (M.state.thread && M.state.threadId === thread.id && on && !row) { const last = M.state.thread.messages.at(-1); if (last) last.is_flagged = true; }
    paintFlag();
    try { await post('thread_flag', { thread_id: thread.id, flagged: on }); }
    catch (e) { row?.classList.toggle('flagged', !on); paintFlag(); M.toast(e.message, true); }
  }
  function paintFlag() {
    const b = $('threadFlagBtn');
    if (!b || !M.state.threadId) return;
    const row = rowOf(M.state.threadId);
    const on = row ? row.classList.contains('flagged') : !!M.state.thread?.messages?.some((m) => m.is_flagged);
    b.setAttribute('aria-pressed', String(on));
    b.classList.toggle('on', on);
    b.querySelector('i').className = (on ? 'fa' : 'fa-regular') + ' fa-flag';
    b.title = on ? 'Clear flag' : 'Flag';
  }
  document.addEventListener('ecw:thread-open', () => setTimeout(paintFlag, 0));

  /** In Archive: some of its emails are there and none is in the Inbox (our replies stay in Sent). */
  function isArchived() {
    const ms = (M.state.thread?.messages || []).filter((m) => !m.removed);
    return ms.some((m) => m.folder_type === 'archive') && !ms.some((m) => m.folder_type === 'inbox');
  }
  function paintArchive() {
    const b = $('threadArchiveBtn');
    if (!b) return;
    const on = !!M.state.threadId && isArchived();
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', String(on));
    b.title = on ? 'In Archive — click to move it back to Inbox' : 'Archive';
    b.setAttribute('aria-label', on ? 'In Archive. Move back to Inbox' : 'Archive');
    b.querySelector('i').className = 'fa ' + (on ? 'fa-box-open' : 'fa-box-archive');
  }
  document.addEventListener('ecw:thread-open', () => setTimeout(paintArchive, 0));

  // ------------------------------------------------------------ buttons, keys
  // Archive, or (the conversation is in Archive) back to Inbox.
  $('threadArchiveBtn')?.addEventListener('click', () => move(current(), isArchived() ? 'unarchive' : 'archive'));
  $('threadDeleteBtn')?.addEventListener('click', () => move(current(), 'deleted'));
  $('threadMoveBtn')?.addEventListener('click', (e) => { const t = current(); if (t) pickFolder(t, e.currentTarget); });
  $('threadFlagBtn')?.addEventListener('click', () => { const t = current(); if (t) flag(t, $('threadFlagBtn').getAttribute('aria-pressed') !== 'true'); });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Delete' || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.target.closest('input, textarea, select, [contenteditable="true"], .composer-window, dialog') || !$('composerOverlay').hidden) return;
    const focusedRow = e.target.closest?.('.thread-row');
    const t = focusedRow?._thread ? { id: focusedRow._thread.id, subject: focusedRow._thread.subject } : current();
    if (!t) return;
    e.preventDefault();
    move(t, 'deleted');
  });

  // ---------------------------------------------------------------- junk
  /** Report as junk (block=true: also Block sender) or Not junk (not=true). */
  async function junk(thread, opts) {
    opts = opts || {};
    const li = rowOf(thread.id)?.closest('li');
    const wasOpen = M.state.threadId === thread.id;
    if (li) li.hidden = true;
    if (wasOpen) M.showEmpty();
    try {
      const r = await post('thread_junk', { thread_id: thread.id, block: !!opts.block, not: !!opts.not });
      M.reloadFolders().catch(() => {});
      const msg = opts.not ? 'Moved to Inbox — sender unblocked' : (r.blocked ? 'Blocked ' + r.blocked + ' — moved to Junk' : 'Moved to Junk');
      undoToast(msg, r.moved?.length ? async () => {
        try {
          await post('messages_restore', { moves: r.moved });
          if (r.blocked) await post('sender_unblock', { pattern: r.blocked });
          await putBack(li, wasOpen ? thread.id : null);
          undoToast('Undone');
        } catch (e) { M.toast(e.message, true); }
      } : null);
    } catch (e) { if (li) li.hidden = false; M.toast(e.message, true); }
  }
  const inJunkFolder = () => /junk|spam|ανεπιθ/i.test(document.querySelector('.nav-item.active')?.textContent || '');

  window.ecwMailActions = { move, pickFolder, flag, junk, inJunkFolder, undoToast, inDeletedFolder, askForever, putBack };
})();
