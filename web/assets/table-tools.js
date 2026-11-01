/**
 * Column menus on tables, like Excel / Outlook: a small ▾ next to each column
 * name opens Sort (A → Z, newest first…) and Filter.
 *
 *   window.ecwColumnMenu(th, () => [{ label, checked, onPick }, '-', { heading: 'Show' }, …])
 *     a menu whose items the page decides (the Cases table sorts and filters
 *     on the server, assets/caseList.js).
 *
 * Every other data table (Users, Audit log, Reports, Response times, Security,
 * AI usage) gets it by itself: sorting and filtering the rows shown, in the
 * browser. A table opts out with data-no-column-menu. Text is written with
 * textContent.
 */
(function () {
  'use strict';
  if (window.ecwColumnMenu) return;
  let openMenu = null;

  function closeMenu() {
    if (!openMenu) return;
    openMenu.menu.remove();
    openMenu.btn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('pointerdown', openMenu.onDoc, true);
    document.removeEventListener('keydown', openMenu.onKey, true);
    window.removeEventListener('scroll', openMenu.onScroll, true);
    openMenu = null;
  }

  function showMenu(btn, items) {
    closeMenu();
    const menu = document.createElement('div');
    menu.className = 'col-menu';
    menu.setAttribute('role', 'menu');
    items.forEach((it) => {
      if (it === '-') { const hr = document.createElement('div'); hr.className = 'col-menu-sep'; menu.appendChild(hr); return; }
      if (it.heading) { const h = document.createElement('div'); h.className = 'col-menu-head'; h.textContent = it.heading; menu.appendChild(h); return; }
      if (it.input) {
        const wrap = document.createElement('div'); wrap.className = 'col-menu-input';
        const inp = document.createElement('input'); inp.type = 'search'; inp.placeholder = it.placeholder || 'Contains…'; inp.value = it.value || '';
        inp.addEventListener('input', () => it.onInput(inp.value));
        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); closeMenu(); } });
        wrap.appendChild(inp); menu.appendChild(wrap);
        setTimeout(() => inp.focus({ preventScroll: true }), 0);
        return;
      }
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'menuitemradio');
      b.setAttribute('aria-checked', it.checked ? 'true' : 'false');
      b.className = 'col-menu-item' + (it.checked ? ' is-on' : '');
      if (it.icon) { const i = document.createElement('i'); i.className = 'fa ' + it.icon; i.setAttribute('aria-hidden', 'true'); b.appendChild(i); }
      b.appendChild(document.createTextNode(it.label));
      b.addEventListener('click', (e) => { e.stopPropagation(); closeMenu(); it.onPick(); });
      menu.appendChild(b);
    });
    document.body.appendChild(menu);
    const r = btn.getBoundingClientRect();
    const w = menu.offsetWidth;
    menu.style.top = Math.round(r.bottom + 4) + 'px';
    menu.style.left = Math.round(Math.max(8, Math.min(window.innerWidth - w - 8, r.left - 6))) + 'px';
    btn.setAttribute('aria-expanded', 'true');
    const onDoc = (e) => { if (!menu.contains(e.target) && e.target !== btn) closeMenu(); };
    const onKey = (e) => { if (e.key === 'Escape') { closeMenu(); btn.focus(); } };
    const opened = Date.now();
    const onScroll = (e) => { if (Date.now() - opened > 300 && !menu.contains(e.target)) closeMenu(); };
    setTimeout(() => document.addEventListener('pointerdown', onDoc, true), 0);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('scroll', onScroll, true);
    openMenu = { btn, menu, onDoc, onKey, onScroll };
    (menu.querySelector('input') || menu.querySelector('.is-on') || menu.querySelector('button'))?.focus({ preventScroll: true });
  }

  /** Put a ▾ menu button in a header cell. @param getItems () => items (built when opened) */
  window.ecwColumnMenu = function (th, getItems) {
    if (th.querySelector('.col-menu-btn')) return th.querySelector('.col-menu-btn');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'col-menu-btn';
    btn.setAttribute('aria-haspopup', 'true');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-label', 'Sort and filter ' + (th.dataset.label || th.textContent.trim()));
    btn.title = 'Sort and filter';
    btn.innerHTML = '<i class="fa fa-chevron-down" aria-hidden="true"></i>';
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (openMenu && openMenu.btn === btn) { closeMenu(); return; }
      showMenu(btn, getItems());
    });
    th.classList.add('has-col-menu');
    th.appendChild(btn);
    return btn;
  };

  // ------------------------------------------------ any other data table

  const SELECTOR = 'table.grid, table.aging-table, table.sec-table, table.ai-usage-table';

  /** What a cell sorts by: a number, a date, or its text. */
  function key(cell) {
    const raw = (cell?.dataset.sort ?? cell?.textContent ?? '').trim();
    if (raw === '' || raw === '—' || raw === '-') return { empty: true };
    const num = raw.replace(/[\s,%€$]/g, '');
    if (/^-?\d+(\.\d+)?$/.test(num)) return { n: parseFloat(num) };
    const t = Date.parse(raw.replace(' UTC', 'Z').replace(/^(\d{4}-\d{2}-\d{2}) /, '$1T'));
    if (!isNaN(t) && /\d{4}|\d{1,2}[\/.]\d{1,2}/.test(raw)) return { n: t };
    return { s: raw.toLocaleLowerCase() };
  }

  function enhance(table) {
    if (table.dataset.columnMenu || table.dataset.noColumnMenu !== undefined || table.id === 'caseTable') return;
    const head = table.tHead?.rows[table.tHead.rows.length - 1];
    if (!head) return;
    table.dataset.columnMenu = '1';
    const filters = new Map();   // column index → text
    let sorted = null;           // { col, dir }
    const body = () => table.tBodies[0];
    const apply = () => {
      [...(body()?.rows || [])].forEach((tr) => {
        let show = true;
        filters.forEach((text, col) => { if (text && !(tr.cells[col]?.textContent || '').toLocaleLowerCase().includes(text.toLocaleLowerCase())) show = false; });
        tr.hidden = !show;
      });
      [...head.cells].forEach((th, i) => {
        th.classList.toggle('is-filtered', !!filters.get(i));
        th.classList.toggle('is-sorted', !!sorted && sorted.col === i);
        th.dataset.sortDir = sorted && sorted.col === i ? sorted.dir : '';
      });
    };
    const sortBy = (col, dir) => {
      const tb = body();
      if (!tb) return;
      sorted = { col, dir };
      const rows = [...tb.rows];
      rows.sort((a, b) => {
        const x = key(a.cells[col]), y = key(b.cells[col]);
        if (x.empty || y.empty) return x.empty === y.empty ? 0 : x.empty ? 1 : -1;   // empty cells last
        const c = x.n !== undefined && y.n !== undefined ? x.n - y.n : String(x.s ?? x.n).localeCompare(String(y.s ?? y.n), undefined, { numeric: true });
        return dir === 'asc' ? c : -c;
      });
      rows.forEach((r) => tb.appendChild(r));
      apply();
    };
    [...head.cells].forEach((th, col) => {
      const label = th.textContent.trim();
      if (!label || th.colSpan > 1) return;
      th.dataset.label = th.dataset.label || label;
      window.ecwColumnMenu(th, () => {
        const sample = [...(body()?.rows || [])].map((r) => key(r.cells[col])).find((k) => !k.empty) || {};
        const numeric = sample.n !== undefined;
        return [
          { heading: 'Sort' },
          { label: numeric ? 'Smallest / oldest first' : 'A → Z', icon: 'fa-arrow-up-short-wide', checked: sorted?.col === col && sorted.dir === 'asc', onPick: () => sortBy(col, 'asc') },
          { label: numeric ? 'Largest / newest first' : 'Z → A', icon: 'fa-arrow-down-wide-short', checked: sorted?.col === col && sorted.dir === 'desc', onPick: () => sortBy(col, 'desc') },
          '-',
          { heading: 'Filter' },
          { input: true, placeholder: 'Show rows containing…', value: filters.get(col) || '', onInput: (v) => { filters.set(col, v); apply(); } },
          ...(filters.get(col) ? [{ label: 'Clear the filter', icon: 'fa-xmark', onPick: () => { filters.delete(col); apply(); } }] : []),
        ];
      });
    });
  }

  const scan = (root) => (root.matches?.(SELECTOR) ? [root] : [...(root.querySelectorAll?.(SELECTOR) || [])]).forEach(enhance);
  const start = () => {
    scan(document);
    new MutationObserver((muts) => muts.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType !== 1) return; scan(n); const t = n.closest?.(SELECTOR); if (t) enhance(t); }))).observe(document.body, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
