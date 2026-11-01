/**
 * Calendar page: the signed-in person's Outlook calendar.
 *
 *  - Month view is one continuous, smoothly scrolling list of weeks with a thin
 *    calendar-week (CW, ISO 8601) column on the left. The title follows the
 *    month in the middle of the screen; ‹ › scroll to the previous/next month.
 *  - Events are cached per week (memory + sessionStorage for this tab), so going
 *    to November and back to October shows October at once. Visible weeks are
 *    refreshed in the background every minute and when the tab is shown again.
 *    The centred loader appears only when nothing is cached for what is on screen.
 *  - Drag an event to move it (to another day, or another time in Week/Day);
 *    drag its bottom edge (Week/Day) or right edge (all-day, Month) to extend it.
 *    Only events you organise without attendees can be changed — meetings and
 *    series are managed in Outlook (the server enforces this too).
 *  - Right-click an event (open, show as, duplicate, join, delete…) or an empty
 *    day / time (new event, new all-day event, show this day / week, today).
 *
 * Everything from Outlook is written with textContent.
 */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const form = $('calendarForm'), dialog = $('calendarDialog');
  const content = document.querySelector('.calendar-content');
  const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  const DAY = 86400000, SNAP = 15, MAX_FETCH_WEEKS = 8, REFRESH_MS = 60000;
  const dateKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const dateAt = (s) => new Date(`${s}T00:00:00`);
  const plusDays = (d, n) => { const next = new Date(d); next.setDate(next.getDate() + n); return next; };
  const midnight = (d) => dateAt(dateKey(d));
  const monthName = (d) => d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  const longDate = (d) => d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  const time = (d) => d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  const monday = (d) => plusDays(midnight(d), -((d.getDay() + 6) % 7));
  const localInput = (d) => `${dateKey(d)}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const button = (cls, text, action) => { const n = el('button', cls, text); n.type = 'button'; if (action) n.addEventListener('click', action); return n; };
  /** ISO 8601 week number (weeks start Monday; week 1 holds the first Thursday). */
  function isoWeek(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const wd = t.getUTCDay() || 7;
    t.setUTCDate(t.getUTCDate() + 4 - wd);
    return Math.ceil(((t - Date.UTC(t.getUTCFullYear(), 0, 1)) / DAY + 1) / 7);
  }
  const evStart = (e) => (e.allDay ? dateAt(e.start) : new Date(e.start));
  const evEnd = (e) => (e.allDay ? dateAt(e.end) : new Date(e.end));

  let selected = midnight(new Date()), miniMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
  let focusMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
  let view = 'month', editing = null, transactionId = '', pending = false, ready = false, connectError = null;
  try { const saved = localStorage.getItem('ecw.calendar.view'); if (['month', 'week', 'day', 'agenda'].includes(saved)) view = saved; } catch (_) {}
  $('calendarView').value = view;
  $('calendarZone').textContent = `Times shown in ${timezone.replaceAll('_', ' ')}`;

  // ------------------------------------------------------------------ cache
  const CACHE_KEY = 'ecw.calendar.cache.' + (window.ecwUserId || '');
  const store = new Map();          // id -> event
  const loadedWeeks = new Map();    // monday key -> fetched at (ms)
  const inflight = new Set();       // monday keys being fetched
  try {
    const c = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
    if (c && Date.now() - c.at < 6 * 3600000) {
      c.events.forEach((e) => store.set(e.id, e));
      Object.entries(c.weeks).forEach(([k, at]) => loadedWeeks.set(k, at));
      ready = true;
    }
  } catch (_) {}
  let saveTimer = null;
  function saveCache() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), events: [...store.values()], weeks: Object.fromEntries(loadedWeeks) })); } catch (_) {}
    }, 300);
  }

  function notice(message = '', connect = false) {
    const n = $('calendarNotice'); n.replaceChildren(); n.hidden = !message;
    if (!message) return;
    n.append(el('span', '', message));
    if (connect) { const a = el('a', '', 'Enable Outlook calendar'); a.href = `${window.ecwWebBase}oauth/start.php?calendar=1`; n.append(a); }
  }
  let toastTimer = null;
  function toast(message, isError) {
    let t = $('calendarToast');
    if (!t) { t = el('div', 'toast'); t.id = 'calendarToast'; t.setAttribute('role', 'status'); document.body.append(t); }
    t.textContent = message; t.classList.toggle('error', !!isError); t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, isError ? 6000 : 2600);
  }
  async function api(action, data = null, params = {}) {
    const url = new URL(`${window.ecwWebBase}api/`, location.href);
    url.search = new URLSearchParams({ action, ...params });
    const response = await fetch(url, { credentials: 'same-origin', headers: data ? { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf } : {}, ...(data ? { method: 'POST', body: JSON.stringify(data) } : {}) });
    let body;
    try { body = await response.json(); } catch (_) { throw new Error('Calendar could not be loaded. Reload the page and try again.'); }
    if (!response.ok) { const error = new Error(body.error || 'Calendar request failed.'); error.consent = body.needs_consent; throw error; }
    return body;
  }

  // ------------------------------------------------------- loading ranges
  const loader = el('div', 'calendar-loading');
  loader.append(el('span', 'calendar-spinner'), el('span', '', 'Loading calendar…'));
  loader.hidden = true;
  document.querySelector('.calendar-layout').append(loader);
  const refreshIcon = $('calendarRefresh').querySelector('i');
  let busyCount = 0;
  const setBusy = (delta) => { busyCount = Math.max(0, busyCount + delta); refreshIcon?.classList.toggle('fa-spin', busyCount > 0); };

  function weeksIn(start, end) { const out = []; for (let w = monday(start); w < end; w = plusDays(w, 7)) out.push(w); return out; }
  /** Make sure [start, end) is cached; background=true re-fetches it anyway (silently). */
  async function ensure(start, end, background = false) {
    if (document.body.dataset.connected !== '1') { notice('Connect your Microsoft account to see your Outlook events here.', true); return; }
    const weeks = weeksIn(start, end).filter((w) => !inflight.has(dateKey(w)) && (background || !loadedWeeks.has(dateKey(w))));
    if (!weeks.length) return;
    const runs = [];
    weeks.forEach((w) => {
      const last = runs[runs.length - 1];
      if (last && dateKey(plusDays(last[last.length - 1], 7)) === dateKey(w) && last.length < MAX_FETCH_WEEKS) last.push(w); else runs.push([w]);
    });
    const showLoader = !background && weeks.some((w) => !loadedWeeks.has(dateKey(w)));
    if (showLoader) loader.hidden = false;
    setBusy(1);
    try { await Promise.all(runs.map((run) => fetchRun(run))); }
    finally { setBusy(-1); if (!inflight.size) loader.hidden = true; }
  }
  async function fetchRun(run) {
    const a = run[0], b = plusDays(run[run.length - 1], 7);
    run.forEach((w) => inflight.add(dateKey(w)));
    try {
      const result = await api('calendar_events', null, { start: a.toISOString(), end: b.toISOString() });
      // Reconcile: what the server no longer returns for this range is gone.
      const seen = new Set(result.events.map((e) => e.id));
      for (const [id, e] of store) if (!seen.has(id) && evStart(e) < b && evEnd(e) > a) store.delete(id);
      result.events.forEach((e) => store.set(e.id, e));
      run.forEach((w) => loadedWeeks.set(dateKey(w), Date.now()));
      ready = true; connectError = null; notice(); $('calendarNew').disabled = false;
      saveCache();
      paint();
    } catch (error) {
      connectError = error;
      notice(error.message, error.consent);
    } finally { run.forEach((w) => inflight.delete(dateKey(w))); }
  }
  function visibleRange() {
    if (view === 'month') {
      const rowH = weekHeight(), top = content.scrollTop;
      const first = Math.max(0, Math.floor(top / rowH) - 1), last = Math.ceil((top + content.clientHeight) / rowH) + 1;
      return [plusDays(month.from, first * 7), plusDays(month.from, last * 7)];
    }
    return range();
  }
  function range() {
    if (view === 'week') { const start = monday(selected); return [start, plusDays(start, 7)]; }
    if (view === 'agenda') return [midnight(selected), plusDays(midnight(selected), 30)];
    if (view === 'day') return [midnight(selected), plusDays(midnight(selected), 1)];
    return [monday(focusMonth), plusDays(monday(focusMonth), 42)];
  }
  function refreshVisible() { const [a, b] = visibleRange(); return ensure(a, b, true); }

  // ---------------------------------------------------------------- events
  function filtered() {
    const q = $('calendarSearch').value.trim().toLocaleLowerCase();
    return [...store.values()].filter((e) => !q || `${e.title} ${e.location} ${e.notes}`.toLocaleLowerCase().includes(q));
  }
  function onDay(e, day) {
    const s = evStart(e), end = evEnd(e), a = midnight(day), b = plusDays(a, 1);
    return s < b && (end > a || (+s === +end && s >= a));
  }
  function forDay(day, list) { return (list || filtered()).filter((e) => onDay(e, day)).sort((a, b) => Number(b.allDay) - Number(a.allDay) || String(a.start).localeCompare(String(b.start))); }
  function eventTime(e) { return e.allDay ? 'All day' : `${time(new Date(e.start))} – ${time(new Date(e.end))}`; }
  function eventButton(e, cls = 'calendar-event') {
    const n = button(`${cls}${e.busy === 'free' ? ' free' : ''}${e.busy === 'tentative' ? ' tentative' : ''}${e.busy === 'oof' ? ' oof' : ''}${e.cancelled ? ' cancelled' : ''}${e.editable ? ' draggable' : ''}`,
      `${e.allDay ? '' : time(new Date(e.start)) + ' '}${e.title}`, () => { if (!suppressClick) openEvent(e); });
    n.title = `${eventTime(e)} · ${e.title}${e.location ? ' · ' + e.location : ''}${e.editable ? '\nDrag to move' : ''}`;
    n.dataset.id = e.id;
    return n;
  }

  // An event over several days is one bar across them, as in Outlook: per week row, in lanes
  // (the first free row from the top), squared off where it goes on into the week before/after.
  function spanning(e) {
    return e.allDay ? evEnd(e) - evStart(e) > DAY * 1.5 : dateKey(evStart(e)) !== dateKey(new Date(+evEnd(e) - 1));
  }
  function weekBars(weekStart, days, list) {
    const from = midnight(weekStart), to = plusDays(from, days);
    const dayIndex = (d) => Math.round((midnight(d) - from) / DAY);
    const bars = list.filter((e) => spanning(e) && evStart(e) < to && evEnd(e) > from)
      .sort((a, b) => evStart(a) - evStart(b) || (evEnd(b) - evStart(b)) - (evEnd(a) - evStart(a)));
    const lanes = [];
    return bars.map((e) => {
      const last = e.allDay ? plusDays(evEnd(e), -1) : new Date(+evEnd(e) - 1);
      const s = Math.max(0, dayIndex(evStart(e))), end = Math.min(days - 1, dayIndex(last));
      let lane = lanes.findIndex((until) => until < s);
      if (lane === -1) { lane = lanes.length; lanes.push(-1); }
      lanes[lane] = end;
      return { e, s, end, lane, before: dayIndex(evStart(e)) < 0, after: dayIndex(last) > days - 1 };
    });
  }
  /** A bar placed in a grid row of day columns (the first day column is grid column `firstCol`). */
  function barNode(b, firstCol, top) {
    const n = eventButton(b.e, 'calendar-event calendar-span' + (b.before ? ' cont-left' : '') + (b.after ? ' cont-right' : ''));
    n.style.gridColumn = (b.s + firstCol) + ' / ' + (b.end + firstCol + 1);
    n.style.gridRow = '1';
    n.style.marginTop = top + b.lane * LANE + 'px';
    if (b.e.allDay && b.e.editable && !b.after) n.append(resizeHandle('x'));
    return n;
  }
  const LANE = 23;   // px: one event line (calendar.css .calendar-event)
  /** The day cell under a point, even with a bar lying over it. */
  function cellAt(x, y) {
    return document.elementsFromPoint(x, y).find((n) => n.matches && n.matches('.calendar-cell[data-date]')) || null;
  }

  // ------------------------------------------------------------ month view
  const month = { from: null, weeks: 0, built: false };
  function weekHeight() {
    return Math.max(104, Math.floor((content.clientHeight - 34) / 6));
  }
  function buildWeekRow(w) {
    const row = el('div', 'calendar-week');
    row.dataset.week = dateKey(w);
    const cw = el('div', 'calendar-cw', String(isoWeek(plusDays(w, 3))));
    cw.title = 'Calendar week ' + isoWeek(plusDays(w, 3));
    cw.style.gridArea = '1 / 1';
    row.append(cw);
    for (let i = 0; i < 7; i++) {
      const d = plusDays(w, i);
      const cell = el('div', 'calendar-cell');
      cell.style.gridArea = '1 / ' + (i + 2);   // fixed: a several-day bar laid over the row must not push the days along
      cell.dataset.date = dateKey(d);
      row.append(cell);
    }
    return row;
  }
  function renderMonthShell() {
    const grid = $('calendarGrid'); grid.replaceChildren();
    content.classList.add('month-scroll');
    content.style.setProperty('--week-h', weekHeight() + 'px');
    const head = el('div', 'calendar-weekdays');
    head.append(el('span', 'calendar-cw-head', 'CW'));
    dayNames.forEach((name, i) => head.append(el('span', i >= 5 ? 'weekend' : '', name)));
    const weeks = el('div', 'calendar-weeks'); weeks.id = 'calendarWeeks';
    grid.append(head, weeks);
    month.from = plusDays(monday(focusMonth), -8 * 7);
    month.weeks = 26;
    for (let i = 0; i < month.weeks; i++) weeks.append(buildWeekRow(plusDays(month.from, i * 7)));
    month.built = true;
    paintMonth();
    scrollToDate(focusMonth, false);
  }
  function paintMonth() {
    const list = filtered(), todayKey = dateKey(new Date()), selKey = dateKey(selected);
    const rowH = weekHeight(), fit = Math.max(1, Math.floor((rowH - 34) / LANE));
    // Several-day events: one bar per week row; the cells keep room for them on top.
    const lanesOn = new Map(), hiddenOn = new Map();
    document.querySelectorAll('#calendarWeeks .calendar-week').forEach((row) => {
      row.querySelectorAll('.calendar-span').forEach((n) => n.remove());
      const bars = weekBars(dateAt(row.dataset.week), 7, list);
      bars.forEach((b) => {
        for (let i = b.s; i <= b.end; i++) {
          const key = dateKey(plusDays(dateAt(row.dataset.week), i));
          if (b.lane >= fit - 1) { hiddenOn.set(key, (hiddenOn.get(key) || 0) + 1); continue; }
          lanesOn.set(key, Math.max(lanesOn.get(key) || 0, b.lane + 1));
        }
        if (b.lane < fit - 1) row.append(barNode(b, 2, 38));
      });
    });
    document.querySelectorAll('#calendarWeeks .calendar-cell').forEach((cell) => {
      const d = dateAt(cell.dataset.date);
      cell.replaceChildren();
      cell.className = 'calendar-cell' + (d.getMonth() !== focusMonth.getMonth() ? ' outside' : '') + (cell.dataset.date === selKey ? ' selected' : '') + (d.getDate() === 1 ? ' first' : '') + (d.getDay() === 0 || d.getDay() === 6 ? ' weekend' : '');
      const label = d.getDate() === 1 ? d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : String(d.getDate());
      const date = button(`calendar-date${cell.dataset.date === todayKey ? ' today' : ''}`, label, () => selectDay(d));
      date.setAttribute('aria-label', longDate(d));
      cell.append(date);
      const lanes = lanesOn.get(cell.dataset.date) || 0, hidden = hiddenOn.get(cell.dataset.date) || 0;
      if (lanes) { const room = el('div', 'calendar-span-room'); room.style.height = lanes * LANE + 'px'; cell.append(room); }
      const items = forDay(d, list).filter((e) => !spanning(e));
      const space = Math.max(0, fit - lanes);
      const shown = items.length + hidden > space ? Math.max(0, space - 1) : space;
      items.slice(0, shown).forEach((e) => {
        const chip = eventButton(e);
        if (e.allDay && e.editable) chip.append(resizeHandle('x'));
        cell.append(chip);
      });
      const more = items.length - Math.min(shown, items.length) + hidden;
      if (more > 0) cell.append(button('calendar-more', `+${more} more`, () => { selected = d; setView('day'); }));
    });
  }
  function selectDay(d) {
    selected = midnight(d); miniMonth = new Date(d.getFullYear(), d.getMonth(), 1);
    document.querySelectorAll('.calendar-cell.selected').forEach((n) => n.classList.remove('selected'));
    document.querySelector(`.calendar-cell[data-date="${dateKey(d)}"]`)?.classList.add('selected');
    renderMini(); renderSummary();
  }
  function rowIndexOf(d) { return Math.round((monday(d) - month.from) / (7 * DAY)); }
  function extendTo(d) {
    const weeks = $('calendarWeeks'), rowH = weekHeight();
    while (rowIndexOf(d) < 3) {
      for (let i = 0; i < 8; i++) { month.from = plusDays(month.from, -7); weeks.prepend(buildWeekRow(month.from)); }
      month.weeks += 8; content.scrollTop += 8 * rowH;
    }
    while (rowIndexOf(d) > month.weeks - 8) {
      for (let i = 0; i < 8; i++) weeks.append(buildWeekRow(plusDays(month.from, (month.weeks + i) * 7)));
      month.weeks += 8;
    }
  }
  function scrollToDate(d, smooth = true) {
    const target = new Date(d.getFullYear(), d.getMonth(), 1);
    extendTo(target); paintMonth();
    content.scrollTo({ top: rowIndexOf(target) * weekHeight(), behavior: smooth ? 'smooth' : 'auto' });
    if (!smooth) onMonthScroll();
  }
  let scrollTimer = null;
  function onMonthScroll() {
    if (view !== 'month' || !month.built) return;
    const rowH = weekHeight(), weeks = $('calendarWeeks');
    // Endless: add weeks before/after, drop far ones so the list stays small.
    if (content.scrollTop < rowH * 3) {
      for (let i = 0; i < 8; i++) { month.from = plusDays(month.from, -7); weeks.prepend(buildWeekRow(month.from)); }
      month.weeks += 8; content.scrollTop += 8 * rowH; paintMonth();
    } else if (content.scrollTop + content.clientHeight > (month.weeks - 3) * rowH) {
      for (let i = 0; i < 8; i++) weeks.append(buildWeekRow(plusDays(month.from, (month.weeks + i) * 7)));
      month.weeks += 8; paintMonth();
    }
    if (month.weeks > 90) {
      const extra = month.weeks - 70, topIdx = Math.floor(content.scrollTop / rowH);
      if (topIdx > month.weeks / 2) { for (let i = 0; i < extra; i++) weeks.firstElementChild.remove(); month.from = plusDays(month.from, extra * 7); content.scrollTop -= extra * rowH; }
      else { for (let i = 0; i < extra; i++) weeks.lastElementChild.remove(); }
      month.weeks -= extra;
    }
    // The month in the middle of the screen is "the" month.
    const mid = Math.floor((content.scrollTop + content.clientHeight / 2) / rowH);
    const thursday = plusDays(month.from, mid * 7 + 3);
    const m = new Date(thursday.getFullYear(), thursday.getMonth(), 1);
    if (+m !== +focusMonth) {
      focusMonth = m; miniMonth = new Date(m); renderMini();
      $('calendarTitle').textContent = monthName(focusMonth);
      document.querySelectorAll('#calendarWeeks .calendar-cell').forEach((cell) => cell.classList.toggle('outside', dateAt(cell.dataset.date).getMonth() !== focusMonth.getMonth()));
    }
    clearTimeout(scrollTimer);
    scrollTimer = setTimeout(() => { const [a, b] = visibleRange(); ensure(a, b); }, 120);
  }
  content.addEventListener('scroll', onMonthScroll, { passive: true });

  // --------------------------------------------------- week / day / agenda
  function renderAgenda(grid) {
    const agenda = el('div', 'calendar-agenda'), [start, end] = range(); let count = 0;
    for (let d = start; d < end; d = plusDays(d, 1)) {
      const list = forDay(d); if (!list.length) continue;
      count += list.length; agenda.append(el('h2', '', longDate(d)));
      list.forEach((e) => {
        const row = button('calendar-agenda-event', '', () => openEvent(e));
        row.dataset.id = e.id;
        row.append(el('small', '', eventTime(e)), el('strong', '', e.cancelled ? `${e.title} (Cancelled)` : e.title), el('small', '', e.location)); agenda.append(row);
      });
    }
    if (!count) agenda.append(el('p', 'calendar-empty', ready ? 'No events in this period.' : 'Your events will appear here.'));
    grid.append(agenda);
  }
  function renderTime(grid) {
    const [start] = range(), days = view === 'week' ? 7 : 1;
    grid.style.setProperty('--days', days);
    const head = el('div', 'calendar-time-head'), allDay = el('div', 'calendar-all-day'), timed = el('div', 'calendar-time-grid');
    // Travelling: a second hour column with the home time zone, like Outlook (assets/timezone.js).
    const second = window.ecwSecondZone || null;
    grid.classList.toggle('two-zones', !!second);
    const zoneName = (z) => String(z).split('/').pop().replace(/_/g, ' ');
    head.append(second ? el('span', 'calendar-cw-corner two', zoneName(second) + ' · ' + zoneName(window.ecwHereZone || timezone)) : el('span', 'calendar-cw-corner', 'CW ' + isoWeek(plusDays(start, 3))));
    const allLabel = el('span', '', 'All day'); allLabel.style.gridArea = '1 / 1';
    allDay.append(allLabel);
    const hours = el('div', 'calendar-hours');
    for (let h = 0; h < 24; h++) {
      const label = el('span', '', `${String(h).padStart(2, '0')}:00`); label.style.top = `${h * 60}px`;
      if (second) {
        const at = new Date(start); at.setHours(h, 0, 0, 0);
        const other = el('em', 'calendar-hour-second', at.toLocaleTimeString([], { timeZone: second, hour: '2-digit', minute: '2-digit', hour12: false }));
        label.prepend(other);
      }
      hours.append(label);
    }
    timed.append(hours);
    const list = filtered();
    // All-day events over several days: one bar across the days (lanes, as in the month).
    const allBars = weekBars(start, days, list.filter((e) => e.allDay));
    for (let i = 0; i < days; i++) {
      const d = plusDays(start, i), today = dateKey(d) === dateKey(new Date());
      head.append(button(today ? 'today' : '', d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }), () => { selected = d; setView('day'); }));
      const all = el('div', 'calendar-cell calendar-all-day-cell' + (d.getDay() === 0 || d.getDay() === 6 ? ' weekend' : '')); all.dataset.date = dateKey(d);
      all.style.gridArea = '1 / ' + (i + 2);   // fixed under the all-day bars
      const dayList = forDay(d, list);
      const lanes = allBars.filter((b) => b.s <= i && b.end >= i).reduce((m, b) => Math.max(m, b.lane + 1), 0);
      if (lanes) { const room = el('div', 'calendar-span-room'); room.style.height = lanes * LANE + 'px'; all.append(room); }
      dayList.filter((e) => e.allDay && !spanning(e)).forEach((e) => all.append(eventButton(e)));
      allDay.append(all);
      const column = el('div', 'calendar-time-day' + (d.getDay() === 0 || d.getDay() === 6 ? ' weekend' : '')); column.dataset.date = dateKey(d);
      column.addEventListener('dblclick', (e) => { if (e.target !== column) return; openNew(d, slotMinute(column, e.clientY)); });
      // Each group of intersecting intervals gets its own columns.
      const intervals = dayList.filter((e) => !e.allDay).map((e) => {
        const a = new Date(e.start), b = new Date(e.end);
        return { e, start: a < d ? 0 : a.getHours() * 60 + a.getMinutes(), end: b >= plusDays(d, 1) ? 1440 : b.getHours() * 60 + b.getMinutes(), whole: a >= d && b <= plusDays(d, 1) };
      }).sort((a, b) => a.start - b.start || b.end - a.end);
      const groups = []; let group = [], lastEnd = -1;
      intervals.forEach((item) => { if (item.start >= lastEnd && group.length) { groups.push(group); group = []; lastEnd = -1; } group.push(item); lastEnd = Math.max(lastEnd, item.end); });
      if (group.length) groups.push(group);
      groups.forEach((items) => {
        const ends = [];
        items.forEach((item) => { let col = ends.findIndex((end) => end <= item.start); if (col === -1) col = ends.length; ends[col] = Math.max(item.end, item.start + 1); item.col = col; });
        items.forEach((item) => {
          const n = eventButton(item.e, 'calendar-event calendar-time-event');
          n.replaceChildren(el('small', '', eventTime(item.e)), el('strong', '', item.e.title));
          if (!item.whole) n.classList.remove('draggable');   // spans midnight: change it in the dialog
          else if (item.e.editable) n.append(resizeHandle('y'));
          n.style.top = `${item.start}px`; n.style.height = `${Math.max(20, item.end - item.start)}px`; n.style.left = `calc(${item.col * 100 / ends.length}% + 2px)`; n.style.width = `calc(${100 / ends.length}% - 4px)`; column.append(n);
        });
      });
      if (today) { const now = new Date(), line = el('div', 'calendar-now'); line.style.top = `${now.getHours() * 60 + now.getMinutes()}px`; column.append(line); }
      timed.append(column);
    }
    allBars.forEach((b) => allDay.append(barNode(b, 2, 3)));
    grid.append(head, allDay, timed);
  }
  const slotMinute = (column, clientY) => Math.min(1410, Math.max(0, Math.floor((clientY - column.getBoundingClientRect().top) / 30) * 30));
  function resizeHandle(axis) {
    const h = el('span', 'calendar-resize ' + axis);
    h.setAttribute('aria-hidden', 'true');
    h.title = axis === 'y' ? 'Drag to change the end time' : 'Drag to extend over more days';
    return h;
  }

  // ------------------------------------------------------------------ paint
  function title() {
    if (view === 'month') return monthName(focusMonth);
    if (view === 'day') return longDate(selected);
    const [start, end] = range();
    const opts = { month: 'short', day: 'numeric' };
    const label = `${start.toLocaleDateString(undefined, opts)} – ${plusDays(end, -1).toLocaleDateString(undefined, { ...opts, year: 'numeric' })}`;
    return view === 'week' ? `${label} · CW ${isoWeek(plusDays(start, 3))}` : label;
  }
  function renderMini() {
    $('miniTitle').textContent = monthName(miniMonth);
    const grid = $('calendarMini'); grid.replaceChildren();
    grid.append(el('span', 'mini-cw', 'CW'));
    dayNames.forEach((name) => grid.append(el('span', '', name[0])));
    const start = monday(miniMonth);
    for (let i = 0; i < 42; i++) {
      const d = plusDays(start, i), key = dateKey(d);
      if (i % 7 === 0) grid.append(el('span', 'mini-cw', String(isoWeek(plusDays(d, 3)))));
      const n = button(`${d.getMonth() !== miniMonth.getMonth() ? 'outside ' : ''}${key === dateKey(selected) ? 'selected ' : ''}${key === dateKey(new Date()) ? 'today' : ''}`, d.getDate(), () => chooseDate(d));
      n.setAttribute('aria-label', longDate(d)); if (key === dateKey(selected)) n.setAttribute('aria-pressed', 'true'); grid.append(n);
    }
  }
  function renderSummary() {
    $('selectedDayTitle').textContent = longDate(selected);
    const list = $('selectedDayEvents'); list.replaceChildren();
    const dayEvents = forDay(selected);
    dayEvents.forEach((e) => { const n = button('calendar-summary-event', e.title, () => openEvent(e)); n.append(el('small', '', eventTime(e))); list.append(n); });
    if (!dayEvents.length) list.append(el('p', '', ready ? 'No events for this day.' : 'Your events will appear here.'));
  }
  /** Redraw with the current data, keeping the scroll position. */
  function paint() {
    $('calendarTitle').textContent = title(); renderMini(); renderSummary();
    if (view === 'month') { if (!month.built) renderMonthShell(); else paintMonth(); return; }
    const top = content.scrollTop, grid = $('calendarGrid');
    grid.replaceChildren();
    if (view === 'agenda') renderAgenda(grid); else renderTime(grid);
    content.scrollTop = top;
  }
  /** Switch view or period: draw at once from the cache, fetch what is missing. */
  function show(firstTime = false) {
    month.built = false;
    content.classList.toggle('month-scroll', view === 'month');
    paint();
    if (view === 'week' || view === 'day') {
      if (firstTime || !content.dataset.scrolled) { content.scrollTop = 7 * 60; content.dataset.scrolled = '1'; }
    }
    const [a, b] = visibleRange();
    ensure(a, b).then(() => {
      // Cached weeks older than a minute are quietly refreshed.
      const stale = weeksIn(a, b).some((w) => Date.now() - (loadedWeeks.get(dateKey(w)) || 0) > REFRESH_MS);
      if (stale) refreshVisible();
    });
    // Neighbours are fetched ahead, so ‹ › are instant too.
    if (view !== 'month') { const [pa, pb] = neighbourRange(); ensure(pa, pb); }
  }
  function neighbourRange() {
    const [a, b] = range(), span = b - a;
    return [new Date(+a - span), new Date(+b + span)];
  }
  function setView(next) {
    if (next !== view) delete content.dataset.scrolled;   // a new view starts at the working day, not at the old scroll
    view = next; $('calendarView').value = view; saveView();
    if (view === 'month') focusMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
    show();
  }
  function chooseDate(d) {
    selected = midnight(d); miniMonth = new Date(d.getFullYear(), d.getMonth(), 1);
    if (view === 'month') {
      if (d.getMonth() !== focusMonth.getMonth() || d.getFullYear() !== focusMonth.getFullYear()) scrollToDate(d);
      selectDay(d);
      return;
    }
    show();
  }

  // --------------------------------------------------------- drag to change
  let drag = null, suppressClick = false;
  content.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0) return;
    const node = ev.target.closest('.calendar-event.draggable');
    if (!node) return;
    const e = store.get(node.dataset.id);
    if (!e || !e.editable) return;
    const resize = !!ev.target.closest('.calendar-resize');
    const timedNode = node.classList.contains('calendar-time-event');
    drag = {
      e, node, resize, timed: timedNode, x0: ev.clientX, y0: ev.clientY, moved: false,
      fromDate: (node.closest('[data-date]') || cellAt(ev.clientX, ev.clientY) || {}).dataset?.date,
      top0: parseFloat(node.style.top) || 0, height0: parseFloat(node.style.height) || 0,
    };
    node.setPointerCapture(ev.pointerId);
  });
  // On window: a timed event moved into another day column loses its pointer capture.
  window.addEventListener('pointermove', (ev) => {
    if (!drag) return;
    const dx = ev.clientX - drag.x0, dy = ev.clientY - drag.y0;
    if (!drag.moved && Math.hypot(dx, dy) < 5) return;
    if (!drag.moved) {
      drag.moved = true;
      document.body.classList.add('calendar-dragging');
      drag.node.classList.add('dragging');
      if (!drag.timed) {
        drag.ghost = drag.node.cloneNode(true);
        drag.ghost.classList.add('calendar-ghost');
        drag.ghost.style.width = drag.node.offsetWidth + 'px';
        document.body.append(drag.ghost);
      }
    }
    const under = document.elementFromPoint(ev.clientX, ev.clientY);
    if (drag.timed) {
      const delta = Math.round(dy / SNAP) * SNAP;
      if (drag.resize) {
        drag.height = Math.max(SNAP, Math.min(1440 - drag.top0, drag.height0 + delta));
        drag.node.style.height = drag.height + 'px';
      } else {
        const column = under?.closest('.calendar-time-day');
        if (column && column !== drag.node.parentElement) column.append(drag.node);
        drag.top = Math.max(0, Math.min(1440 - drag.height0, drag.top0 + delta));
        drag.node.style.top = drag.top + 'px';
        drag.toDate = drag.node.parentElement.dataset.date;
      }
      const s = drag.node.querySelector('small');
      if (s) {
        const day = dateAt(drag.toDate || drag.fromDate), top = drag.top ?? drag.top0, h = drag.height ?? drag.height0;
        const a = new Date(+day + top * 60000), b = new Date(+day + (top + h) * 60000);
        s.textContent = `${time(a)} – ${time(b)}`;
      }
    } else {
      drag.ghost.style.left = (ev.clientX + 8) + 'px';
      drag.ghost.style.top = (ev.clientY + 6) + 'px';
      const cell = under?.closest('.calendar-cell[data-date]') || cellAt(ev.clientX, ev.clientY);
      document.querySelectorAll('.calendar-cell.drop-target').forEach((c) => c.classList.remove('drop-target'));
      if (cell) { cell.classList.add('drop-target'); drag.toDate = cell.dataset.date; }
    }
  });
  function endDrag(cancel) {
    if (!drag) return;
    const d = drag; drag = null;
    document.body.classList.remove('calendar-dragging');
    d.ghost?.remove();
    document.querySelectorAll('.calendar-cell.drop-target').forEach((c) => c.classList.remove('drop-target'));
    if (!d.moved) return;
    suppressClick = true; setTimeout(() => { suppressClick = false; }, 0);
    if (cancel) { paint(); return; }
    const e = d.e, s = evStart(e), en = evEnd(e);
    let ns = s, ne = en;
    if (d.timed) {
      const day = dateAt(d.toDate || d.fromDate);
      const top = d.top ?? d.top0, h = d.height ?? d.height0;
      ns = new Date(+day + top * 60000);
      ne = d.resize ? new Date(+s + h * 60000) : new Date(+ns + (en - s));
      if (d.resize) ns = s;
    } else if (d.toDate) {
      const shift = Math.round((dateAt(d.toDate) - dateAt(d.fromDate)) / DAY);
      if (d.resize) { ne = plusDays(dateAt(d.toDate), 1); if (ne <= s) ne = plusDays(s, 1); }
      else { ns = plusDays(s, shift); ne = plusDays(en, shift); if (!e.allDay) { ns = new Date(+s + shift * DAY); ne = new Date(+en + shift * DAY); } }
    }
    if (+ns === +s && +ne === +en) { paint(); return; }
    changeEvent(e, { start: ns, end: ne }, d.resize ? 'Event extended' : 'Event moved');
  }
  window.addEventListener('pointerup', () => endDrag(false));
  window.addEventListener('pointercancel', () => endDrag(true));
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && drag) endDrag(true); });

  /** Save a change to one event, showing it at once and undoing it if Outlook refuses. */
  async function changeEvent(e, changes, message) {
    const next = { ...e, ...changes };
    if (changes.start instanceof Date) next.start = e.allDay ? dateKey(changes.start) : changes.start.toISOString();
    if (changes.end instanceof Date) next.end = e.allDay ? dateKey(changes.end) : changes.end.toISOString();
    store.set(e.id, next); paint();
    try {
      const saved = await api('calendar_save', { id: e.id, transactionId: '', title: next.title, allDay: next.allDay, timezone,
        location: next.location, notes: next.notes, busy: next.busy, start: next.start, end: next.end });
      store.set(saved.id, saved); saveCache(); paint();
      toast(message);
    } catch (error) {
      store.set(e.id, e); paint();
      toast(error.message, true);
    }
  }

  // ------------------------------------------------------- right-click menu
  const menu = el('div', 'ctx-menu'); menu.setAttribute('role', 'menu'); menu.hidden = true; document.body.append(menu);
  function closeMenu() { menu.hidden = true; menu.replaceChildren(); }
  function openMenu(x, y, items) {
    menu.replaceChildren();
    items.forEach((it) => {
      if (it === '-') { const s = el('div', 'ctx-sep'); s.setAttribute('role', 'separator'); menu.append(s); return; }
      const b = button('ctx-item'); b.setAttribute('role', 'menuitem');
      const i = el('i', 'fa ' + it.icon); i.setAttribute('aria-hidden', 'true');
      b.append(i, el('span', '', it.label));
      if (it.checked) b.append(el('i', 'fa fa-check ctx-check'));
      if (it.danger) b.classList.add('danger');
      b.addEventListener('click', () => { if (it.keepOpen && it.keepOpen(b)) return; closeMenu(); it.run(); });
      menu.append(b);
    });
    menu.hidden = false;
    const r = menu.getBoundingClientRect();
    menu.style.left = Math.max(6, Math.min(x, innerWidth - r.width - 6)) + 'px';
    menu.style.top = Math.max(6, Math.min(y, innerHeight - r.height - 6)) + 'px';
    menu.querySelector('.ctx-item')?.focus({ preventScroll: true });
  }
  const showAs = [['busy', 'Busy', 'fa-circle'], ['tentative', 'Tentative', 'fa-circle-half-stroke'], ['free', 'Free', 'fa-circle-notch'], ['oof', 'Out of office', 'fa-plane'], ['workingElsewhere', 'Working elsewhere', 'fa-house-laptop']];
  function eventMenu(e) {
    const items = [{ icon: 'fa-up-right-from-square', label: 'Open', run: () => openEvent(e) }];
    if (e.meeting && /^https:/i.test(e.meeting)) items.push({ icon: 'fa-video', label: 'Join meeting', run: () => window.open(e.meeting, '_blank', 'noopener') });
    if (e.url && /^https:/i.test(e.url)) items.push({ icon: 'fa-envelope-open-text', label: 'Open in Outlook', run: () => window.open(e.url, '_blank', 'noopener') });
    if (e.editable) {
      items.push('-');
      showAs.forEach(([value, label, icon]) => items.push({ icon, label: 'Show as ' + label.toLowerCase(), checked: e.busy === value,
        run: () => { if (e.busy !== value) changeEvent(e, { busy: value }, 'Shown as ' + label.toLowerCase()); } }));
    }
    items.push('-', { icon: 'fa-clone', label: 'Duplicate', run: () => openEvent({ ...e, id: undefined, title: e.title + ' (copy)', editable: true, meeting: null, url: null }) });
    items.push({ icon: 'fa-copy', label: 'Copy details', run: async () => {
      const text = `${e.title}\n${e.allDay ? longDate(evStart(e)) + ' (all day)' : longDate(new Date(e.start)) + ', ' + eventTime(e)}${e.location ? '\n' + e.location : ''}${e.meeting ? '\n' + e.meeting : ''}`;
      try { await navigator.clipboard.writeText(text); toast('Copied'); } catch (_) { toast('Could not copy', true); }
    } });
    if (e.editable) {
      items.push('-', { icon: 'fa-trash-can', label: 'Delete', danger: true,
        keepOpen: (b) => { if (b.dataset.confirm === '1') return false; b.dataset.confirm = '1'; b.querySelector('span').textContent = 'Click again to delete'; return true; },
        run: () => removeEvent(e) });
    }
    return items;
  }
  function slotMenu(d, minute) {
    return [
      { icon: 'fa-plus', label: minute !== null ? 'New event at ' + time(new Date(+d + minute * 60000)) : 'New event', run: () => openNew(d, minute) },
      { icon: 'fa-sun', label: 'New all-day event', run: () => openNewAllDay(d) },
      '-',
      { icon: 'fa-calendar-day', label: 'Show this day', run: () => { selected = midnight(d); setView('day'); } },
      { icon: 'fa-calendar-week', label: 'Show this week (CW ' + isoWeek(plusDays(monday(d), 3)) + ')', run: () => { selected = midnight(d); setView('week'); } },
      { icon: 'fa-calendar', label: 'Show this month', run: () => { selected = midnight(d); setView('month'); } },
      { icon: 'fa-location-crosshairs', label: 'Go to today', run: goToday },
    ];
  }
  content.addEventListener('contextmenu', (ev) => {
    const evNode = ev.target.closest('[data-id]');
    if (evNode && store.get(evNode.dataset.id)) { ev.preventDefault(); openMenu(ev.clientX, ev.clientY, eventMenu(store.get(evNode.dataset.id))); return; }
    const column = ev.target.closest('.calendar-time-day');
    if (column) { ev.preventDefault(); openMenu(ev.clientX, ev.clientY, slotMenu(dateAt(column.dataset.date), slotMinute(column, ev.clientY))); return; }
    const cell = ev.target.closest('.calendar-cell[data-date]');
    if (cell) { ev.preventDefault(); openMenu(ev.clientX, ev.clientY, slotMenu(dateAt(cell.dataset.date), null)); }
  });
  menu.addEventListener('keydown', (ev) => {
    const all = [...menu.querySelectorAll('.ctx-item')], i = all.indexOf(document.activeElement);
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); all[(i + (ev.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length].focus(); }
    if (ev.key === 'Escape' || ev.key === 'Tab') { ev.preventDefault(); closeMenu(); }
  });
  document.addEventListener('pointerdown', (ev) => { if (!menu.hidden && !menu.contains(ev.target)) closeMenu(); }, true);
  window.addEventListener('blur', closeMenu);
  content.addEventListener('scroll', closeMenu, { passive: true });

  // month cells: double-click a free spot to create
  content.addEventListener('dblclick', (ev) => {
    const cell = ev.target.closest('.calendar-cell[data-date]');
    if (cell && (ev.target === cell || ev.target.classList.contains('calendar-date')) && !cell.classList.contains('calendar-all-day-cell')) openNew(dateAt(cell.dataset.date));
  });

  // ------------------------------------------------------------ the dialog
  function safeLink(node, href) {
    node.hidden = true; node.removeAttribute('href');
    if (!href) return;
    try { const url = new URL(href); if (url.protocol === 'https:') { node.href = url.href; node.hidden = false; } } catch (_) {}
  }
  function allDayToggle() {
    const all = form.elements.allDay.checked;
    ['start', 'end'].forEach((key) => {
      const input = form.elements[key], previous = input.value;
      input.type = all ? 'date' : 'datetime-local';
      if (previous) input.value = all ? previous.slice(0, 10) : previous.includes('T') ? previous : `${previous}T09:00`;
    });
    $('eventEndLabel').textContent = all ? 'Last day' : 'Ends';
  }
  function canCreate() {
    if (document.body.dataset.connected !== '1' || (connectError && connectError.consent)) { notice('Enable or refresh your Outlook calendar before creating an event.', true); return false; }
    return true;
  }
  function openNew(day = selected, minute = null) {
    if (!canCreate()) return;
    const start = new Date(day); start.setHours(9, 0, 0, 0);
    if (minute !== null && minute !== undefined) start.setHours(Math.floor(minute / 60), minute % 60, 0, 0);
    openEvent({ title: '', start: start.toISOString(), end: new Date(start.getTime() + 3600000).toISOString(), allDay: false, location: '', notes: '', busy: 'busy', editable: true });
  }
  function openNewAllDay(day) {
    if (!canCreate()) return;
    openEvent({ title: '', start: dateKey(day), end: dateKey(plusDays(day, 1)), allDay: true, location: '', notes: '', busy: 'free', editable: true });
  }
  function newTransaction() {
    return typeof crypto.randomUUID === 'function' ? crypto.randomUUID()
      : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
  }
  function openEvent(event) {
    if (pending) return;
    editing = event.id ? event : null;
    transactionId = newTransaction();
    form.reset(); Array.from(form.elements).forEach((n) => { n.disabled = false; });
    form.elements.allDay.checked = event.allDay; allDayToggle();
    for (const key of ['title', 'location', 'notes', 'busy']) form.elements[key].value = event[key] || (key === 'busy' ? 'busy' : '');
    for (const key of ['start', 'end']) form.elements[key].value = event.allDay ? (key === 'end' ? dateKey(plusDays(dateAt(event.end), -1)) : event.start) : localInput(new Date(event[key]));
    $('eventDialogTitle').textContent = editing ? 'Event details' : 'New event';
    $('calendarFormError').hidden = true; $('eventReadOnly').hidden = event.editable;
    $('eventSave').hidden = !event.editable; $('eventDelete').hidden = !editing || !event.editable; $('eventDelete').textContent = 'Delete';
    if (!event.editable) for (const key of ['title', 'location', 'notes', 'busy', 'allDay', 'start', 'end']) form.elements[key].disabled = true;
    safeLink($('eventJoin'), event.meeting); safeLink($('eventOutlook'), event.url);
    dialog.showModal(); form.elements.title.focus();
  }
  function formError(message) { $('calendarFormError').textContent = message; $('calendarFormError').hidden = false; }
  function busy(value) { pending = value; $('eventSave').disabled = value; $('eventDelete').disabled = value; form.querySelectorAll('[data-close]').forEach((n) => { n.disabled = value; }); }
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); if (pending) return;
    const fields = form.elements, allDay = fields.allDay.checked;
    const data = { id: editing?.id || '', transactionId, title: fields.title.value, allDay, timezone, location: fields.location.value, notes: fields.notes.value, busy: fields.busy.value };
    try {
      data.start = allDay ? fields.start.value : new Date(fields.start.value).toISOString();
      data.end = allDay ? dateKey(plusDays(dateAt(fields.end.value), 1)) : new Date(fields.end.value).toISOString();
      if (data.end <= data.start) { formError('End must follow the start.'); return; }
    } catch (_) { formError('Choose valid start and end times.'); return; }
    busy(true); $('calendarFormError').hidden = true;
    try {
      const saved = await api('calendar_save', data);
      store.set(saved.id, saved); saveCache();
      busy(false); dialog.close(); paint(); toast(editing ? 'Event saved' : 'Event created');
      refreshVisible();
    } catch (error) { formError(error.message); if (error.consent) notice(error.message, true); }
    finally { busy(false); }
  });
  async function removeEvent(e) {
    try { await api('calendar_delete', { id: e.id }); store.delete(e.id); saveCache(); paint(); toast('Event deleted'); }
    catch (error) { toast(error.message, true); }
  }
  $('eventDelete').addEventListener('click', async () => {
    if (!editing || pending) return;
    if ($('eventDelete').textContent !== 'Click again to delete') { $('eventDelete').textContent = 'Click again to delete'; $('eventDelete').classList.add('armed'); return; }
    busy(true);
    try { await api('calendar_delete', { id: editing.id }); store.delete(editing.id); saveCache(); busy(false); dialog.close(); paint(); toast('Event deleted'); }
    catch (error) { formError(error.message); }
    finally { busy(false); $('eventDelete').classList.remove('armed'); }
  });
  form.elements.allDay.addEventListener('change', () => { allDayToggle(); if (form.elements.allDay.checked && form.elements.end.value < form.elements.start.value) form.elements.end.value = form.elements.start.value; });
  form.querySelectorAll('[data-close]').forEach((n) => n.addEventListener('click', () => { if (!pending) dialog.close(); }));
  dialog.addEventListener('cancel', (e) => { if (pending) e.preventDefault(); });

  // -------------------------------------------------------------- controls
  function saveView() { try { localStorage.setItem('ecw.calendar.view', view); } catch (_) {} }
  $('calendarView').addEventListener('change', () => setView($('calendarView').value));
  $('calendarSearch').addEventListener('input', paint);
  $('calendarNew').addEventListener('click', () => openNew());
  function goToday() {
    const now = midnight(new Date());
    selected = now; miniMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    if (view === 'month') { scrollToDate(now); selectDay(now); } else show();
  }
  $('calendarToday').addEventListener('click', goToday);
  $('calendarRefresh').addEventListener('click', () => { loadedWeeks.clear(); refreshVisible(); });
  function step(direction) {
    if (view === 'month') { const target = new Date(focusMonth.getFullYear(), focusMonth.getMonth() + direction, 1); scrollToDate(target); return; }
    selected = plusDays(selected, direction * (view === 'week' ? 7 : view === 'agenda' ? 30 : 1));
    miniMonth = new Date(selected.getFullYear(), selected.getMonth(), 1); show();
  }
  $('calendarPrev').addEventListener('click', () => step(-1)); $('calendarNext').addEventListener('click', () => step(1));
  $('miniPrev').addEventListener('click', () => { miniMonth = new Date(miniMonth.getFullYear(), miniMonth.getMonth() - 1, 1); renderMini(); });
  $('miniNext').addEventListener('click', () => { miniMonth = new Date(miniMonth.getFullYear(), miniMonth.getMonth() + 1, 1); renderMini(); });
  document.addEventListener('keydown', (e) => {
    if (dialog.open || e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input,textarea,select,button,a,[contenteditable="true"]')) return;
    if (e.key.toLowerCase() === 't') goToday();
    if (e.key.toLowerCase() === 'n') openNew();
    if (e.key === 'ArrowLeft') step(-1); if (e.key === 'ArrowRight') step(1);
  });
  // Week / Day: a sideways swipe or Shift+wheel passes to the next week or day.
  let wheelX = 0, wheelTimer = null;
  content.addEventListener('wheel', (e) => {
    if (view !== 'week' && view !== 'day') return;
    const dx = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : (e.shiftKey ? e.deltaY : 0);
    if (!dx) return;
    e.preventDefault();
    wheelX += dx; clearTimeout(wheelTimer); wheelTimer = setTimeout(() => { wheelX = 0; }, 250);
    if (Math.abs(wheelX) > 120) { step(wheelX > 0 ? 1 : -1); wheelX = 0; }
  }, { passive: false });
  window.addEventListener('resize', () => {
    if (view !== 'month') return;
    const before = focusMonth;
    content.style.setProperty('--week-h', weekHeight() + 'px');
    paintMonth(); scrollToDate(before, false);
  });
  // Background refresh: what is on screen, every minute and when the tab comes back.
  setInterval(() => { if (!document.hidden && !drag && !dialog.open) refreshVisible(); }, REFRESH_MS);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    const [a, b] = visibleRange();
    if (weeksIn(a, b).some((w) => Date.now() - (loadedWeeks.get(dateKey(w)) || 0) > 30000)) refreshVisible();
  });

  if (ready) $('calendarNew').disabled = false;
  show(true);
})();
