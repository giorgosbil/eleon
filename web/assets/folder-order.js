/**
 * Arrange the folder panel your way: drag any item (All Mail, Drafts, Inbox,
 * your folders…) to another place in its list, or between the top list and
 * "Folders" (like favourites in Outlook). Keyboard: focus an item and press
 * Alt+↑ / Alt+↓. Saved to your account (prefs nav_layout), so the order follows
 * you to every device; right-click a folder → "Reset folder order" undoes it.
 * Talks to app.js only through window.ecwMail.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const $ = (id) => document.getElementById(id);
  const lists = { system: $('systemFolders'), user: $('userFolders') };
  if (!lists.system || !lists.user) return;

  // Mark each item with its key once app.js has drawn the lists.
  function tag() {
    Object.values(lists).forEach((ul) => [...ul.children].forEach((li) => {
      const b = li.querySelector('.nav-item');
      if (!b) return;
      if (!b.dataset.navKey) b.dataset.navKey = b.dataset.folderId ? 'f:' + b.dataset.folderId : b.dataset.drafts === '1' ? 'drafts' : b.dataset.snoozed === '1' ? 'snoozed' : 'all';
      li.dataset.navKey = b.dataset.navKey;
      li.draggable = true;
    }));
  }
  const layoutNow = () => ({
    system: [...lists.system.children].map((li) => li.dataset.navKey).filter(Boolean),
    user: [...lists.user.children].map((li) => li.dataset.navKey).filter(Boolean),
  });

  /** Put the saved order back after every redraw of the folder lists. */
  function apply() {
    tag();
    const layout = M.state.prefs?.nav_layout;
    if (!layout) return;
    const all = new Map();
    Object.values(lists).forEach((ul) => [...ul.children].forEach((li) => { if (li.dataset.navKey) all.set(li.dataset.navKey, li); }));
    ['system', 'user'].forEach((name) => {
      (layout[name] || []).forEach((k) => { const li = all.get(k); if (li) { lists[name].appendChild(li); all.delete(k); } });
    });
    // Folders new since the order was saved stay where app.js put them (end of their list).
  }

  let saveTimer = null;
  function save() {
    clearTimeout(saveTimer);
    const layout = layoutNow();
    M.state.prefs = Object.assign({}, M.state.prefs, { nav_layout: layout });
    saveTimer = setTimeout(() => {
      M.api('prefs_save', {}, { method: 'POST', body: { nav_layout: layout } }).catch((e) => M.toast(e.message, true));
    }, 400);
  }

  // ------------------------------------------------------------ dragging
  let dragged = null;
  const marker = document.createElement('li');
  marker.className = 'nav-drop-marker';
  marker.setAttribute('aria-hidden', 'true');
  Object.values(lists).forEach((ul) => {
    ul.addEventListener('dragstart', (e) => {
      const li = e.target.closest('li[data-nav-key]');
      if (!li) return;
      dragged = li;
      li.classList.add('nav-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', li.dataset.navKey);
    });
    ul.addEventListener('dragend', () => { dragged?.classList.remove('nav-dragging'); dragged = null; marker.remove(); });
    ul.addEventListener('dragover', (e) => {
      if (!dragged) return;   // mail dragged onto a folder is handled by app.js
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      const over = e.target.closest('li[data-nav-key]');
      if (!over || over === dragged) { if (!ul.children.length || (!over && e.target === ul)) ul.appendChild(marker); return; }
      const r = over.getBoundingClientRect();
      ul.insertBefore(marker, e.clientY < r.top + r.height / 2 ? over : over.nextSibling);
    });
    ul.addEventListener('drop', (e) => {
      if (!dragged) return;
      e.preventDefault();
      if (marker.parentElement) marker.replaceWith(dragged); else ul.appendChild(dragged);
      dragged.classList.remove('nav-dragging');
      dragged = null;
      save();
    });
  });

  // ------------------------------------------------------------ keyboard
  document.addEventListener('keydown', (e) => {
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    const li = e.target.closest?.('#systemFolders > li, #userFolders > li');
    if (!li) return;
    e.preventDefault();
    if (e.key === 'ArrowUp') {
      if (li.previousElementSibling) li.parentElement.insertBefore(li, li.previousElementSibling);
      else if (li.parentElement === lists.user) lists.system.appendChild(li);   // up out of Folders into the top list
    } else if (li.nextElementSibling) li.parentElement.insertBefore(li.nextElementSibling, li);
    else if (li.parentElement === lists.system) lists.user.prepend(li);
    li.querySelector('.nav-item')?.focus();
    save();
  });

  // ------------------------------------------------------------ reset
  window.ecwFolderOrderReset = async () => {
    M.state.prefs = Object.assign({}, M.state.prefs);
    delete M.state.prefs.nav_layout;
    try { await M.api('prefs_save', {}, { method: 'POST', body: { nav_layout: 'reset' } }); } catch (e) { M.toast(e.message, true); return; }
    await M.reloadFolders();
    M.toast('Folder order reset');
  };

  // app.js redraws the lists (folders reloaded, unread counts…): re-apply each time.
  let applying = false;
  new MutationObserver(() => {
    if (applying || dragged) return;
    applying = true;
    try { apply(); } finally { setTimeout(() => { applying = false; }, 0); }
  }).observe(lists.system, { childList: true });
  apply();
})();
