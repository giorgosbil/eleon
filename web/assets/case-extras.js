/**
 * Case page: due date, tags, merge into another case, and the History tab.
 * Listens for case.js's ecw:case-summary event; talks to api case_update,
 * case_tags_save, case_tag_suggestions, cases, case_merge and case_history.
 * Text is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const caseId = Number(document.body.dataset.case);
  const csrf = document.body.dataset.csrf;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  async function api(action, params, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v)); });
    const r = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  function toast(msg, isError) {
    const t = $('toast'); if (!t) return;
    $('toastMsg').textContent = msg; t.classList.toggle('error', !!isError); t.hidden = false;
    const a = $('toastAction'); if (a) a.hidden = true;
    clearTimeout(t._timer); t._timer = setTimeout(() => { t.hidden = true; }, isError ? 6000 : 2600);
  }

  // ------------------------------------------- phone: facts fold to two lines
  // Status and Responsible stay; "More details" shows the rest (collab.css hides it only on a phone).
  const facts = document.querySelector('.case-facts');
  if (facts) {
    const more = el('button', 'case-facts-toggle', 'More details');
    more.type = 'button';
    more.setAttribute('aria-expanded', 'false');
    more.addEventListener('click', () => {
      const open = document.querySelector('.case-top').classList.toggle('facts-open');
      more.textContent = open ? 'Fewer details' : 'More details';
      more.setAttribute('aria-expanded', String(open));
    });
    facts.after(more);
  }

  // ------------------------------------------------------------------ due date
  const due = $('caseDue');
  let lastDue = null;
  due?.addEventListener('change', async () => {
    try {
      await api('case_update', { case_id: caseId }, { case_id: caseId, due_at: due.value });
      lastDue = due.value;
      toast(due.value ? 'Due ' + new Date(due.value + 'T00:00:00').toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' }) : 'Due date removed');
      paintDue();
    } catch (e) { due.value = lastDue || ''; toast(e.message, true); }
  });
  function paintDue() {
    if (!due) return;
    const today = new Date(); const key = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    due.classList.toggle('due-late', !!due.value && due.value < key && $('caseStatus')?.dataset.base !== 'closed');
    due.classList.toggle('due-today', due.value === key && $('caseStatus')?.dataset.base !== 'closed');
  }

  // ---------------------------------------------------------------------- tags
  const box = $('caseTags');
  let tags = [], suggestions = null;
  function drawTags() {
    if (!box) return;
    box.replaceChildren();
    tags.forEach((t) => {
      const chip = el('span', 'tag-chip');
      // Its colour (Settings → Case settings → Tags).
      const color = ((window.ecwCaseSettings || {}).tag_colors || {})[String(t).toLowerCase()];
      if (color) { chip.style.background = 'color-mix(in srgb, ' + color + ' 14%, var(--bg))'; chip.style.color = color; }
      const link = el('a', '', t); link.href = 'cases.php?tag=' + encodeURIComponent(t) + '&status=all'; link.title = 'All cases tagged “' + t + '”';
      const x = el('button', 'tag-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Remove tag ' + t);
      x.addEventListener('click', () => saveTags(tags.filter((v) => v !== t)));
      chip.append(link, x);
      box.appendChild(chip);
    });
    const input = el('input', 'tag-input');
    input.type = 'text'; input.placeholder = tags.length ? '+ tag' : 'Add a tag'; input.maxLength = 40; input.setAttribute('list', 'caseTagSuggestions');
    input.setAttribute('aria-label', 'Add a tag');
    input.addEventListener('focus', loadSuggestions, { once: true });
    input.addEventListener('keydown', (e) => {
      if ((e.key === 'Enter' || e.key === ',') && input.value.trim()) { e.preventDefault(); saveTags([...tags, input.value.trim()]); }
      if (e.key === 'Backspace' && !input.value && tags.length) saveTags(tags.slice(0, -1));
    });
    input.addEventListener('change', () => { if (input.value.trim() && suggestions?.includes(input.value.trim().toLowerCase())) saveTags([...tags, input.value.trim()]); });
    box.appendChild(input);
  }
  async function loadSuggestions() {
    if (suggestions) return;
    try { suggestions = (await api('case_tag_suggestions')).tags; } catch (_) { suggestions = []; }
    let list = $('caseTagSuggestions');
    if (!list) { list = el('datalist'); list.id = 'caseTagSuggestions'; document.body.appendChild(list); }
    list.replaceChildren(...suggestions.map((s) => { const o = el('option'); o.value = s; return o; }));
  }
  async function saveTags(next) {
    try {
      tags = (await api('case_tags_save', { case_id: caseId }, { case_id: caseId, tags: next })).tags;
      drawTags();
      box.querySelector('.tag-input')?.focus();
    } catch (e) { toast(e.message, true); }
  }

  document.addEventListener('ecw:case-summary', (e) => {
    const s = e.detail;
    if (due && document.activeElement !== due) { due.value = s.due_at || ''; lastDue = due.value; paintDue(); }
    const next = s.tags || [];
    if (box && !box.contains(document.activeElement) && (!box.childElementCount || JSON.stringify(next) !== JSON.stringify(tags))) { tags = next; drawTags(); }
    paintSla(s.sla);
  });

  // ------------------------------------------------- reply clock (lib/sla.php)
  function paintSla(c) {
    const fact = $('caseSlaFact'), out = $('caseSla');
    if (!fact || !out) return;
    fact.hidden = !c;
    out.textContent = '';
    if (!c) return;
    const abs = Math.abs(c.left_seconds);
    const t = abs < 3600 ? Math.max(1, Math.round(abs / 60)) + ' min' : abs < 172800 ? (abs / 3600).toFixed(1).replace(/\.0$/, '') + ' h' : (abs / 86400).toFixed(1).replace(/\.0$/, '') + ' d';
    const pill = document.createElement('span');
    pill.className = 'sla-pill ' + c.state;
    const i = document.createElement('i'); i.className = 'fa fa-stopwatch'; i.setAttribute('aria-hidden', 'true');
    pill.append(i, ' ' + (c.state === 'overdue' ? 'overdue ' + t : t + ' left'));
    const due = new Date(String(c.due_at).replace(' ', 'T') + 'Z');
    pill.title = 'Waiting for our reply since ' + new Date(String(c.waiting_since).replace(' ', 'T') + 'Z').toLocaleString()
      + ' · reply by ' + due.toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      + ' (' + c.target_hours + ' business hours)';
    out.appendChild(pill);
  }

  // --------------------------------------------------------------------- merge
  $('caseMergeBtn')?.addEventListener('click', () => {
    const overlay = el('div', 'overlay');
    const dlg = el('div', 'dialog');
    dlg.setAttribute('role', 'dialog'); dlg.setAttribute('aria-modal', 'true'); dlg.setAttribute('aria-label', 'Merge into another case');
    const head = el('h2', '', 'Merge into another case');
    const help = el('p', 'muted', 'Everything in this case — conversations, notes, reminders, Teams chats, people and tags — moves into the case you choose. This case is then closed with a link to it.');
    const search = el('input'); search.type = 'search'; search.placeholder = 'Search by number, name or participant';
    const list = el('ul', 'case-results');
    const footer = el('footer');
    const cancel = el('button', '', 'Cancel'); cancel.type = 'button';
    footer.appendChild(cancel);
    dlg.append(head, help, search, list, footer);
    overlay.appendChild(dlg); document.body.appendChild(overlay);
    const close = () => overlay.remove();
    cancel.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    let seq = 0;
    async function find() {
      const n = ++seq;
      try {
        const d = await api('cases', { q: search.value.trim(), status: 'all' });
        if (n !== seq) return;
        list.replaceChildren();
        d.cases.filter((c) => Number(c.id) !== caseId).slice(0, 20).forEach((c) => {
          const li = el('li'); const b = el('button', '', c.case_number + ' · ' + c.name + ' (' + c.status + ')'); b.type = 'button';
          b.addEventListener('click', async () => {
            if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Click again to merge into ' + c.case_number; return; }
            try {
              const r = await api('case_merge', { case_id: caseId }, { case_id: caseId, into: c.id });
              location.href = 'case.php?id=' + r.case_id;
            } catch (e) { toast(e.message, true); }
          });
          li.appendChild(b); list.appendChild(li);
        });
        if (!list.children.length) list.appendChild(el('li', 'muted', 'No other case matches.'));
      } catch (e) { list.replaceChildren(el('li', 'muted', e.message)); }
    }
    let t = null;
    search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(find, 200); });
    search.focus(); find();
  });

  // ------------------------------------------------------------------- history
  async function loadHistory() {
    const list = $('historyList'); if (!list) return;
    try {
      const { history } = await api('case_history', { case_id: caseId });
      list.replaceChildren();
      history.forEach((h) => {
        const li = el('li', 'history-item');
        const who = el('strong', '', h.who);
        const text = el('span', '', ' ' + h.text);
        const at = el('time', 'muted', new Date(String(h.at).replace(' ', 'T') + 'Z').toLocaleString([], { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }));
        li.append(who, text);
        if (h.link_case) { const a = el('a', '', ' Open'); a.href = 'case.php?id=' + h.link_case; li.appendChild(a); }
        li.appendChild(at);
        list.appendChild(li);
      });
      if (!history.length) list.appendChild(el('li', 'muted', 'Nothing recorded yet.'));
    } catch (e) { list.replaceChildren(el('li', 'muted', e.message)); }
  }
  document.querySelector('[role="tab"][data-tab="history"]')?.addEventListener('click', loadHistory);

  // ------------------------------------------- find inside the case's emails
  // assets/find-in-mail.js: highlights matches across every column of the timeline.
  const find = window.ecwFindInMail && $('caseFindHost') ? window.ecwFindInMail({
    root: () => $('timeline'),
    scope: '.bubble-body, .bubble-quoted:not([hidden]), .bubble-from, .bubble-attachments .attachment',
    host: $('caseFindHost'),
    expand: (hit) => {
      // A clamped long email opens fully so the match can be seen.
      const body = hit.closest('.bubble-body.is-collapsed');
      if (!body) return;
      body.classList.remove('is-collapsed');
      const more = body.parentElement.querySelector('.bubble-expand');
      if (more) { more.textContent = 'Show less'; more.setAttribute('aria-expanded', 'true'); }
    },
  }) : null;
  if (find) {
    // Wide window: the 🔍 button opens out into the search bar right where it is,
    // in the toolbar. Narrow (no room there): the bar opens above the timeline, as before.
    const findBtn = $('caseFindBtn');
    const bar = $('caseFindHost')?.querySelector('.find-bar');
    // The button becomes the bar, in its place; the buttons to its left slide over
    // and show only their icons (their words come back when the search closes).
    const actions = findBtn?.parentElement;
    if (actions) {
      // Each button's words in their own span, so they can fold away smoothly.
      actions.querySelectorAll(':scope > button, :scope > a').forEach((b) => {
        if (b.classList.contains('primary') || b === findBtn) return;
        [...b.childNodes].forEach((n) => {
          if (n.nodeType === 3 && n.textContent.trim()) { const s = document.createElement('span'); s.className = 'btn-words'; s.textContent = n.textContent; n.replaceWith(s); }
        });
        if (b.querySelector('.btn-words') && !b.title) b.title = b.textContent.trim();
      });
    }
    const placeBar = () => {
      if (!findBtn || !bar) return;
      const inline = window.innerWidth >= 1100 && !bar.hidden;
      if (inline && bar.parentElement !== actions) {
        findBtn.after(bar);
        bar.classList.add('find-inline');
        findBtn.hidden = true;
        actions.classList.add('is-searching');
        requestAnimationFrame(() => requestAnimationFrame(() => bar.classList.add('is-open')));
      } else if (!inline && bar.parentElement === actions) {
        bar.classList.remove('find-inline', 'is-open');
        actions.classList.remove('is-searching');
        findBtn.hidden = false;
        $('caseFindHost').prepend(bar);
      }
    };
    if (bar) {
      new MutationObserver(placeBar).observe(bar, { attributes: true, attributeFilter: ['hidden'] });
      window.addEventListener('resize', placeBar);
    }
    findBtn?.addEventListener('click', () => { find.jump(); placeBar(); bar?.querySelector('input')?.focus(); });   // open, or back to the highlighted match
    document.addEventListener('keydown', (e) => {
      if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'f' || e.shiftKey || e.altKey) return;
      if (e.target.closest?.('textarea, [contenteditable="true"], input:not(.find-bar input), iframe')) return;
      e.preventDefault();
      find.jump();
    });
  }
})();
