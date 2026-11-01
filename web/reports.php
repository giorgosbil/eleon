<?php declare(strict_types=1);
/** Reports: workload, response and resolution times over the cases the viewer can see, with filters. */
require_once dirname(__DIR__) . '/lib/session.php';
$context = ecw_require_login(); ecw_require_feature('reports');
$navActive = 'reports';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  /* Series colours: reference palette slots 1-2 (dataviz skill), light and dark steps. */
  .reports-page { --series-created:#2a78d6; --series-closed:#eb6834; }
  :root[data-theme="dark"] .reports-page { --series-created:#3987e5; --series-closed:#d95926; }
  .week-bars .b-created { background:var(--series-created); }
  .week-bars .b-closed { background:var(--series-closed); }
  .hbar-fill { background:var(--series-created); }
  .group-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; vertical-align:middle; }
  .report-tip { position:fixed; z-index:90; pointer-events:none; padding:6px 9px; border-radius:6px; background:var(--text); color:var(--bg); font-size:12px; white-space:nowrap; }
  .report-filters { display:flex; flex-wrap:wrap; gap:10px 12px; align-items:flex-end; padding:12px 14px; margin:8px 0 6px; border:1px solid var(--border); border-radius:12px; background:var(--bg); }
  .report-filters label { display:flex; flex-direction:column; gap:3px; font-size:11px; font-weight:600; letter-spacing:.03em; text-transform:uppercase; color:var(--muted); }
  .report-filters select, .report-filters input[type="date"] { min-width:150px; padding:6px 8px; border:1px solid var(--border); border-radius:8px; font:inherit; font-size:13px; text-transform:none; letter-spacing:0; font-weight:400; background:var(--bg); color:var(--text); }
  .report-filters .filter-actions { display:flex; gap:8px; margin-left:auto; }
  .report-active { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 10px; }
  .report-active .chip { display:inline-flex; gap:6px; align-items:center; }
  .hbar.clickable { cursor:pointer; border-radius:6px; }
  .hbar.clickable:hover { background:var(--bg-alt); }
  .kpi .kpi-sub { font-size:12px; color:var(--muted); margin-top:2px; }
  .trend-chart { display:grid; grid-auto-flow:column; grid-auto-columns:minmax(18px, 1fr); gap:4px; align-items:end; height:170px; padding-top:8px; overflow-x:auto; }
  .trend-chart .week-col { min-width:18px; }
  .trend-chart .week-label { writing-mode:vertical-rl; transform:rotate(180deg); max-height:44px; }
</style>
</head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>">
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="page reports-page btn-scope">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h1>Reports</h1>
    <a class="button" id="repCsv" href="#"><i class="fa fa-file-arrow-down" aria-hidden="true"></i> Export cases (CSV)</a>
  </div>

  <form class="report-filters" id="repFilters" autocomplete="off">
    <label>Period
      <select id="fPreset">
        <option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option><option value="90">Last 90 days</option>
        <option value="180">Last 6 months</option><option value="365">Last 12 months</option><option value="custom">Custom range…</option>
      </select>
    </label>
    <label id="fFromWrap" hidden>From<input type="date" id="fFrom"></label>
    <label id="fToWrap" hidden>To<input type="date" id="fTo"></label>
    <label>Group<select id="fGroup"><option value="0">All groups</option><option value="-1">No group</option></select></label>
    <label>Responsible<select id="fOwner"><option value="0">Anyone</option><option value="-1">Unassigned</option></select></label>
    <label>Handled by<select id="fHandler"><option value="0">Anyone</option><option value="-1">Unassigned</option></select></label>
    <label>Status<select id="fStatus" data-i18n-ctx="status"><option value="">Any status</option><option value="open">Open</option><option value="waiting">Waiting</option><option value="closed">Closed</option></select></label>
    <div class="filter-actions"><button type="button" id="fReset">Reset</button></div>
  </form>
  <div class="report-active" id="repActive"></div>
  <p class="muted small" id="repPeriod">Counts only include cases you can see.</p>

  <div class="kpi-row" id="kpis"></div>

  <div class="report-grid">
    <section class="report-card sla-card" style="grid-column:1 / -1" id="slaCard">
      <h2>Response times <span class="muted small" id="slaRule"></span></h2>
      <div class="kpi-row sla-kpis" id="slaKpis"></div>
      <div class="sla-columns">
        <div><h3 class="small">Late or nearly late now</h3><table class="aging-table"><thead><tr><th>Case</th><th>Responsible</th><th>Reply</th></tr></thead><tbody id="slaLate"></tbody></table></div>
        <div><h3 class="small">By Responsible <span class="muted">· replies in the period</span></h3><table class="aging-table"><thead><tr><th>Person</th><th>Replies</th><th>On time</th><th>Median</th></tr></thead><tbody id="slaPeople"></tbody></table></div>
      </div>
    </section>
    <section class="report-card" style="grid-column:1 / -1">
      <h2 id="trendTitle">Created and closed</h2>
      <div class="legend"><span><i style="background:var(--series-created)"></i>Created</span><span><i style="background:var(--series-closed)"></i>Closed</span></div>
      <div class="trend-chart" id="trendChart" role="img" aria-label="Cases created and closed over the period"></div>
      <details><summary class="small">Show as table</summary><table class="aging-table" id="trendTable"></table></details>
    </section>
    <section class="report-card"><h2>Open work by group <span class="muted small">· click to filter</span></h2><div id="byGroup"></div></section>
    <section class="report-card"><h2>Open work by Responsible <span class="muted small">· click to filter</span></h2><div id="byPerson"></div></section>
    <section class="report-card"><h2>Open work by Handled by <span class="muted small">· click to filter</span></h2><div id="byHandler"></div></section>
    <section class="report-card"><h2>Busiest customers <span class="muted small">· cases opened in the period, by email domain</span></h2><div id="customers"></div></section>
    <section class="report-card" style="grid-column:1 / -1">
      <h2>Needs attention <span class="muted small">· not closed, no activity for 7+ days</span></h2>
      <table class="aging-table"><thead><tr><th>Case</th><th>Status</th><th>Responsible</th><th>Last activity</th></tr></thead><tbody id="aging"></tbody></table>
    </section>
  </div>
</main>
<div class="report-tip" id="reportTip" hidden></div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const tip = $('reportTip');
  const hover = (node, text) => {
    node.addEventListener('pointermove', (e) => { tip.hidden = false; tip.textContent = text; tip.style.left = (e.clientX + 12) + 'px'; tip.style.top = (e.clientY - 28) + 'px'; });
    node.addEventListener('pointerleave', () => { tip.hidden = true; });
    node.setAttribute('aria-label', text);
  };
  const duration = (s) => s === null || s === undefined ? '—' : s < 3600 ? Math.round(s / 60) + ' min' : s < 86400 ? (s / 3600).toFixed(1) + ' h' : (s / 86400).toFixed(1) + ' days';
  const names = { group: new Map([['-1', 'No group']]), user: new Map([['-1', 'Unassigned']]) };

  // ---------------------------------------------------------------- filters
  const fields = { group_id: 'fGroup', owner: 'fOwner', handler: 'fHandler', status: 'fStatus' };
  function readFilters() {
    const f = {};
    const preset = $('fPreset').value;
    if (preset === 'custom') { if ($('fFrom').value) f.from = $('fFrom').value; if ($('fTo').value) f.to = $('fTo').value; }
    else f.days = preset;
    Object.entries(fields).forEach(([k, id]) => { const v = $(id).value; if (v && v !== '0') f[k] = v; });
    return f;
  }
  function writeFilters(params) {
    const custom = params.get('from') || params.get('to');
    $('fPreset').value = custom ? 'custom' : (params.get('days') || '30');
    $('fFrom').value = params.get('from') || ''; $('fTo').value = params.get('to') || '';
    Object.entries(fields).forEach(([k, id]) => { $(id).value = params.get(k) || (k === 'status' ? '' : '0'); });
    syncCustom();
  }
  function syncCustom() { const c = $('fPreset').value === 'custom'; $('fFromWrap').hidden = !c; $('fToWrap').hidden = !c; }
  const query = (f) => new URLSearchParams(f).toString();

  function renderActive(f) {
    const box = $('repActive'); box.textContent = '';
    const label = { group_id: (v) => 'Group: ' + (names.group.get(String(v)) || v), owner: (v) => 'Responsible: ' + (names.user.get(String(v)) || v),
      handler: (v) => 'Handled by: ' + (names.user.get(String(v)) || v), status: (v) => 'Status: ' + v };
    Object.keys(fields).forEach((k) => {
      if (!f[k]) return;
      const chip = el('span', 'chip active', label[k](f[k]));
      const x = el('button', 'link-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Remove filter');
      x.addEventListener('click', () => { $(fields[k]).value = k === 'status' ? '' : '0'; apply(); });
      chip.appendChild(x); box.appendChild(chip);
    });
  }

  // ------------------------------------------------------------------ cards
  function kpi(label, value, sub) {
    const box = el('div', 'kpi');
    box.append(el('div', 'kpi-label', label), el('div', 'kpi-value', String(value)));
    if (sub) box.appendChild(el('div', 'kpi-sub', sub));
    return box;
  }
  function bars(container, rows, opts) {
    container.textContent = '';
    if (!rows.length) { container.appendChild(el('p', 'muted small', 'Nothing here for these filters.')); return; }
    const max = Math.max(...rows.map((r) => r.count), 1);
    rows.forEach((r) => {
      const row = el('div', 'hbar' + (opts.filter ? ' clickable' : ''));
      const label = el('span', 'hbar-label');
      if (opts.dot) { const d = el('span', 'group-dot'); d.style.background = r.color || 'var(--muted)'; label.appendChild(d); }
      label.appendChild(document.createTextNode(r.label));
      const track = el('span', 'hbar-track');
      const fill = el('span', 'hbar-fill'); fill.style.width = Math.max(2, r.count / max * 100) + '%'; track.appendChild(fill);
      hover(track, r.label + ': ' + r.count + (opts.unit || ''));
      row.append(label, track, el('span', 'hbar-value', String(r.count)));
      if (opts.filter && r.id !== undefined) {
        row.tabIndex = 0; row.setAttribute('role', 'button'); row.title = 'Filter the report to ' + r.label;
        const go = () => { $(opts.filter).value = String(r.id); apply(); };
        row.addEventListener('click', go);
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
      }
      container.appendChild(row);
    });
  }

  async function load() {
    const f = readFilters();
    renderActive(f);
    $('repCsv').href = 'api/index.php?action=reports_csv&' + query(f);
    const r = await fetch('api/index.php?action=reports&' + query(f));
    const d = await r.json();
    if (!r.ok) { $('kpis').textContent = d.error || 'Reports unavailable'; return; }
    const fmt = (s) => new Date(s + 'T00:00:00').toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
    $('repPeriod').textContent = 'Period ' + fmt(d.filters.from) + ' – ' + fmt(d.filters.to) + ' · counts only include cases you can see.';

    const fr = d.first_response;
    const k = $('kpis'); k.textContent = '';
    k.append(
      kpi('Open now', d.status.open), kpi('Waiting now', d.status.waiting),
      kpi('Created', d.created, 'in the period'), kpi('Closed', d.closed, 'in the period'),
      kpi('First reply (median)', duration(fr.median_seconds), fr.cases ? 'average ' + duration(fr.average_seconds) + ' · ' + fr.cases + ' cases' : 'no replies yet'),
      kpi('Answered within 24 h', fr.within_24h_pct === null ? '—' : fr.within_24h_pct + '%', fr.still_waiting ? fr.still_waiting + ' still waiting for a first reply' : ''),
      kpi('Time to close (median)', duration(d.resolution.median_seconds), d.resolution.cases + ' closed'),
    );

    $('trendTitle').textContent = 'Created and closed per ' + d.trend.unit;
    const chart = $('trendChart'); chart.textContent = '';
    const table = $('trendTable'); table.textContent = '';
    const head = el('tr'); [d.trend.unit === 'month' ? 'Month' : 'Week of', 'Created', 'Closed'].forEach((t) => head.appendChild(el('th', '', t))); table.appendChild(head);
    const max = Math.max(1, ...d.trend.buckets.flatMap((w) => [w.created, w.closed]));
    d.trend.buckets.forEach((w) => {
      const col = el('div', 'week-col');
      const b = el('div', 'week-bars');
      [['created', w.created], ['closed', w.closed]].forEach(([kind, n]) => {
        const bar = el('span', 'b-' + kind); bar.style.height = (n / max * 100) + '%';
        hover(bar, w.label + ': ' + n + ' ' + kind); b.appendChild(bar);
      });
      col.append(b, el('span', 'week-label', w.label));
      chart.appendChild(col);
      const tr = el('tr'); [w.label, w.created, w.closed].forEach((v) => tr.appendChild(el('td', '', String(v)))); table.appendChild(tr);
    });

    bars($('byGroup'), d.by_group, { dot: true, filter: 'fGroup', unit: ' open' });
    bars($('byPerson'), d.by_person, { filter: 'fOwner', unit: ' open' });
    bars($('byHandler'), d.by_handler, { filter: 'fHandler', unit: ' open' });
    bars($('customers'), d.customers, { unit: ' cases' });

    // Response times in business hours (lib/sla.php).
    const s = d.sla;
    $('slaCard').hidden = !s || !s.enabled;
    if (s && s.enabled) {
      const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      $('slaRule').textContent = '· target ' + s.target_hours + ' business hours (' + s.days.map((x) => dayNames[x]).join(' ') + ', ' + s.hours + ')';
      const sk = $('slaKpis'); sk.textContent = '';
      sk.append(
        kpi('Replied on time', s.within_pct === null ? '—' : s.within_pct + '%', s.replies + ' replies in the period'),
        kpi('Reply time (median)', duration(s.median_seconds), 'business hours'),
        kpi('Overdue now', s.overdue_now, s.due_soon ? s.due_soon + ' more due soon' : 'open cases past the target'),
        kpi('Waiting for us', s.waiting_now, 'open cases with an unanswered email'),
      );
      const late = $('slaLate'); late.textContent = '';
      if (!s.late_cases.length) { const tr = el('tr'); const td = el('td', 'muted', 'Every open case is on time. 🎉'); td.colSpan = 3; tr.appendChild(td); late.appendChild(tr); }
      s.late_cases.forEach((c) => {
        const tr = el('tr');
        const a = el('a', '', c.number + ' · ' + c.name); a.href = 'case.php?id=' + c.id;
        const td = el('td'); td.appendChild(a);
        const pill = el('span', 'sla-pill ' + c.state, c.state === 'overdue' ? 'overdue ' + duration(-c.left_seconds) : duration(c.left_seconds) + ' left');
        const tdp = el('td'); tdp.appendChild(pill);
        tr.append(td, el('td', '', c.owner || 'Unassigned'), tdp);
        late.appendChild(tr);
      });
      const people = $('slaPeople'); people.textContent = '';
      if (!s.by_person.length) { const tr = el('tr'); const td = el('td', 'muted', 'No replies in this period.'); td.colSpan = 4; tr.appendChild(td); people.appendChild(tr); }
      s.by_person.forEach((p) => {
        const tr = el('tr');
        tr.append(el('td', '', p.label), el('td', '', String(p.replies)), el('td', '', p.within_pct + '%'), el('td', '', duration(p.median_seconds)));
        people.appendChild(tr);
      });
    }

    const aging = $('aging'); aging.textContent = '';
    if (!d.aging.length) { const tr = el('tr'); const td = el('td', 'muted', 'Nothing is stuck. 🎉'); td.colSpan = 4; tr.appendChild(td); aging.appendChild(tr); }
    d.aging.forEach((c) => {
      const tr = el('tr');
      const a = el('a', '', c.number + ' · ' + c.name); a.href = 'case.php?id=' + c.id;
      const td = el('td'); td.appendChild(a);
      tr.append(td, el('td', '', c.status), el('td', '', c.owner || 'Unassigned'),
        el('td', '', c.last_activity ? new Date(c.last_activity.replace(' ', 'T') + 'Z').toLocaleDateString() : 'never'));
      aging.appendChild(tr);
    });
  }

  function apply() {
    syncCustom();
    const qs = query(readFilters());
    history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
    load();
  }

  $('repFilters').addEventListener('change', apply);
  $('fPreset').addEventListener('change', () => { if ($('fPreset').value === 'custom' && !$('fTo').value) { const d = new Date(); $('fTo').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); } });
  $('fReset').addEventListener('click', () => { history.replaceState(null, '', location.pathname); writeFilters(new URLSearchParams()); load(); });

  // Filter options: groups and people.
  Promise.all([fetch('api/index.php?action=case_groups').then((r) => r.json()), fetch('api/index.php?action=org_users').then((r) => r.json())])
    .then(([g, u]) => {
      (g.groups || []).forEach((x) => { $('fGroup').add(new Option(x.name, x.id)); names.group.set(String(x.id), x.name); });
      (u.users || []).forEach((x) => { ['fOwner', 'fHandler'].forEach((id) => $(id).add(new Option(x.name || x.email, x.id))); names.user.set(String(x.id), x.name || x.email); });
    })
    .catch(() => {})
    .finally(() => { writeFilters(new URLSearchParams(location.search)); load(); });
})();
</script>
</body>
</html>
