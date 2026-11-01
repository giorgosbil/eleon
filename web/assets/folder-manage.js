/**
 * Folders from the app: "+" next to Folders makes a new one; right-click (or
 * long-press, or the Menu key) on a folder offers New subfolder, Rename and
 * Delete. Done in Outlook / Gmail first (api folder_*), then the list reloads.
 * Talks to app.js only through window.ecwMail. Text is written with textContent.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const post = (action, body) => M.api(action, {}, { method: 'POST', body });

  // ------------------------------------------------------- name box (new / rename)
  const box = el('form', 'folder-name-box');
  box.hidden = true;
  box.setAttribute('role', 'dialog');
  const boxTitle = el('strong', '', 'New folder');
  const input = el('input');
  input.type = 'text'; input.maxLength = 100; input.required = true; input.setAttribute('aria-label', 'Folder name');
  const row = el('div', 'folder-name-row');
  const ok = el('button', 'primary', 'Create'); ok.type = 'submit';
  const cancel = el('button', '', 'Cancel'); cancel.type = 'button';
  const status = el('p', 'muted small');
  row.append(ok, cancel);
  box.append(boxTitle, input, row, status);
  document.body.appendChild(box);
  let submit = null;
  function ask(title, value, button, anchor, run) {
    boxTitle.textContent = title;
    input.value = value || '';
    ok.textContent = button;
    status.textContent = '';
    submit = run;
    box.hidden = false;
    const r = anchor?.getBoundingClientRect();
    box.style.left = Math.max(8, Math.min((r ? r.left : 40), window.innerWidth - 300)) + 'px';
    box.style.top = Math.max(8, Math.min((r ? r.bottom + 6 : 120), window.innerHeight - 160)) + 'px';
    input.focus(); input.select();
  }
  const closeBox = () => { box.hidden = true; submit = null; };
  cancel.addEventListener('click', closeBox);
  box.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeBox(); });
  box.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!submit || !input.value.trim()) return;
    ok.disabled = true;
    try { await submit(input.value.trim()); closeBox(); }
    catch (err) { status.textContent = err.message; }
    finally { ok.disabled = false; }
  });
  document.addEventListener('pointerdown', (e) => { if (!box.hidden && !box.contains(e.target)) closeBox(); }, true);

  async function create(name, parentId) {
    const r = await post('folder_create', { name, parent_id: parentId || 0 });
    await M.reloadFolders();
    M.toast('Folder “' + (r.folder?.name || name) + '” created — it is in Outlook too');
  }

  // ------------------------------------------------------- empty Deleted Items / Junk Email
  // The server deletes up to 200 per call (20 per request at Outlook, lib/folder_actions.php), so a folder of
  // thousands is emptied in rounds, with the progress in a toast that stays until done.
  let emptying = false;
  async function emptyFolder(id, name) {
    if (emptying) return;
    emptying = true;
    const note = el('div', 'toast page-toast');
    note.setAttribute('role', 'status');
    const text = el('span', '', 'Emptying ' + name + '…');
    note.appendChild(text);
    document.body.appendChild(note);
    let done = 0, held = 0;
    try {
      let idle = 0;
      for (;;) {
        const r = await post('folder_empty', { folder_id: id });
        done += r.deleted; held = r.held;
        text.textContent = 'Emptying ' + name + '… ' + done + (r.remaining ? ' of ' + (done + r.remaining) : '');
        if (!r.remaining) break;
        // Outlook asked to slow down: wait a little and go on (three times at most in a row).
        if (!r.deleted) { if (++idle > 3) break; await new Promise((ok) => setTimeout(ok, 3000)); } else idle = 0;
      }
      note.remove();
      M.toast(done ? done + ' email' + (done === 1 ? '' : 's') + ' deleted for good from ' + name + (held ? ' · ' + held + ' kept (case on legal hold)' : '')
        : (held ? 'Nothing deleted: the ' + held + ' left are in a case on legal hold' : name + ' was already empty'));
    } catch (e) {
      note.remove();
      M.toast((done ? done + ' deleted, then: ' : '') + e.message, true);
    } finally {
      emptying = false;
      await M.reloadFolders();
      if (M.state.folderId === id) M.resetList();
    }
  }

  // ------------------------------------------------------- menu
  const menu = el('div', 'ctx-menu');
  menu.setAttribute('role', 'menu');
  menu.hidden = true;
  document.body.appendChild(menu);
  const closeMenu = () => { menu.hidden = true; menu.textContent = ''; };
  document.addEventListener('pointerdown', (e) => { if (!menu.hidden && !menu.contains(e.target)) closeMenu(); }, true);
  window.addEventListener('blur', closeMenu);

  function item(icon, label, run, danger) {
    const b = el('button', 'ctx-item' + (danger ? ' danger' : ''));
    b.type = 'button';
    b.setAttribute('role', 'menuitem');
    const i = el('i', 'fa ' + icon); i.setAttribute('aria-hidden', 'true');
    const span = el('span', '', label);
    b.append(i, span);
    b.addEventListener('click', () => run(b, span));
    return b;
  }

  function openMenu(btn, x, y) {
    const id = Number(btn.dataset.folderId);
    const name = btn.dataset.folderName || btn.textContent.trim();
    const own = btn.dataset.system !== '1' && btn.dataset.folderType === 'custom';
    const canNest = !['sent', 'drafts'].includes(btn.dataset.folderType);
    menu.textContent = '';
    if (canNest) menu.appendChild(item('fa-folder-plus', 'New subfolder…', () => { closeMenu(); ask('New folder inside “' + name + '”', '', 'Create', btn, (v) => create(v, id)); }));
    if (own) {
      menu.appendChild(item('fa-pen', 'Rename…', () => {
        closeMenu();
        ask('Rename “' + name + '”', name, 'Rename', btn, async (v) => { await post('folder_rename', { folder_id: id, name: v }); await M.reloadFolders(); M.toast('Renamed — in Outlook too'); });
      }));
      menu.appendChild(item('fa-trash-can', 'Delete folder', async (b, span) => {
        if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; span.textContent = 'Click again — its mail goes to Deleted Items'; return; }
        closeMenu();
        try {
          const r = await post('folder_delete', { folder_id: id });
          if (M.state.folderId === id) document.querySelector('#systemFolders .nav-item')?.click();
          await M.reloadFolders();
          M.toast('Folder deleted' + (r.emails ? ' (' + r.emails + ' email' + (r.emails === 1 ? '' : 's') + ' with it)' : ''));
        } catch (e) { M.toast(e.message, true); }
      }, true));
    }
    if (['deleted', 'junk'].includes(btn.dataset.folderType)) {
      const empty = item('fa-broom', 'Empty folder…', () => {
        window.ecwChoice(empty, [
          { label: 'Delete every email in it for good', danger: true, onPick: () => { closeMenu(); emptyFolder(id, name); } },
          { label: 'Cancel', onPick: () => {} },
        ], 10000);
      }, true);
      empty.title = 'Delete every email in ' + name + ' for good, like Outlook. Emails in a case on legal hold stay.';
      menu.appendChild(empty);
    }
    if (window.ecwFolderOrderReset && M.state.prefs?.nav_layout) {
      menu.appendChild(item('fa-arrow-rotate-left', 'Reset folder order', () => { closeMenu(); window.ecwFolderOrderReset(); }));
    }
    if (!menu.children.length) return;
    menu.hidden = false;
    const r = menu.getBoundingClientRect();
    menu.style.left = Math.max(6, Math.min(x, window.innerWidth - r.width - 6)) + 'px';
    menu.style.top = Math.max(6, Math.min(y, window.innerHeight - r.height - 6)) + 'px';
    menu.querySelector('.ctx-item')?.focus({ preventScroll: true });
  }

  const nav = $('navPane') || document;
  nav.addEventListener('contextmenu', (e) => {
    const btn = e.target.closest('.nav-item[data-folder-id]');
    if (!btn) return;
    e.preventDefault();
    const r = btn.getBoundingClientRect();
    openMenu(btn, e.clientX || r.left + 30, e.clientY || r.bottom);
  });
  nav.addEventListener('keydown', (e) => {
    if (e.key !== 'ContextMenu' && !(e.key === 'F10' && e.shiftKey)) return;
    const btn = e.target.closest('.nav-item[data-folder-id]');
    if (!btn) return;
    e.preventDefault();
    const r = btn.getBoundingClientRect();
    openMenu(btn, r.left + 30, r.bottom);
  });
  menu.addEventListener('keydown', (e) => {
    const all = [...menu.querySelectorAll('.ctx-item')];
    const i = all.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); all[(i + (e.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length].focus(); }
    else if (e.key === 'Escape') closeMenu();
  });

  $('newFolderBtn')?.addEventListener('click', (e) => { e.stopPropagation(); ask('New folder', '', 'Create', e.currentTarget, (v) => create(v, null)); });
})();
