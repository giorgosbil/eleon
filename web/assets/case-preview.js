/**
 * The case list's Split layout: the list on the left, the case you pick on the
 * right (its next step, people, references, latest emails, open checklist
 * steps, latest notes), with Open full case / Waiting / Close. Like the mail
 * view, for going through many cases in a row. ↑ ↓ move, Enter opens.
 *
 * Each person picks List or Split (api prefs_save cases_layout); List is the
 * default and is the page exactly as it was. Everything Split needs is in this
 * file, the .cases-split rules in collab.css and the wrapper in cases.php — to
 * take it out again, remove those three.
 * Data: api case_preview. Text is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const pane = $('casePreview');
  if (!pane) return;
  const csrf = document.body.dataset.csrf;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', 'fa ' + cls); i.setAttribute('aria-hidden', 'true'); return i; };
  const split = () => document.body.classList.contains('cases-split');
  let current = null;   // case id on the right
  let seq = 0;

  async function api(action, params, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const r = await fetch(url, body === undefined ? { credentials: 'same-origin' } : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  const utc = (s) => new Date(String(s).replace(' ', 'T') + (String(s).length > 10 ? 'Z' : 'T00:00:00'));
  function when(s) {
    const d = utc(s), now = new Date(), diff = (now - d) / 1000;
    if (diff < 3600) return Math.max(1, Math.round(diff / 60)) + ' min ago';
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (diff < 6 * 86400) return d.toLocaleDateString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
  }
  const day = (s) => utc(s).toLocaleDateString([], { day: 'numeric', month: 'short' });

  // ------------------------------------------------------------------ layout
  function setLayout(layout, save) {
    document.body.classList.toggle('cases-split', layout === 'split');
    document.querySelectorAll('.cases-layout-switch [data-layout]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.layout === layout)));
    if (layout === 'split' && current === null) {
      const first = document.querySelector('#caseTableBody tr[data-case-id]');
      if (first) show(Number(first.dataset.caseId));
    }
    if (save) api('prefs_save', { cases_layout: layout }, {}).catch(() => {});
  }
  document.querySelectorAll('.cases-layout-switch [data-layout]').forEach((b) => b.addEventListener('click', () => setLayout(b.dataset.layout, true)));

  // In Split, a click on a case shows it on the right instead of leaving the list.
  // The checkbox, the ⋮ menu, tags and the checklist chip keep doing their own thing;
  // Ctrl / ⌘ / middle click still open the full case.
  document.addEventListener('click', (e) => {
    if (!split()) return;
    const row = e.target.closest('#caseTableBody tr[data-case-id]');
    if (!row || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    if (e.target.closest('input, details, button, .tag-chip, .cl-chip, .person-cell, [data-pick]')) return;
    e.preventDefault();
    show(Number(row.dataset.caseId));
  }, true);
  document.addEventListener('keydown', (e) => {
    if (!split() || e.altKey || e.ctrlKey || e.metaKey) return;
    if (e.target.closest('input, textarea, select, [contenteditable="true"], .overlay, dialog')) return;
    const rows = [...document.querySelectorAll('#caseTableBody tr[data-case-id]')];
    if (!rows.length) return;
    const at = rows.findIndex((r) => Number(r.dataset.caseId) === current);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const next = rows[Math.min(rows.length - 1, Math.max(0, at + (e.key === 'ArrowDown' ? 1 : -1)))] || rows[0];
      show(Number(next.dataset.caseId));
      next.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && current !== null) {
      e.preventDefault();
      location.href = 'case.php?id=' + current;
    }
  });
  document.addEventListener('ecw:cases-rendered', () => {
    mark();
    if (split() && (current === null || !document.querySelector('#caseTableBody tr[data-case-id="' + current + '"]'))) {
      const first = document.querySelector('#caseTableBody tr[data-case-id]');
      if (first) show(Number(first.dataset.caseId)); else empty();
    }
  });
  function mark() {
    document.querySelectorAll('#caseTableBody tr[data-case-id]').forEach((r) => r.classList.toggle('previewing', Number(r.dataset.caseId) === current));
  }
  function empty() {
    current = null;
    pane.replaceChildren();
    const box = el('div', 'cp-empty');
    box.append(icon('fa-hand-pointer'), el('p', '', 'No case to show with these filters.'));
    pane.appendChild(box);
  }

  // ------------------------------------------------------------------ the preview
  async function show(caseId) {
    current = caseId;
    mark();
    const n = ++seq;
    pane.classList.add('loading');
    try {
      const d = await api('case_preview', { case_id: caseId });
      if (n !== seq) return;
      render(d);
    } catch (e) {
      if (n !== seq) return;
      pane.replaceChildren(el('div', 'cp-empty', e.message));
    } finally {
      if (n === seq) pane.classList.remove('loading');
    }
  }

  const WAITING = { us: 'our turn', customer: 'waiting on the customer', supplier: 'waiting on the supplier', internal: 'waiting on a colleague' };
  const STATUS = { open: 'Open', waiting: 'Waiting', closed: 'Closed' };
  function render(d) {
    const c = d.case;
    pane.replaceChildren();
    pane.scrollTop = 0;

    const head = el('header', 'cp-head');
    const title = el('div', 'cp-title');
    const num = el('a', 'case-badge', c.number); num.href = 'case.php?id=' + c.id;
    const st = el('span', 'cp-status cp-' + c.status, STATUS[c.status] || c.status);
    title.append(num, st);
    if (d.sla) title.append(el('span', 'sla-pill ' + d.sla.state, d.sla.state === 'overdue' ? 'reply overdue' : 'reply due'));
    const name = el('h2', '', c.name);
    const open = el('a', 'cp-open primary', ''); open.href = 'case.php?id=' + c.id;
    open.append('Open full case ', icon('fa-arrow-right'));
    head.append(title, name, open);
    pane.appendChild(head);

    // What happens next.
    if (d.next) {
      const nx = d.next;
      const box = el('a', 'cp-next' + (nx.next_action ? '' : ' none'));
      box.href = 'case.php?id=' + c.id;
      box.dataset.waiting = nx.waiting_on || '';
      box.append(el('span', 'cn-dot'));
      const txt = el('span', '');
      txt.append(el('strong', '', nx.next_action || 'No next step yet'));
      const bits = [nx.next_user_name, nx.waiting_on ? WAITING[nx.waiting_on] : null, nx.review_on ? 'look again ' + day(nx.review_on) : null].filter(Boolean);
      if (bits.length) txt.append(el('span', 'muted', ' · ' + bits.join(' · ')));
      if (nx.by === 'ai') txt.append(el('span', 'cn-ai-mini', 'AI'));
      box.append(txt);
      pane.appendChild(box);
    }

    // People and dates.
    const facts = el('dl', 'cp-facts');
    const fact = (k, v) => { if (!v) return; const w = el('div'); w.append(el('dt', '', k), el('dd', '', v)); facts.appendChild(w); };
    fact('Responsible', c.owner || '—');
    if (c.sharing && c.sharing.group) fact('Group', c.sharing.group.name);
    fact('Due', c.due_at ? day(c.due_at) : null);
    fact('Conversations', String(d.counts.conversations));
    fact('Emails', String(d.counts.emails));
    if (d.counts.files) fact('Files', String(d.counts.files));
    fact('Last activity', c.last_activity ? when(c.last_activity) : null);
    pane.appendChild(facts);
    if (d.next && d.next.refs && d.next.refs.length) {
      const refs = el('div', 'cp-refs');
      d.next.refs.forEach((r) => { const chip = el('span', 'cn-ref'); chip.append(el('span', 'cn-ref-kind', r.label), el('span', 'cn-ref-value', r.value)); refs.appendChild(chip); });
      pane.appendChild(refs);
    }

    // Latest emails.
    pane.appendChild(el('h3', '', 'Latest emails'));
    if (!d.emails.length) pane.appendChild(el('p', 'muted small', 'No emails in this case yet.'));
    const list = el('ul', 'cp-mails');
    d.emails.forEach((m) => {
      const li = el('li', m.ours ? 'ours' : 'theirs');
      const a = el('a'); a.href = 'case.php?id=' + c.id + '#msg-' + m.id;
      const top = el('div', 'cp-mail-top');
      const who = el('span', 'cp-who');
      who.append(icon(m.ours ? 'fa-reply' : 'fa-inbox'), ' ', m.ours ? 'Us · ' + m.from : m.from);
      top.append(who, el('span', 'muted small', when(m.at)));
      a.append(top, el('div', 'cp-subj', (m.files ? '📎 ' : '') + (m.subject || '(no subject)')));
      if (m.snippet) a.append(el('div', 'cp-snip', m.snippet));
      li.appendChild(a);
      list.appendChild(li);
    });
    pane.appendChild(list);

    // Open checklist steps.
    if (d.steps.length) {
      pane.appendChild(el('h3', '', 'Open steps (' + d.steps_open + ')'));
      const ul = el('ul', 'cp-steps');
      d.steps.forEach((s) => {
        const li = el('li', s.late ? 'late' : '');
        li.append(el('span', '', '☐ ' + s.text));
        const meta = [s.assignee, s.due_date ? day(s.due_date) : null].filter(Boolean).join(' · ');
        if (meta) li.append(el('span', 'muted small', ' ' + meta));
        ul.appendChild(li);
      });
      pane.appendChild(ul);
    }

    // Latest notes.
    if (d.notes.length) {
      pane.appendChild(el('h3', '', 'Latest notes'));
      d.notes.forEach((n) => {
        const box = el('div', 'cp-note');
        box.append(el('div', 'muted small', (n.by || '') + ' · ' + when(n.at)), el('div', '', n.text));
        pane.appendChild(box);
      });
    }

    // Quick actions.
    const actions = el('div', 'cp-actions');
    const act = (label, ic, changes, done) => {
      const b = el('button', ''); b.type = 'button'; b.append(icon(ic), ' ' + label);
      b.addEventListener('click', async () => {
        b.disabled = true;
        try {
          await api('case_update', { case_id: c.id }, changes);
          window.ecwCaseListRefresh?.();
          show(c.id);
          const t = $('toast'); if (t && $('toastMsg')) { $('toastMsg').textContent = done; t.hidden = false; clearTimeout(t._timer); t._timer = setTimeout(() => { t.hidden = true; }, 2600); }
        } catch (e) { b.disabled = false; alert(e.message); }
      });
      actions.appendChild(b);
    };
    if (c.status !== 'waiting' && c.status !== 'closed') act('Waiting', 'fa-hourglass-half', { status: 'waiting' }, 'Marked as waiting');
    if (c.status !== 'closed') act('Close case', 'fa-check', { status: 'closed' }, 'Case closed');
    else act('Reopen', 'fa-rotate-left', { status: 'open' }, 'Case reopened');
    pane.appendChild(actions);
  }

  // Start: Split shows the first case straight away.
  if (split()) setLayout('split', false);
})();
