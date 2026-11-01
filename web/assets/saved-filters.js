/**
 * Cases list: saved filters (lib/saved_filters.php, api saved_filters / saved_filter_save /
 * saved_filter_delete). Inside the Filters panel (cases.php #caseFiltersPopover): the saved ones
 * as chips at the top, one click opens the list with that filter; "Save this filter" next to
 * Apply keeps the current one under a name, for oneself or the team. The Filters button shows
 * the name of the saved filter that is on. The filter is the page's address after "?", so it
 * opens exactly as it was saved.
 *
 * The chosen filter stays chosen while the filters are changed (remembered in this tab): its chip
 * and the Filters button then show it changed, and "Update" saves the changes into it. The pencil
 * on the chosen one renames it or shares it.
 *
 * The panel's fields that make up the filter on now are lit, so one sees what a filter is made
 * of; with a chosen filter, the ones changed since it was saved are lit as changed.
 */
(function () {
  'use strict';
  const row = document.getElementById('savedFilters');
  const save = document.getElementById('savedFilterAdd');
  const panel = document.getElementById('caseFiltersPopover');
  if (!row || !save || !panel) return;
  const summary = panel.querySelector('summary');
  const base = window.ecwWebBase || '';
  const csrf = () => document.body.dataset.csrf || window.ecwCsrf || '';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', 'fa ' + cls); i.setAttribute('aria-hidden', 'true'); return i; };

  const onName = el('span', 'saved-filter-on');
  summary.appendChild(onName);
  const update = el('button', 'saved-filter-update');
  update.type = 'button'; update.hidden = true;
  save.before(update);
  let filters = [];
  let admin = false;

  // The chosen filter, for this tab: it stays chosen when the filters around it change.
  const KEY = 'ecw.savedFilter';
  let chosenId = 0;
  try { chosenId = Number(sessionStorage.getItem(KEY)) || 0; } catch (e) { /* no storage: only an exact match is chosen */ }
  const choose = (id) => { chosenId = id; try { if (id) sessionStorage.setItem(KEY, String(id)); else sessionStorage.removeItem(KEY); } catch (e) { /* see above */ } };

  async function api(action, body) {
    const url = base + 'api/index.php?action=' + action;
    const res = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Could not save the filter (' + res.status + ')');
    filters = data.filters || []; admin = !!data.admin;
    return data;
  }

  /** The address as a filter: without the page number, in a fixed order (to see which one is on). */
  const current = () => location.search.replace(/^\?/, '').split('&').filter((p) => p && !/^page=/.test(p) && !/=$/.test(p));
  const same = (q) => { const a = current().sort().join('&'); const b = q.split('&').filter(Boolean).sort().join('&'); return a === b; };
  const mayChange = (f) => f.mine || (f.shared && admin);

  /** The chosen filter and whether the list now differs from it. */
  function chosen() {
    const exact = filters.find((f) => f.id === chosenId && same(f.query)) || filters.find((f) => same(f.query));
    if (exact) return { f: exact, changed: false };
    const kept = filters.find((f) => f.id === chosenId);
    return kept ? { f: kept, changed: true } : { f: null, changed: false };
  }

  function render() {
    row.textContent = '';
    row.hidden = filters.length === 0;
    row.appendChild(el('span', 'saved-filters-label', 'Saved filters'));
    const chips = el('div', 'saved-filters-chips');
    filters.forEach((f) => {
      const chip = el('span', 'saved-filter' + (f.shared ? ' shared' : ''));
      chip.dataset.id = String(f.id);
      const a = el('a', '', f.name);
      a.href = 'cases.php' + (f.query ? '?' + f.query : '');
      a.title = f.shared ? 'Shared with the team' + (f.mine ? '' : ' by ' + f.owner_name) : 'Only you see this filter';
      a.addEventListener('click', () => choose(f.id));
      if (f.shared) a.prepend(icon('fa-user-group'), ' ');
      chip.appendChild(a);
      if (mayChange(f)) {
        const pen = el('button', 'saved-filter-edit');
        pen.type = 'button'; pen.title = 'Rename or share this filter'; pen.setAttribute('aria-label', 'Edit the saved filter ' + f.name);
        pen.appendChild(icon('fa-pen'));
        pen.addEventListener('click', () => openSave(f));
        const x = el('button', 'saved-filter-x', '×');
        x.type = 'button'; x.setAttribute('aria-label', 'Delete the saved filter ' + f.name); x.title = 'Delete this saved filter';
        x.addEventListener('click', async () => {
          if (!confirm('Delete the saved filter “' + f.name + '”' + (f.shared ? ' for everyone' : '') + '?')) return;
          try { await api('saved_filter_delete', { id: f.id }); if (chosenId === f.id) choose(0); render(); } catch (e) { alert(e.message); }
        });
        chip.append(pen, x);
      }
      chips.appendChild(chip);
    });
    row.appendChild(chips);
    if (!panel.querySelector('.case-filter-actions .saved-filter-pop')) save.hidden = false;
    mark();
  }

  const iconButton = (cls, fa, label) => {
    const b = el('button', 'saved-filter-icon ' + cls); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label);
    b.appendChild(icon(fa));
    return b;
  };

  /**
   * "Save this filter" turns into a name field in its place: [name] [team] [✓] [×]. With a saved
   * filter (the pencil): that one, renamed, shared or not, and with the filters on now.
   */
  function openSave(editing) {
    panel.querySelector('.saved-filter-pop')?.remove();
    const pop = el('form', 'saved-filter-pop');
    const name = el('input'); name.type = 'text'; name.maxLength = 80; name.placeholder = 'Name this filter'; name.required = true; name.setAttribute('aria-label', 'Name of the filter');
    const on = editing || filters.find((f) => same(f.query) && f.mine);
    if (on) name.value = on.name;
    const share = iconButton('share', 'fa-user-group', 'Share with the team');
    const paintShare = (v) => { share.setAttribute('aria-pressed', v ? 'true' : 'false'); share.title = v ? 'Shared with the team (click for only you)' : 'Only you (click to share with the team)'; };
    paintShare(!!(on && on.shared));
    share.addEventListener('click', () => paintShare(share.getAttribute('aria-pressed') !== 'true'));
    const ok = iconButton('ok', 'fa-check', editing ? 'Save the changes' : 'Save'); ok.type = 'submit';
    const cancel = iconButton('cancel', 'fa-xmark', 'Cancel');
    const close = () => { pop.remove(); save.hidden = false; mark(); save.focus(); };
    cancel.addEventListener('click', close);
    pop.append(name, share, ok, cancel);
    pop.addEventListener('submit', async (e) => {
      e.preventDefault();
      ok.disabled = true;
      // Renaming without having changed the filters keeps its filters as they were saved.
      const ch = chosen();
      const query = editing && !(ch.f && ch.f.id === editing.id) ? editing.query : current().join('&');
      try {
        const data = await api('saved_filter_save', { id: editing ? editing.id : 0, name: name.value, query, shared: share.getAttribute('aria-pressed') === 'true' });
        if (data.id) choose(data.id);
        pop.remove(); render();
      } catch (err) { alert(err.message); ok.disabled = false; }
    });
    pop.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } });
    // Editing: under the chips, by the pencil. A new one: in place of "Save this filter".
    if (editing) { save.hidden = false; row.appendChild(pop); }
    else {
      save.hidden = true; update.hidden = true;
      save.after(pop);
      const scroller = pop.closest('.case-filter-panel'); if (scroller) scroller.scrollTop = scroller.scrollHeight;
    }
    name.focus();
    name.select();
  }
  save.addEventListener('click', () => openSave());

  update.addEventListener('click', async () => {
    const { f } = chosen();
    if (!f) return;
    update.disabled = true;
    try { await api('saved_filter_save', { id: f.id, name: f.name, query: current().join('&'), shared: f.shared }); render(); }
    catch (err) { alert(err.message); }
    update.disabled = false;
  });

  // The list changes its address as filters change (assets/caseList.js): which chip is on follows,
  // and so do the name on the Filters button and the Update button.
  function mark() {
    const { f, changed } = chosen();
    row.querySelectorAll('.saved-filter').forEach((chip) => {
      const on = !!f && chip.dataset.id === String(f.id);
      chip.classList.toggle('active', on);
      chip.classList.toggle('changed', on && changed);
    });
    onName.textContent = f ? f.name + (changed ? ' •' : '') : '';
    onName.hidden = !f;
    onName.classList.toggle('changed', changed);
    summary.title = f ? 'Filters: ' + f.name + (changed ? ' (changed, not saved)' : '') : 'Filters';
    const editingNow = !!panel.querySelector('.case-filter-actions .saved-filter-pop');
    update.textContent = '';
    update.append(icon('fa-floppy-disk'), ' Update “' + (f ? f.name : '') + '”');
    update.title = 'Save the filters on now into “' + (f ? f.name : '') + '”';
    update.hidden = editingNow || !f || !changed || !mayChange(f);
    save.textContent = '';
    save.append(icon('fa-bookmark'), f && changed ? ' Save as new' : ' Save this filter');
    lightFields(f, changed);
  }
  // A field and the address part it writes (assets/caseList.js buildParams).
  const FIELDS = [
    ['caseFilterParticipant', 'participant'], ['caseFilterDatePreset', 'date_preset'], ['caseFilterDateFrom', 'date_from'],
    ['caseFilterDateTo', 'date_to'], ['caseFilterAttachments', 'has_attachments'], ['caseFilterStatus', 'status'], ['caseFilterSort', 'sort'],
  ].map(([id, key]) => [document.getElementById(id), key]).filter(([n]) => n);
  panel.querySelectorAll('[data-filter-key]').forEach((n) => FIELDS.push([n, n.dataset.filterKey]));
  const params = (q) => { const m = new Map(); new URLSearchParams(q).forEach((v, k) => { if (v !== '' && k !== 'page') m.set(k, (m.has(k) ? m.get(k) + ',' : '') + v); }); return m; };

  function lightFields(f, changed) {
    const now = params(location.search);
    const saved = f && changed ? params(f.query) : null;
    const boxes = new Map();
    const light = (box, key) => {
      if (!box) return;
      const set = now.has(key);
      const diff = !!saved && (now.get(key) || '') !== (saved.get(key) || '');
      const was = boxes.get(box) || { set: false, diff: false };
      boxes.set(box, { set: was.set || set, diff: was.diff || diff });
    };
    FIELDS.forEach(([n, key]) => light(n.closest('.case-filter-group, .case-filter-check'), key));
    panel.querySelectorAll('.case-filter-mailbox').forEach((n) => light(n.closest('.case-filter-group'), 'mailbox_ids[]'));
    panel.querySelectorAll('.case-filter-group, .case-filter-check').forEach((box) => {
      const st = boxes.get(box) || { set: false, diff: false };
      box.classList.toggle('is-set', st.set && !st.diff);
      box.classList.toggle('is-changed', st.diff);
    });
  }

  ['pushState', 'replaceState'].forEach((m) => { const orig = history[m]; history[m] = function () { const r = orig.apply(this, arguments); mark(); return r; }; });
  window.addEventListener('popstate', mark);
  // "Clear all" lets go of the chosen filter too.
  document.getElementById('caseFiltersClearAll')?.addEventListener('click', () => { choose(0); mark(); });

  api('saved_filters').then(render).catch(() => render());
})();
