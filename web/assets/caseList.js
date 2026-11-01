/**
 * Email Case Workspace — case list filters (task 06).
 *
 * Progressive enhancement over web/cases.php's server-rendered table: the
 * page works as a plain GET form with no JS at all (every control here has
 * a real name/value an SSR reload already understands), and this file turns
 * it into a debounced, URL-synced filter bar without a second copy of the
 * filtering rules — every fetch hits the same case_list action, backed by
 * the same lib/cases.php:ecw_case_list() the initial page render used.
 *
 * XSS POSTURE: every value written from a fetched case row uses textContent.
 * Case names and owner names are user-entered data, never HTML.
 */

(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);

  const bootstrapEl = $('caseListBootstrap');
  if (!bootstrapEl) {
    return;
  }
  const bootstrap = JSON.parse(bootstrapEl.textContent || '{}');

  const DEFAULT_FILTERS = {
    status: 'open', q: '', mailbox_ids: [], participant: '',
    date_preset: '', date_from: '', date_to: '', has_attachments: '', group_ids: [], tag: '',
    sort: 'last_activity', dir: '', view: '',
    owner: 0, handler: 0, waiting: '', due: '', due_from: '', due_to: '', created_from: '', created_to: '', file: '', unread: '',
  };

  // The filters panel's other fields (cases.php: data-filter-key): each one's value is
  // state.filters[key], its URL parameter has the same name, and it shows as a chip.
  const ADV = [...document.querySelectorAll('#caseFiltersPopover [data-filter-key]')];
  const advValue = (el) => (el.type === 'checkbox' ? (el.checked ? el.value : '') : el.value.trim());
  const neutral = (v) => v === '' || v === 0 || v === '0' || v === null || v === undefined;

  const state = {
    filters: Object.assign({}, DEFAULT_FILTERS, bootstrap.filters || {}),
    page: bootstrap.page || 1,
    csrf: document.body ? document.body.dataset.csrf : undefined,
  };

  let debounceTimer = null;
  let inFlight = null;
  /** Cases ticked for a bulk action (kept across pages and filter changes until cleared). */
  const selected = new Set();
  /** The rows on screen, by id: the row menu and the bulk bar read them. */
  const rowsById = new Map();
  let lastFetched = false;

  // ------------------------------------------------------------------- api

  async function api(action, params, options) {
    options = options || {};
    const url = new URL('api/index.php', window.location.href);
    url.searchParams.set('action', action);
    Object.keys(params || {}).forEach((k) => {
      if (params[k] !== null && params[k] !== undefined && params[k] !== '') {
        url.searchParams.set(k, String(params[k]));
      }
    });

    const init = { method: options.method || 'GET', headers: {} };
    if (init.method !== 'GET') {
      init.headers['X-ECW-CSRF'] = state.csrf;
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body || {});
    }

    const response = await fetch(url.toString(), init);
    const text = await response.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: 'bad response' }; }
    if (!response.ok) throw new Error(data.error || ('request failed (' + response.status + ')'));
    return data;
  }

  /** action: optional { label, onClick }, shown ~8s instead of the default ~2.6s. */
  function toast(message, isError, action) {
    const el = document.getElementById('toast');
    const actionBtn = document.getElementById('toastAction');
    document.getElementById('toastMsg').textContent = message;
    el.classList.toggle('error', !!isError);
    el.hidden = false;
    if (action) {
      actionBtn.textContent = action.label;
      actionBtn.hidden = false;
      actionBtn.onclick = () => { el.hidden = true; action.onClick(); };
    } else {
      actionBtn.hidden = true;
      actionBtn.onclick = null;
    }
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.hidden = true; }, action ? 8000 : (isError ? 6000 : 2600));
  }

  // ------------------------------------------------------------- URL sync

  /** Only non-neutral values go into the URL, so a fresh "Clear all" is a clean link. */
  function buildParams(filters, page) {
    const params = new URLSearchParams();
    if (filters.status && filters.status !== 'open') params.set('status', filters.status);
    else if (filters.status === 'all') params.set('status', 'all');
    if (filters.q) params.set('q', filters.q);
    (filters.mailbox_ids || []).forEach((id) => params.append('mailbox_ids[]', String(id)));
    if (filters.participant) params.set('participant', filters.participant);
    if (filters.date_preset) params.set('date_preset', filters.date_preset);
    if (filters.date_preset === 'custom') {
      if (filters.date_from) params.set('date_from', filters.date_from);
      if (filters.date_to) params.set('date_to', filters.date_to);
    }
    if (filters.has_attachments) params.set('has_attachments', filters.has_attachments);
    (filters.group_ids || []).forEach((id) => params.append('group_ids[]', String(id)));
    if (filters.tag) params.set('tag', filters.tag);
    if (filters.view) params.set('view', filters.view);
    ADV.forEach((el) => { const k = el.dataset.filterKey; if (k !== 'tag' && !neutral(filters[k])) params.set(k, String(filters[k])); });
    if (filters.sort && filters.sort !== 'last_activity') params.set('sort', filters.sort);
    if (filters.dir) params.set('dir', filters.dir);
    if (page && page > 1) params.set('page', String(page));
    return params;
  }

  function syncUrl(push) {
    const params = buildParams(state.filters, state.page);
    const qs = params.toString();
    const url = window.location.pathname + (qs ? '?' + qs : '');
    if (push) {
      window.history.pushState({ filters: state.filters, page: state.page }, '', url);
    } else {
      window.history.replaceState({ filters: state.filters, page: state.page }, '', url);
    }
  }

  // -------------------------------------------------------------- fetching

  window.ecwCaseListRefresh = () => fetchAndRender(false);   // case-preview.js, after Close / Waiting
  async function fetchAndRender(push) {
    if (inFlight) {
      inFlight.abort();
    }
    const controller = new AbortController();
    inFlight = controller;

    const params = buildParams(state.filters, state.page);
    params.set('action', 'case_list');
    const url = new URL('api/index.php', window.location.href);
    url.search = params.toString();

    let data;
    try {
      const response = await fetch(url.toString(), { signal: controller.signal });
      data = await response.json();
    } catch (e) {
      if (e.name === 'AbortError') return; // superseded by a newer request
      return;
    }
    if (controller !== inFlight) return; // a newer request already landed
    inFlight = null;

    state.page = data.page || 1;
    lastFetched = true;
    if (data.views) renderViews(data.views);
    renderTable(data.cases || []);
    renderCount(data.total || 0);
    renderPagination(data.total || 0, data.per_page || 50);
    renderChips();
    syncUrl(push);
  }

  function scheduleFetch(push) {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => fetchAndRender(!!push), 250);
  }

  // -------------------------------------------------------------- render

  function cell(text) {
    const td = document.createElement('td');
    td.textContent = text;
    return td;
  }

  /** The reply clock (lib/sla.php): "3.5 h left", amber when nearly due, red when overdue. */
  function slaPill(s) {
    const span = document.createElement('span');
    span.className = 'sla-pill ' + s.state;
    const abs = Math.abs(s.left_seconds);
    const t = abs < 3600 ? Math.max(1, Math.round(abs / 60)) + ' min' : abs < 172800 ? (abs / 3600).toFixed(1).replace(/\.0$/, '') + ' h' : (abs / 86400).toFixed(1).replace(/\.0$/, '') + ' d';
    const i = document.createElement('i');
    i.className = 'fa fa-stopwatch';
    i.setAttribute('aria-hidden', 'true');
    span.append(i, ' ' + (s.state === 'overdue' ? 'overdue ' + t : t + ' left'));
    span.title = 'Waiting for our reply since ' + new Date(String(s.waiting_since).replace(' ', 'T') + 'Z').toLocaleString() + ' · target ' + s.target_hours + ' business hours';
    return span;
  }

  /** Rebuilds a name cell as a plain link — the resting state, before/after a rename edit. */
  /** A tag in its colour (Settings → Case settings → Tags). */
  function paintTag(chip, tag) {
    const color = ((window.ecwCaseSettings || {}).tag_colors || {})[String(tag).toLowerCase()];
    if (!color) return;
    chip.style.background = 'color-mix(in srgb, ' + color + ' 14%, var(--bg))';
    chip.style.color = color;
  }

  /** Due date: red when overdue, amber when due today (closed cases are never late). */
  function dueCell(due, status) {
    const td = document.createElement('td');
    if (!due) { td.textContent = '—'; td.className = 'muted'; return td; }
    const today = new Date(); const key = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    const d = new Date(due + 'T00:00:00');
    td.textContent = d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    td.title = d.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    // Overdue after the company's grace days (Settings → Case settings).
    const grace = Number((window.ecwCaseSettings || {}).due_grace_days) || 0;
    const late = new Date(due + 'T00:00:00'); late.setDate(late.getDate() + grace);
    const lateKey = late.getFullYear() + '-' + String(late.getMonth() + 1).padStart(2, '0') + '-' + String(late.getDate()).padStart(2, '0');
    if (status !== 'closed') td.className = lateKey < key ? 'due-late' : due <= key ? 'due-today' : '';
    return td;
  }

  /** "just now", "12 min ago", "3 h ago", "Yesterday", "Mon 5 Oct", "5 Oct 2025". */
  function relativeTime(utc) {
    if (!utc) return '—';
    const d = new Date(String(utc).replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return String(utc);
    const s = (Date.now() - d.getTime()) / 1000;
    if (s < 90) return 'just now';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    if (d >= today) return Math.round(s / 3600) + ' h ago';
    const yesterday = new Date(today.getTime() - 86400000);
    if (d >= yesterday) return 'Yesterday';
    if (s < 6 * 86400) return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    return d.toLocaleDateString([], d.getFullYear() === today.getFullYear() ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' });
  }

  /** A person, "You" for oneself. */
  function personCell(name, id) {
    const td = cell(!name ? '—' : (Number(id) === Number(bootstrap.me) ? 'You' : name));
    if (!name) { td.className = 'muted'; return td; }
    td.title = name;
    const p = (bootstrap.people || []).find((x) => Number(x.id) === Number(id));
    if (p && window.ecwAvatar) { td.classList.add('person-cell'); td.prepend(window.ecwAvatar(p, 20)); }
    return td;
  }

  function nameCell(caseId, name) {
    const td = document.createElement('td');
    td.className = 'case-name-cell';
    const a = document.createElement('a');
    a.href = 'case.php?id=' + encodeURIComponent(String(caseId));
    a.textContent = name;
    td.appendChild(a);
    return td;
  }

  // Shared shape with case.php's CASE_SETTINGS_ACTIONS in assets/case.js and
  // web/cases.php's $caseRowActions — one array, three places, so a new
  // action is one line in each.
  /** The row menu, built for the row's case: close or reopen, people, date, group, rename, delete. */
  function rowActions(c) {
    const list = [{ key: 'open', label: 'Open the case' }];
    if (c.status === 'closed') list.push({ key: 'reopen', label: 'Reopen' });
    else {
      list.push({ key: 'close', label: 'Close' });
      if (c.status !== 'waiting') list.push({ key: 'waiting', label: 'Mark as waiting for the customer' });
      else list.push({ key: 'reopen', label: 'Back to open' });
    }
    list.push({ key: 'owner', label: 'Responsible…' });
    if (bootstrap.handler) list.push({ key: 'handler', label: 'Handled by…' });
    if (bootstrap.due) list.push({ key: 'due', label: 'Due date…' });
    if ((bootstrap.groups || []).length) list.push({ key: 'group', label: 'Move to group…' });
    list.push({ key: 'rename', label: 'Rename' });
    list.push({ key: 'delete', label: 'Delete', danger: true });
    return list;
  }
  const CASE_ROW_ACTIONS = [
    { key: 'rename', label: 'Rename' },
    { key: 'delete', label: 'Delete', danger: true },
  ];

  function actionsCell(c) {
    const td = document.createElement('td');
    td.className = 'case-actions-col';
    const details = document.createElement('details');
    details.className = 'menu case-settings-menu';
    const summary = document.createElement('summary');
    summary.setAttribute('aria-label', 'Case settings');
    summary.title = 'Case settings';
    summary.textContent = '⋮';
    const body = document.createElement('div');
    body.className = 'menu-body';
    (c ? rowActions(c) : CASE_ROW_ACTIONS).forEach((action) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'menu-item' + (action.danger ? ' danger' : '');
      btn.dataset.caseAction = action.key;
      btn.textContent = action.label;
      body.appendChild(btn);
    });
    details.appendChild(summary);
    details.appendChild(body);
    td.appendChild(details);
    return td;
  }

  /** Group badge plus a lock for members-only cases. Text via textContent only. */
  function groupCell(c) {
    const td = document.createElement('td');
    if (c.group_name) {
      const badge = document.createElement('span');
      badge.className = 'group-badge';
      if (c.group_color) badge.style.setProperty('--group-color', c.group_color);
      badge.textContent = c.group_name;
      td.appendChild(badge);
    }
    if (c.visibility === 'members') {
      const lock = document.createElement('i');
      lock.className = 'fa fa-lock muted';
      lock.title = 'Only selected people can see this case';
      lock.style.marginLeft = '6px';
      td.appendChild(lock);
    }
    return td;
  }

  function toggleGroup(ids, id) {
    if (!id) return [];
    const list = (ids || []).map(Number);
    return list.includes(id) ? list.filter((g) => g !== id) : list.concat(id);
  }

  function syncGroupTabs() {
    document.querySelectorAll('.case-group-tab').forEach((tab) => {
      const id = Number(tab.dataset.groupId);
      const chosen = state.filters.group_ids || [];
      const active = id === 0 ? chosen.length === 0 : chosen.includes(id);
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-pressed', String(active));
    });
  }

  document.querySelectorAll('.case-group-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      // A group tab adds or removes that group (several can be chosen); "All groups" clears them.
      state.filters.group_ids = toggleGroup(state.filters.group_ids, Number(tab.dataset.groupId) || 0);
      state.page = 1;
      syncGroupTabs();
      fetchAndRender(true);
    });
  });

  function renderTable(cases) {
    const container = $('caseListContainer');
    if (cases.length === 0) {
      container.innerHTML = '';
      const p = document.createElement('p');
      p.className = 'muted';
      p.id = 'caseEmptyState';
      p.textContent = 'No cases match these filters. ';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'caseEmptyClear';
      btn.className = 'button';
      btn.textContent = 'Clear filters';
      btn.addEventListener('click', clearAll);
      p.appendChild(btn);
      container.appendChild(p);
      return;
    }

    const table = document.createElement('table');
    table.className = 'grid';
    table.id = 'caseTable';
    const labels = ['', 'Case', 'Name'].concat(bootstrap.next ? ['Next step'] : [], ['Group', 'Status', 'Responsible'], bootstrap.handler ? ['Handled by'] : [], ['Due', 'Emails', 'Created', 'Last activity', '']);
    const thead = document.createElement('thead');
    const htr = document.createElement('tr');
    labels.forEach((l, i) => {
      const th = document.createElement('th');
      if (i === 0) {
        th.className = 'case-check-col';
        const all = document.createElement('input');
        all.type = 'checkbox'; all.id = 'caseSelectAll'; all.setAttribute('aria-label', 'Select all cases on this page');
        all.checked = cases.length > 0 && cases.every((c) => selected.has(c.id));
        th.appendChild(all);
      } else th.textContent = l;
      th.dataset.label = l;
      htr.appendChild(th);
      columnMenu(th, l);
    });
    thead.appendChild(htr);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    tbody.id = 'caseTableBody';
    rowsById.clear();

    cases.forEach((c) => {
      rowsById.set(c.id, c);
      const tr = document.createElement('tr');
      tr.dataset.caseId = String(c.id);
      tr.dataset.caseName = c.name;
      tr.dataset.caseThreads = String(c.thread_count);
      if (selected.has(c.id)) tr.classList.add('selected');

      const checkTd = document.createElement('td');
      checkTd.className = 'case-check-col';
      const box = document.createElement('input');
      box.type = 'checkbox'; box.className = 'case-select'; box.checked = selected.has(c.id);
      box.setAttribute('aria-label', 'Select ' + c.case_number);
      checkTd.appendChild(box);
      tr.appendChild(checkTd);

      const caseTd = document.createElement('td');
      const caseLink = document.createElement('a');
      caseLink.className = 'case-badge';
      caseLink.href = 'case.php?id=' + encodeURIComponent(String(c.id));
      caseLink.textContent = c.case_number;
      caseTd.appendChild(caseLink);
      if (c.unread_count > 0) {
        tr.classList.add('has-unread');
        const pill = document.createElement('span');
        pill.className = 'unread-pill';
        pill.textContent = String(c.unread_count);
        pill.title = c.unread_count + ' unread email' + (c.unread_count === 1 ? '' : 's');
        caseTd.append(' ', pill);
      }
      tr.appendChild(caseTd);

      const nameTd = nameCell(c.id, c.name);
      if (c.checklist && c.checklist.total) {
        // Checklist progress (lib/checklists.php): "3/5", green when all done.
        const cl = document.createElement('a');
        cl.className = 'cl-chip' + (c.checklist.done === c.checklist.total ? ' all' : '');
        cl.href = 'case.php?id=' + encodeURIComponent(String(c.id)) + '#checklist';
        cl.title = 'Checklist: ' + c.checklist.done + ' of ' + c.checklist.total + ' steps done';
        const ic = document.createElement('i'); ic.className = 'fa fa-list-check'; ic.setAttribute('aria-hidden', 'true');
        cl.append(ic, ' ' + c.checklist.done + '/' + c.checklist.total);
        nameTd.appendChild(cl);
      }
      (c.tags || []).forEach((t) => {
        const chip = document.createElement('a');
        chip.className = 'tag-chip';
        chip.textContent = t;
        paintTag(chip, t);
        chip.href = 'cases.php?tag=' + encodeURIComponent(t) + '&status=all';
        chip.title = 'Show cases tagged “' + t + '”';
        chip.addEventListener('click', (e) => { e.preventDefault(); state.filters.tag = t; state.filters.status = 'all'; state.page = 1; syncUrl(true); fetchAndRender(false); });
        nameTd.appendChild(chip);
      });
      tr.appendChild(nameTd);
      if (bootstrap.next) tr.appendChild(nextCell(c));

      tr.appendChild(groupCell(c));
      const statusTd = cell(c.status_label || STATUS_LABELS[c.status] || c.status);
      statusTd.className = 'case-status case-status-' + c.status;
      // The company's own status in its colour (Settings → Case settings).
      if (c.sub_status && c.status_color) statusTd.style.color = c.status_color;
      if (c.waiting_overdue) {
        const pill = document.createElement('span'); pill.className = 'sla-pill overdue'; pill.textContent = 'Waiting too long';
        pill.title = 'In Waiting for more than ' + (window.ecwCaseSettings || {}).waiting_overdue_days + ' days';
        statusTd.append(' ', pill);
      }
      if (c.sla) statusTd.append(' ', slaPill(c.sla));
      tr.appendChild(statusTd);
      tr.appendChild(personCell(c.owner_name, c.owner_user_id));
      if (bootstrap.handler) tr.appendChild(personCell(c.handler_name, c.handler_user_id));
      tr.appendChild(dueCell(c.due_at, c.status));
      tr.appendChild(cell(String(c.thread_count)));

      // When the case was opened: how old it is, next to how recently it moved.
      const created = new Date(String(c.created_at || '').replace(' ', 'T') + 'Z');
      const createdTd = cell(isNaN(created) ? '—' : created.toLocaleDateString([], created.getFullYear() === new Date().getFullYear() ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' }));
      if (!isNaN(created)) createdTd.title = 'Opened ' + created.toLocaleString();
      createdTd.classList.add('case-created');
      tr.appendChild(createdTd);

      // "3 h ago" / "Yesterday"; "—" for a case without emails yet (Created says when it was made).
      const lastTd = cell(c.last_activity ? relativeTime(c.last_activity) : '—');
      if (c.last_activity) lastTd.title = new Date(String(c.last_activity).replace(' ', 'T') + 'Z').toLocaleString();
      else lastTd.className = 'muted';
      tr.appendChild(lastTd);

      tr.appendChild(actionsCell(c));
      // Column names for the phone layout, where each row is a card ("Responsible: Ann").
      labels.forEach((label, i) => { if (tr.children[i]) tr.children[i].dataset.label = label; });

      tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.innerHTML = '';
    container.appendChild(table);
    updateBulkBar();
    document.dispatchEvent(new CustomEvent('ecw:cases-rendered'));   // case-preview.js marks the open case again
  }

  function renderCount(total) {
    const el = $('caseFilterCount');
    if (el) el.textContent = total + ' case' + (total === 1 ? '' : 's');
  }

  function renderPagination(total, perPage) {
    const el = $('casePagination');
    if (!el) return;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    if (totalPages <= 1) {
      el.innerHTML = '';
      return;
    }
    el.innerHTML = '';

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.id = 'casePagePrev';
    prev.textContent = '‹ Prev';
    prev.disabled = state.page <= 1;
    prev.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); fetchAndRender(true); });
    el.appendChild(prev);

    const label = document.createElement('span');
    label.className = 'muted';
    label.id = 'casePaginationLabel';
    label.textContent = 'Page ' + state.page + ' of ' + totalPages;
    el.appendChild(label);

    const next = document.createElement('button');
    next.type = 'button';
    next.id = 'casePageNext';
    next.textContent = 'Next ›';
    next.disabled = state.page >= totalPages;
    next.addEventListener('click', () => { state.page = Math.min(totalPages, state.page + 1); fetchAndRender(true); });
    el.appendChild(next);
  }

  const STATUS_LABELS = { open: 'Open', waiting: 'Waiting', closed: 'Closed', all: 'All' };
  // The company's own statuses too (Settings → Case settings).
  ((window.ecwCaseSettings || {}).statuses || []).forEach((o) => { STATUS_LABELS[o.value] = o.label; });
  const DATE_LABELS = { today: 'Today', '7d': 'Last 7 days', '30d': 'Last 30 days', custom: 'Custom range' };
  const ATTACH_LABELS = { yes: 'Has attachments', no: 'No attachments' };

  function mailboxLabel(id) {
    const cb = document.querySelector('.case-filter-mailbox[value="' + id + '"]');
    return cb ? cb.closest('label').textContent.trim() : ('Mailbox ' + id);
  }

  function chipList() {
    const f = state.filters;
    const chips = [];
    if (f.status && f.status !== 'all') {
      chips.push({ key: 'status', label: 'Status: ' + (STATUS_LABELS[f.status] || f.status) });
    }
    (f.mailbox_ids || []).forEach((id) => {
      chips.push({ key: 'mailbox:' + id, label: 'Mailbox: ' + mailboxLabel(id) });
    });
    if (f.participant) {
      chips.push({ key: 'participant', label: 'Participant: ' + f.participant });
    }
    if (f.date_preset) {
      const label = f.date_preset === 'custom'
        ? 'Last activity: ' + (f.date_from || '…') + ' – ' + (f.date_to || '…')
        : 'Last activity: ' + (DATE_LABELS[f.date_preset] || f.date_preset);
      chips.push({ key: 'date', label: label });
    }
    if (f.has_attachments) {
      chips.push({ key: 'has_attachments', label: ATTACH_LABELS[f.has_attachments] || f.has_attachments });
    }
    if (f.q) {
      chips.push({ key: 'q', label: 'Search: ' + f.q });
    }
    if (f.tag) {
      chips.push({ key: 'tag', label: 'Tag: ' + f.tag });
    }
    ADV.forEach((el) => {
      const k = el.dataset.filterKey;
      if (k === 'tag' || neutral(f[k])) return;
      const shown = el.tagName === 'SELECT' ? (el.querySelector('option[value="' + CSS.escape(String(f[k])) + '"]')?.textContent || f[k])
        : el.type === 'checkbox' ? '' : f[k];
      chips.push({ key: 'adv:' + k, label: el.dataset.filterLabel + (shown ? ': ' + shown : '') });
    });
    return chips;
  }

  function renderChips() {
    const el = $('caseFilterChips');
    if (!el) return;
    const chips = chipList();
    el.innerHTML = '';
    el.hidden = chips.length === 0;
    chips.forEach((chip) => {
      const span = document.createElement('span');
      span.className = 'chip case-filter-chip';
      span.dataset.chipKey = chip.key;
      span.appendChild(document.createTextNode(chip.label + ' '));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'chip-remove';
      btn.setAttribute('aria-label', 'Remove filter');
      btn.textContent = '×';
      span.appendChild(btn);
      el.appendChild(span);
    });
  }

  // --------------------------------------------------------- filter state

  function removeChip(key) {
    if (key === 'tag') {
      state.filters.tag = '';
    } else if (key === 'status') {
      state.filters.status = 'all';
    } else if (key.indexOf('mailbox:') === 0) {
      const id = Number(key.slice('mailbox:'.length));
      state.filters.mailbox_ids = state.filters.mailbox_ids.filter((m) => m !== id);
      const cb = document.querySelector('.case-filter-mailbox[value="' + id + '"]');
      if (cb) cb.checked = false;
    } else if (key === 'participant') {
      state.filters.participant = '';
      $('caseFilterParticipant').value = '';
    } else if (key === 'date') {
      state.filters.date_preset = '';
      state.filters.date_from = '';
      state.filters.date_to = '';
      $('caseFilterDatePreset').value = '';
      $('caseFilterDateFrom').value = '';
      $('caseFilterDateTo').value = '';
      $('caseFilterDateRange').hidden = true;
    } else if (key === 'has_attachments') {
      state.filters.has_attachments = '';
      $('caseFilterAttachments').value = '';
    } else if (key === 'q') {
      state.filters.q = '';
      $('caseSearchInput').value = '';
    } else if (key.indexOf('adv:') === 0) {
      const k = key.slice(4);
      state.filters[k] = DEFAULT_FILTERS[k];
    }
    syncSelects();
    state.page = 1;
    fetchAndRender(true);
  }

  function clearAll() {
    state.filters = Object.assign({}, DEFAULT_FILTERS, { status: 'all', view: '' });
    state.page = 1;
    syncSelects();
    fetchAndRender(true);
  }

  /** Push the current filter state back into every control — used after a
   *  chip removal, Clear all, or a browser back/forward navigation. */
  /**
   * ▾ next to a column name (table-tools.js): sort the whole list by it on the
   * server, and for Status / Group / Due the filters that go with them.
   */
  const COLUMN_SORT = { 'Case': ['number', 'Highest number first', 'Lowest number first', 'desc'], 'Name': ['name', 'A → Z', 'Z → A', 'asc'],
    'Next step': ['next', 'A → Z', 'Z → A', 'asc'], 'Group': ['group', 'A → Z', 'Z → A', 'asc'], 'Status': ['status', 'A → Z', 'Z → A', 'asc'],
    'Responsible': ['owner', 'A → Z', 'Z → A', 'asc'], 'Handled by': ['handler', 'A → Z', 'Z → A', 'asc'], 'Due': ['due', 'Soonest first', 'Latest first', 'asc'],
    'Emails': ['threads', 'Most first', 'Fewest first', 'desc'], 'Created': ['created', 'Newest first', 'Oldest first', 'desc'], 'Last activity': ['last_activity', 'Newest first', 'Oldest first', 'desc'] };
  function columnMenu(th, label) {
    const def = COLUMN_SORT[label];
    if (!def || !window.ecwColumnMenu) return;
    const [key, firstLabel, secondLabel, firstDir] = def;
    const secondDir = firstDir === 'asc' ? 'desc' : 'asc';
    const f = state.filters;
    const current = f.sort === key ? (f.dir || ({ number: 'desc', threads: 'desc', last_activity: 'desc', created: 'desc' }[key] || 'asc')) : '';
    if (current) { th.classList.add('is-sorted'); th.dataset.sortDir = current; }
    if ((label === 'Status' && f.status && f.status !== 'all' && f.status !== 'open') || (label === 'Group' && (f.group_ids || []).length > 0)
      || (label === 'Due' && ['overdue', 'week'].includes(f.view))) th.classList.add('is-filtered');
    const go = (patch) => { Object.assign(state.filters, patch); state.page = 1; syncSelects(); fetchAndRender(true); };
    window.ecwColumnMenu(th, () => {
      const items = [{ heading: 'Sort' },
        { label: firstLabel, icon: 'fa-arrow-down-wide-short', checked: current === firstDir, onPick: () => go({ sort: key, dir: firstDir }) },
        { label: secondLabel, icon: 'fa-arrow-up-short-wide', checked: current === secondDir, onPick: () => go({ sort: key, dir: secondDir }) }];
      if (label === 'Status') {
        items.push('-', { heading: 'Show' });
        // The three groups, each with the company's own statuses under it.
        const opts = ((window.ecwCaseSettings || {}).statuses || []).map((o) => [o.value, (o.custom ? ' ' : '') + o.label]);
        (opts.length ? opts : [['open', 'Open'], ['waiting', 'Waiting'], ['closed', 'Closed']]).concat([['all', 'All']]).forEach(([v, t]) =>
          items.push({ label: t, checked: f.status === v && !f.view, onPick: () => go({ status: v, view: '' }) }));
      } else if (label === 'Group') {
        items.push('-', { heading: 'Show' }, { label: 'All groups', checked: !(f.group_ids || []).length, onPick: () => go({ group_ids: [] }) });
        // Each group adds or removes itself: several can be shown together.
        (bootstrap.groups || []).forEach((g) => items.push({ label: g.name, checked: (f.group_ids || []).includes(g.id), onPick: () => go({ group_ids: toggleGroup(f.group_ids, g.id) }) }));
      } else if (label === 'Due') {
        items.push('-', { heading: 'Show' },
          { label: 'Any due date', checked: !['overdue', 'week'].includes(f.view), onPick: () => go({ view: '', status: 'open' }) },
          { label: 'Overdue', checked: f.view === 'overdue', onPick: () => go({ view: 'overdue', status: 'all' }) },
          { label: 'Due this week', checked: f.view === 'week', onPick: () => go({ view: 'week', status: 'all' }) });
      } else if (label === 'Responsible') {
        items.push('-', { heading: 'Show' },
          { label: 'Everyone’s', checked: !['mine', 'unassigned'].includes(f.view), onPick: () => go({ view: '', status: 'open' }) },
          { label: 'Mine', checked: f.view === 'mine', onPick: () => go({ view: 'mine', status: 'all' }) },
          { label: 'Nobody yet', checked: f.view === 'unassigned', onPick: () => go({ view: 'unassigned', status: 'all' }) });
      }
      return items;
    });
  }

  function syncSelects() {
    const f = state.filters;
    $('caseSearchInput').value = f.q;
    $('caseFilterParticipant').value = f.participant;
    $('caseFilterDatePreset').value = f.date_preset;
    $('caseFilterDateFrom').value = f.date_from;
    $('caseFilterDateTo').value = f.date_to;
    $('caseFilterDateRange').hidden = f.date_preset !== 'custom';
    $('caseFilterAttachments').value = f.has_attachments;
    $('caseFilterStatus').value = f.status;
    syncGroupTabs();
    $('caseFilterSort').value = f.sort;
    document.querySelectorAll('.case-filter-mailbox').forEach((cb) => {
      cb.checked = f.mailbox_ids.indexOf(Number(cb.value)) !== -1;
    });
    ADV.forEach((el) => {
      const v = f[el.dataset.filterKey];
      if (el.type === 'checkbox') el.checked = !neutral(v) && String(v) === el.value;
      else el.value = neutral(v) ? (el.tagName === 'SELECT' && el.querySelector('option[value="0"]') ? '0' : '') : String(v);
    });
  }

  ADV.forEach((el) => {
    const k = el.dataset.filterKey;
    const set = () => { const v = advValue(el); state.filters[k] = typeof DEFAULT_FILTERS[k] === 'number' ? (Number(v) || 0) : v; state.page = 1; };
    if (el.type === 'text') el.addEventListener('input', () => { set(); scheduleFetch(false); });
    else el.addEventListener('change', () => { set(); fetchAndRender(true); });
  });

  // ------------------------------------------------------------- wiring

  const searchInput = $('caseSearchInput');
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      state.filters.q = searchInput.value.trim();
      state.page = 1;
      scheduleFetch(false);
    });
  }

  const participantInput = $('caseFilterParticipant');
  if (participantInput) {
    participantInput.addEventListener('input', () => {
      state.filters.participant = participantInput.value.trim();
      state.page = 1;
      scheduleFetch(false);
    });
  }

  const datePreset = $('caseFilterDatePreset');
  if (datePreset) {
    datePreset.addEventListener('change', () => {
      state.filters.date_preset = datePreset.value;
      $('caseFilterDateRange').hidden = datePreset.value !== 'custom';
      state.page = 1;
      if (datePreset.value !== 'custom') {
        fetchAndRender(true);
      }
    });
  }

  ['caseFilterDateFrom', 'caseFilterDateTo'].forEach((id) => {
    const input = $(id);
    if (!input) return;
    input.addEventListener('change', () => {
      state.filters.date_from = $('caseFilterDateFrom').value;
      state.filters.date_to = $('caseFilterDateTo').value;
      state.page = 1;
      fetchAndRender(true);
    });
  });

  const attachmentsSelect = $('caseFilterAttachments');
  if (attachmentsSelect) {
    attachmentsSelect.addEventListener('change', () => {
      state.filters.has_attachments = attachmentsSelect.value;
      state.page = 1;
      fetchAndRender(true);
    });
  }

  const statusSelect = $('caseFilterStatus');
  if (statusSelect) {
    statusSelect.addEventListener('change', () => {
      state.filters.status = statusSelect.value;
      state.page = 1;
      fetchAndRender(true);
    });
  }

  const sortSelect = $('caseFilterSort');
  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      state.filters.sort = sortSelect.value;
      state.filters.dir = '';   // each sort's own direction
      state.page = 1;
      fetchAndRender(true);
    });
  }

  document.querySelectorAll('.case-filter-mailbox').forEach((cb) => {
    cb.addEventListener('change', () => {
      const id = Number(cb.value);
      const ids = new Set(state.filters.mailbox_ids);
      if (cb.checked) ids.add(id); else ids.delete(id);
      state.filters.mailbox_ids = Array.from(ids);
      state.page = 1;
      fetchAndRender(true);
    });
  });

  const applyBtn = $('caseFiltersApply');
  if (applyBtn) {
    applyBtn.addEventListener('click', () => {
      const popover = $('caseFiltersPopover');
      if (popover) popover.removeAttribute('open');
      fetchAndRender(true);
    });
  }

  const clearAllBtn = $('caseFiltersClearAll');
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', clearAll);
  }

  const emptyClearBtn = $('caseEmptyClear');
  if (emptyClearBtn) {
    emptyClearBtn.addEventListener('click', clearAll);
  }

  document.addEventListener('click', (e) => {
    const removeBtn = e.target.closest('.chip-remove');
    if (!removeBtn) return;
    const chip = removeBtn.closest('.case-filter-chip');
    if (!chip) return;
    removeChip(chip.dataset.chipKey);
  });

  const prevBtn = $('casePagePrev');
  if (prevBtn) prevBtn.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); fetchAndRender(true); });
  const nextBtn = $('casePageNext');
  if (nextBtn) nextBtn.addEventListener('click', () => { state.page = state.page + 1; fetchAndRender(true); });

  // ----------------------------------------------------- rename (task 07)

  function startRowRename(tr) {
    const nameTd = tr.querySelector('.case-name-cell');
    const originalName = tr.dataset.caseName;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'case-name-edit';
    input.value = originalName;
    nameTd.innerHTML = '';
    nameTd.appendChild(input);
    input.focus();
    input.select();

    let settled = false;

    async function commit() {
      if (settled) return;
      settled = true;
      const name = input.value.trim();
      if (name === originalName) {
        nameTd.replaceWith(nameCell(tr.dataset.caseId, originalName));
        return;
      }
      if (name.length < 1 || name.length > 120) {
        toast(name.length < 1 ? 'Case name cannot be empty' : 'Case name must be 120 characters or fewer', true);
        nameTd.replaceWith(nameCell(tr.dataset.caseId, originalName));
        return;
      }

      nameTd.replaceWith(nameCell(tr.dataset.caseId, name)); // optimistic
      try {
        const data = await api('case_rename', { case_id: tr.dataset.caseId }, { method: 'POST', body: { name: name } });
        tr.dataset.caseName = data.name;
        toast('Case renamed');
      } catch (e) {
        tr.dataset.caseName = originalName;
        tr.querySelector('.case-name-cell')?.replaceWith(nameCell(tr.dataset.caseId, originalName)); // rollback
        toast(e.message, true);
      }
    }

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') {
        settled = true;
        nameTd.replaceWith(nameCell(tr.dataset.caseId, originalName));
      }
    });
    input.addEventListener('blur', () => { void commit(); });
  }

  // ----------------------------------------------------- delete (task 07)

  let pendingDelete = null;

  function openDeleteDialog(tr) {
    pendingDelete = tr;
    const threads = Number(tr.dataset.caseThreads);
    document.getElementById('deleteCaseBody').textContent = 'Delete "' + tr.dataset.caseName + '"? It contains '
      + threads + ' thread' + (threads === 1 ? '' : 's') + '.';
    document.getElementById('deleteCaseOverlay').hidden = false;
  }

  async function restoreCase(caseId, name) {
    try {
      await api('case_restore', { case_id: caseId }, { method: 'POST' });
      toast('Case restored');
      fetchAndRender(false);
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function confirmDelete() {
    const tr = pendingDelete;
    pendingDelete = null;
    document.getElementById('deleteCaseOverlay').hidden = true;
    if (!tr) return;

    const caseId = tr.dataset.caseId;
    const name = tr.dataset.caseName;
    try {
      await api('case_delete', { case_id: caseId }, { method: 'POST' });
    } catch (e) {
      toast(e.message, true);
      return;
    }

    // Re-fetch rather than hand-remove the row: the server already excludes
    // the deleted case, so this gets the table, count, and pagination back
    // in one consistent state instead of three separately-patched ones.
    fetchAndRender(false);
    toast('Case deleted', false, { label: 'Undo', onClick: () => restoreCase(caseId, name) });
  }

  document.getElementById('deleteCaseCancel')?.addEventListener('click', () => {
    pendingDelete = null;
    document.getElementById('deleteCaseOverlay').hidden = true;
  });
  document.getElementById('deleteCaseConfirm')?.addEventListener('click', () => { void confirmDelete(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const overlay = document.getElementById('deleteCaseOverlay');
      if (overlay && !overlay.hidden) { pendingDelete = null; overlay.hidden = true; }
    }
  });

  document.addEventListener('click', (e) => {
    const actionBtn = e.target.closest('[data-case-action]');
    if (!actionBtn) return;
    const tr = actionBtn.closest('tr');
    if (!tr) return;
    const menu = actionBtn.closest('details.menu');
    if (menu) menu.removeAttribute('open');
    const key = actionBtn.dataset.caseAction;
    if (key === 'rename') startRowRename(tr);
    else if (key === 'delete') openDeleteDialog(tr);
    else rowAction(key, Number(tr.dataset.caseId), actionBtn.closest('td') || tr);
  });

  // A case deleted from case.php (the case being viewed) redirects here
  // rather than showing its own toast, so the Undo has somewhere to land.
  (function checkCrossPageUndo() {
    let raw;
    try { raw = window.sessionStorage.getItem('ecw.caseDeleted'); } catch (e) { return; }
    if (!raw) return;
    window.sessionStorage.removeItem('ecw.caseDeleted');
    let flag;
    try { flag = JSON.parse(raw); } catch (e) { return; }
    const remaining = (flag.expiresAt || 0) - Date.now();
    if (remaining <= 0) return;
    toast('Case deleted', false, { label: 'Undo', onClick: () => restoreCase(flag.caseId, flag.name) });
  })();

  // ------------------------------------------------------------ next step
  // What happens next (lib/next_actions.php): the step, who it waits on, the look-again day.
  const WAITING = { us: 'Us', customer: 'Customer', supplier: 'Supplier', internal: 'Colleague' };
  function nextCell(c) {
    const td = document.createElement('td');
    const n = c.next || {};
    const wrap = document.createElement('div');
    wrap.className = 'cl-next';
    const text = document.createElement('a');
    text.href = 'case.php?id=' + encodeURIComponent(String(c.id));
    if (n.action) {
      text.className = 'cl-next-text';
      text.textContent = n.action;
      text.title = n.action + (n.user_name ? ' — ' + n.user_name : '') + (n.by === 'ai' ? ' (filled in by the AI)' : '');
    } else {
      text.className = 'cl-next-text none';
      text.textContent = c.status === 'closed' ? '—' : 'No next step';
    }
    wrap.appendChild(text);
    const meta = document.createElement('div');
    meta.className = 'cl-next-meta';
    if (n.waiting_on && c.status !== 'closed') {
      const w = document.createElement('span');
      w.className = 'cl-wait ' + n.waiting_on;
      w.textContent = n.waiting_on === 'us' ? (n.user_name ? n.user_name.split(' ')[0] : 'Us') : 'Waiting: ' + WAITING[n.waiting_on];
      w.title = n.waiting_on === 'us' ? 'Our turn' : 'Waiting on the ' + WAITING[n.waiting_on].toLowerCase();
      meta.appendChild(w);
    }
    if (n.review_on && c.status !== 'closed') {
      const r = document.createElement('span');
      const today = new Date(); const key = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      r.className = 'cl-review' + (n.review_on <= key ? ' late' : '');
      r.textContent = n.review_on <= key ? (n.review_on === key ? 'Look today' : 'Look: ' + shortDay(n.review_on)) : 'Look ' + shortDay(n.review_on);
      r.title = 'Look at it again on ' + new Date(n.review_on + 'T00:00:00').toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
      meta.appendChild(r);
    }
    if (n.by === 'ai' && n.action) {
      const ai = document.createElement('span'); ai.className = 'cl-ai'; ai.textContent = 'AI'; ai.title = 'Filled in by the AI from the emails';
      meta.appendChild(ai);
    }
    if (meta.childElementCount) wrap.appendChild(meta);
    td.appendChild(wrap);
    // ✨ the AI review of the whole case (case-review.js), for open cases.
    const review = c.status !== 'closed' ? window.ecwCaseReview?.button(c.id, { onApplied: () => fetchAndRender(false) }) : null;
    if (review) {
      // Before the next step, on its line.
      const row = document.createElement('div');
      row.className = 'cl-next-row';
      td.classList.add('cl-next-cell');
      td.replaceChild(row, wrap);
      row.append(review, wrap);
    }
    return td;
  }
  function shortDay(ymd) { return new Date(ymd + 'T00:00:00').toLocaleDateString([], { day: 'numeric', month: 'short' }); }

  // ------------------------------------------------------------ quick views
  // All open · My cases · Unassigned · Overdue · Due this week · Waiting for the customer.

  function activeView() {
    if (state.filters.view) return state.filters.view;
    return ['all', 'open', 'waiting'].includes(state.filters.status) ? state.filters.status : null;
  }
  function renderViews(counts) {
    const now = activeView();
    document.querySelectorAll('#caseViews .case-view').forEach((b) => {
      const on = b.dataset.view === now;
      b.classList.toggle('active', on);
      b.setAttribute('aria-pressed', String(on));
      const n = b.querySelector('.case-view-count');
      if (counts && n && counts[b.dataset.view] !== undefined) n.textContent = String(counts[b.dataset.view]);
      if ((b.dataset.view === 'overdue' || b.dataset.view === 'review') && counts) b.classList.toggle('alert', (counts[b.dataset.view] || 0) > 0);
    });
  }
  document.querySelectorAll('#caseViews .case-view').forEach((b) => b.addEventListener('click', () => {
    const v = b.dataset.view;
    // All / Open / Waiting are statuses; the others are views (which leave closed cases out by themselves).
    if (['all', 'open', 'waiting'].includes(v)) { state.filters.view = ''; state.filters.status = v; }
    else { state.filters.view = v; state.filters.status = 'all'; }
    state.page = 1;
    syncSelects();
    renderViews(null);
    fetchAndRender(true);
  }));

  // ------------------------------------------------------------ pickers
  // A small floating list (a person, a group) or a date, next to the button pressed.

  let picker = null;
  function closePicker() { if (picker) { picker.remove(); picker = null; } }
  document.addEventListener('mousedown', (e) => { if (picker && !picker.contains(e.target)) closePicker(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePicker(); });

  function placePicker(el, anchor) {
    document.body.appendChild(el);
    const r = anchor.getBoundingClientRect();
    const w = el.offsetWidth;
    el.style.top = (window.scrollY + r.bottom + 4) + 'px';
    el.style.left = Math.max(8, Math.min(window.scrollX + r.right - w, window.scrollX + window.innerWidth - w - 8)) + 'px';
  }

  /** options: [{ value, label }] — onPick(value). */
  function pickFrom(anchor, title, options, onPick) {
    closePicker();
    picker = document.createElement('div');
    picker.className = 'case-picker';
    picker.setAttribute('role', 'menu');
    const h = document.createElement('div'); h.className = 'case-picker-title'; h.textContent = title;
    picker.appendChild(h);
    if (options.length > 7) {
      const f = document.createElement('input'); f.type = 'search'; f.placeholder = 'Find…'; f.className = 'case-picker-find';
      f.addEventListener('input', () => { const q = f.value.toLowerCase(); picker.querySelectorAll('.case-picker-item').forEach((b) => { b.hidden = !b.textContent.toLowerCase().includes(q); }); });
      picker.appendChild(f);
    }
    options.forEach((o) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'case-picker-item' + (o.muted ? ' muted' : '') + (o.current ? ' current' : ''); b.setAttribute('role', 'menuitem');
      // A person: their photo (or coloured initials), then the name.
      if (o.person && window.ecwAvatar) b.appendChild(window.ecwAvatar(o.person, 22));
      else if (o.person === null) { const none = document.createElement('span'); none.className = 'case-picker-none'; none.setAttribute('aria-hidden', 'true'); b.appendChild(none); }
      b.appendChild(document.createTextNode(o.label));
      b.addEventListener('click', () => { closePicker(); onPick(o.value); });
      picker.appendChild(b);
    });
    placePicker(picker, anchor);
    (picker.querySelector('input') || picker.querySelector('button'))?.focus();
  }

  function pickDate(anchor, title, current, onPick) {
    closePicker();
    picker = document.createElement('div');
    picker.className = 'case-picker';
    const h = document.createElement('div'); h.className = 'case-picker-title'; h.textContent = title;
    const input = document.createElement('input'); input.type = 'date'; input.value = current || '';
    const row = document.createElement('div'); row.className = 'case-picker-row';
    const quick = (label, days) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.addEventListener('click', () => { const d = new Date(); d.setDate(d.getDate() + days); input.value = localDay(d); }); return b; };   // today here, not in UTC
    const localDay = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    row.append(quick('Today', 0), quick('+1 week', 7), quick('+2 weeks', 14));
    const save = document.createElement('button'); save.type = 'button'; save.className = 'primary'; save.textContent = 'Set';
    save.addEventListener('click', () => { closePicker(); onPick(input.value); });
    const clear = document.createElement('button'); clear.type = 'button'; clear.textContent = 'No date';
    clear.addEventListener('click', () => { closePicker(); onPick(''); });
    const foot = document.createElement('div'); foot.className = 'case-picker-row'; foot.append(clear, save);
    picker.append(h, input, row, foot);
    placePicker(picker, anchor);
    input.focus();
  }

  const peopleOptions = (currentId) => [{ value: '', label: 'Nobody', muted: true, person: null, current: !currentId }].concat((bootstrap.people || []).map((p) => ({
    value: p.id, label: Number(p.id) === Number(bootstrap.me) ? p.name + ' (you)' : p.name, person: p, current: Number(currentId) === Number(p.id),
  })));
  const personById = (id) => (bootstrap.people || []).find((p) => Number(p.id) === Number(id)) || null;
  const groupOptions = () => [{ value: 0, label: 'No group', muted: true }].concat((bootstrap.groups || []).map((g) => ({ value: g.id, label: g.name })));
  const STATUS_OF = { close: 'closed', reopen: 'open', waiting: 'waiting' };

  async function updateCase(id, changes) {
    return api('case_update', { case_id: id }, { method: 'POST', body: changes });
  }
  async function setGroup(id, groupId) {
    return api('case_group_set', { case_id: id }, { method: 'POST', body: { group_id: groupId || 0 } });
  }

  /**
   * The list as it is on screen, for ‹ › on the case page (case-nav.js): kept in
   * this tab only, written when a case is opened from here.
   */
  function rememberOrder() {
    const cases = Array.from(document.querySelectorAll('tr[data-case-id]')).map((tr) => ({
      id: Number(tr.dataset.caseId),
      number: (tr.querySelector('.case-badge')?.textContent || '').trim(),
      name: tr.dataset.caseName || '',
    })).filter((c) => c.id > 0);
    const label = (document.querySelector('.case-view.active, .view-chip.active, [aria-pressed="true"].case-view')?.textContent || '').trim().replace(/\s*\d+$/, '');
    try { sessionStorage.setItem('ecw.caseNav', JSON.stringify({ cases: cases.slice(0, 500), label })); } catch (_) { /* private mode: no ‹ › order */ }
  }
  // Any way a case is opened from the list: its link (click, middle-click, Enter) or the row.
  ['click', 'auxclick', 'keydown'].forEach((type) => document.addEventListener(type, (e) => {
    if (type === 'keydown' && e.key !== 'Enter') return;
    if (e.target.closest?.('a[href^="case.php?id="], tr[data-case-id]')) rememberOrder();
  }, true));

  /** One case, from its row menu. */
  function rowAction(key, id, anchor) {
    const c = rowsById.get(id) || {};
    const done = (msg) => { toast(msg); fetchAndRender(false); };
    const fail = (e) => toast(e.message, true);
    if (key === 'open') { rememberOrder(); window.location.href = 'case.php?id=' + id; return; }
    if (STATUS_OF[key]) { updateCase(id, { status: STATUS_OF[key] }).then(() => done(c.case_number + ' ' + (key === 'close' ? 'closed' : key === 'waiting' ? 'is waiting for the customer' : 'reopened'))).catch(fail); return; }
    if (key === 'owner' || key === 'handler') {
      const field = key === 'owner' ? 'owner_user_id' : 'handler_user_id';
      pickFrom(anchor, key === 'owner' ? 'Responsible' : 'Handled by', peopleOptions(key === 'owner' ? c.owner_user_id : c.handler_user_id), (v) => updateCase(id, { [field]: v === '' ? null : Number(v) }).then(() => done('Saved')).catch(fail));
      return;
    }
    if (key === 'due') { pickDate(anchor, 'Due date', c.due_at, (v) => updateCase(id, { due_at: v }).then(() => done(v ? 'Due date set' : 'Due date removed')).catch(fail)); return; }
    if (key === 'group') { pickFrom(anchor, 'Move to group', groupOptions(), (v) => setGroup(id, Number(v)).then(() => done('Moved')).catch(fail)); }
  }

  // ------------------------------------------------------------ bulk actions
  // Tick cases (or the header box for the whole page), then close, reopen, set
  // people, a date or a group for all of them at once.

  function updateBulkBar() {
    const bar = $('caseBulk');
    if (!bar) return;
    bar.hidden = selected.size === 0;
    $('caseBulkCount').textContent = selected.size + ' selected';
    document.querySelectorAll('#caseTableBody tr').forEach((tr) => tr.classList.toggle('selected', selected.has(Number(tr.dataset.caseId))));
    const all = $('caseSelectAll');
    if (all) {
      const ids = [...rowsById.keys()];
      all.checked = ids.length > 0 && ids.every((id) => selected.has(id));
      all.indeterminate = !all.checked && ids.some((id) => selected.has(id));
    }
  }
  document.addEventListener('change', (e) => {
    if (e.target.classList?.contains('case-select')) {
      const id = Number(e.target.closest('tr').dataset.caseId);
      if (e.target.checked) selected.add(id); else selected.delete(id);
      updateBulkBar();
    } else if (e.target.id === 'caseSelectAll') {
      rowsById.forEach((_, id) => { if (e.target.checked) selected.add(id); else selected.delete(id); });
      document.querySelectorAll('.case-select').forEach((b) => { b.checked = e.target.checked; });
      updateBulkBar();
    }
  });

  async function bulkApply(label, fn) {
    const ids = [...selected];
    let ok = 0; const failed = [];
    toast(label + ' ' + ids.length + ' case' + (ids.length === 1 ? '' : 's') + '…');
    for (const id of ids) {
      try { await fn(id); ok++; } catch (e) { failed.push(e.message); }
    }
    selected.clear();
    updateBulkBar();
    fetchAndRender(false);
    toast(failed.length ? ok + ' done, ' + failed.length + ' not changed: ' + failed[0] : ok + ' case' + (ok === 1 ? '' : 's') + ' updated', failed.length > 0);
  }
  document.querySelectorAll('[data-bulk]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.bulk;
    if (k === 'clear') { selected.clear(); document.querySelectorAll('.case-select').forEach((x) => { x.checked = false; }); updateBulkBar(); return; }
    if (STATUS_OF[k]) { bulkApply(k === 'close' ? 'Closing' : 'Updating', (id) => updateCase(id, { status: STATUS_OF[k] })); return; }
    if (k === 'owner' || k === 'handler') {
      const field = k === 'owner' ? 'owner_user_id' : 'handler_user_id';
      pickFrom(b, k === 'owner' ? 'Responsible for all selected' : 'Handled by for all selected', peopleOptions(), (v) => bulkApply('Updating', (id) => updateCase(id, { [field]: v === '' ? null : Number(v) })));
      return;
    }
    if (k === 'due') { pickDate(b, 'Due date for all selected', '', (v) => bulkApply('Updating', (id) => updateCase(id, { due_at: v }))); return; }
    if (k === 'group') pickFrom(b, 'Move all selected to', groupOptions(), (v) => bulkApply('Moving', (id) => setGroup(id, Number(v))));
  }));

  // ------------------------------------------------------------ new case

  const ncOverlay = $('newCaseOverlay');
  $('newCaseBtn')?.addEventListener('click', () => {
    if (!ncOverlay) return;
    ncOverlay.hidden = false;
    $('ncName').value = '';
    if ($('ncGroup') && (state.filters.group_ids || []).length === 1) $('ncGroup').value = String(state.filters.group_ids[0]);
    $('ncName').focus();
  });
  // Responsible / Handled by in the dialog: a button with the person's photo that opens the people list.
  function drawPersonPick(btn) {
    const id = $(btn.dataset.pick).value;
    const p = id ? personById(id) : null;
    btn.textContent = '';
    if (p && window.ecwAvatar) btn.appendChild(window.ecwAvatar(p, 22));
    const name = document.createElement('span'); name.className = 'person-pick-name';
    name.textContent = p ? (Number(p.id) === Number(bootstrap.me) ? p.name + ' (you)' : p.name) : 'Nobody yet';
    if (!p) name.classList.add('muted');
    const caret = document.createElement('i'); caret.className = 'fa fa-chevron-down person-pick-caret'; caret.setAttribute('aria-hidden', 'true');
    btn.append(name, caret);
  }
  document.querySelectorAll('.person-pick').forEach((btn) => {
    drawPersonPick(btn);
    btn.addEventListener('click', () => pickFrom(btn, btn.dataset.title, peopleOptions($(btn.dataset.pick).value), (v) => { $(btn.dataset.pick).value = v; drawPersonPick(btn); }));
  });
  // avatars.js loads deferred: redraw once it is there.
  window.addEventListener('load', () => document.querySelectorAll('.person-pick').forEach(drawPersonPick));
  $('ncCancel')?.addEventListener('click', () => { ncOverlay.hidden = true; });
  ncOverlay?.addEventListener('keydown', (e) => { if (e.key === 'Escape') ncOverlay.hidden = true; });
  $('newCaseForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = $('ncName').value.trim();
    if (!name) { $('ncName').focus(); return; }
    const btn = $('ncCreate'); btn.disabled = true; btn.textContent = 'Creating…';
    const val = (id) => ($(id) ? $(id).value : '');
    try {
      const r = await api('case_new', {}, { method: 'POST', body: {
        name, template_id: Number(val('ncTemplate')) || 0, owner_user_id: val('ncOwner') ? Number(val('ncOwner')) : null,
        handler_user_id: Number(val('ncHandler')) || 0, group_id: Number(val('ncGroup')) || 0, due_at: val('ncDue'),
      } });
      window.location.href = r.url;
    } catch (err) {
      toast(err.message, true);
      btn.disabled = false; btn.textContent = 'Create case';
    }
  });

  // First draw from the page's own data, so every row has its checkbox and menu.
  if (Array.isArray(bootstrap.cases)) {
    renderTable(bootstrap.cases);
    renderViews(bootstrap.views || null);
    // avatars.js loads deferred: draw the photos once it is there (only if nothing newer was fetched).
    if (!window.ecwAvatar) window.addEventListener('load', () => { if (!inFlight && $('caseTable') && !lastFetched) renderTable(bootstrap.cases); });
  }

  window.addEventListener('popstate', (e) => {
    const params = new URLSearchParams(window.location.search);
    state.filters = Object.assign({}, DEFAULT_FILTERS, {
      status: params.get('status') || 'open',
      q: params.get('q') || '',
      mailbox_ids: params.getAll('mailbox_ids[]').map(Number).filter((n) => n > 0),
      participant: params.get('participant') || '',
      date_preset: params.get('date_preset') || '',
      date_from: params.get('date_from') || '',
      date_to: params.get('date_to') || '',
      has_attachments: params.get('has_attachments') || '',
      group_ids: [...new Set(params.getAll('group_ids[]').concat(params.get('group_id') || []).map(Number).filter((n) => n > 0))],
      tag: params.get('tag') || '',
      view: params.get('view') || '',
      sort: params.get('sort') || 'last_activity',
      dir: params.get('dir') || '',
    });
    ADV.forEach((el) => { const k = el.dataset.filterKey; if (k === 'tag') return; const v = params.get(k); state.filters[k] = v === null ? DEFAULT_FILTERS[k] : (typeof DEFAULT_FILTERS[k] === 'number' ? Number(v) || 0 : v); });
    state.page = Number(params.get('page')) || 1;
    syncSelects();
    fetchAndRender(false);
  });
})();
