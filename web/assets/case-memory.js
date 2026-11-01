/**
 * Case page: Solutions memory (lib/case_memory.php).
 *   - "Might be the same issue as CASE-00012", with that case's problem and
 *     solution, and Yes / No (Undo after Yes).
 *   - On a closed case, the problem and solution summary the AI wrote, which
 *     people can correct (a correction is never overwritten).
 * Compact rows under the case header; listens for case.js's ecw:case-summary.
 */
(function () {
  'use strict';
  const box = document.getElementById('caseMemory');
  if (!box) return;
  const caseId = Number(document.body.dataset.case);
  const csrf = () => document.body.dataset.csrf || '';
  let status = '';
  let data = { resolution: null, similar: [] };
  let pollTimer = 0;
  let editing = false;
  const open = new Set();

  async function api(action, body) {
    const url = 'api/index.php?action=' + action + '&case_id=' + caseId;
    const res = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body) });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(d.error || 'Request failed (' + res.status + ')');
    return d;
  }
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const btn = (label, cls, fn) => { const b = el('button', 'cm-btn' + (cls ? ' ' + cls : ''), label); b.type = 'button'; b.addEventListener('click', fn); return b; };
  const toast = (m, bad) => (window.ecwPageToast ? window.ecwPageToast(m, bad) : alert(m));
  const dateOf = (s) => (s ? new Date(s.replace(' ', 'T') + 'Z').toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }) : '');

  async function act(action, body, busyBtn) {
    if (busyBtn) { busyBtn.disabled = true; busyBtn.dataset.label = busyBtn.textContent; busyBtn.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> ' + busyBtn.dataset.label; }
    try { data = await api(action, body); render(); return true; }
    catch (e) { toast(e.message, true); if (busyBtn) { busyBtn.disabled = false; busyBtn.textContent = busyBtn.dataset.label; } return false; }
  }

  function detail(problem, solution) {
    const d = el('div', 'cm-detail');
    const p = el('p'); p.append(el('strong', '', 'Problem: '), problem || '—');
    const s = el('p'); s.append(el('strong', '', 'Solution: '), solution || '—');
    d.append(p, s);
    return d;
  }

  function similarRow(s) {
    const row = el('div', 'cm-row cm-similar' + (s.state === 'yes' ? ' is-yes' : ''));
    const head = el('div', 'cm-head');
    head.appendChild(el('span', 'cm-icon', s.state === 'yes' ? '✓' : '💡'));
    const text = el('span', 'cm-text');
    const link = el('a', 'cm-case', s.case_number + ' · ' + s.name);
    link.href = 'case.php?id=' + s.case_id; link.target = '_blank'; link.rel = 'noopener';
    if (s.state === 'yes') {
      text.append('Same issue as ', link, s.solution ? ' — ' : '', el('span', 'cm-sol', s.solution));
    } else {
      text.append('Might be the same issue as ', link);
      if (s.closed_at) text.append(el('span', 'muted', ' (closed ' + dateOf(s.closed_at) + ')'));
      if (s.reason) text.append(el('span', 'cm-reason', ' — ' + s.reason));
    }
    head.appendChild(text);
    const actions = el('span', 'cm-actions');
    const key = 's' + s.case_id;
    actions.appendChild(btn(open.has(key) ? 'Hide' : 'Show solution', 'cm-link', () => { open.has(key) ? open.delete(key) : open.add(key); render(); }));
    if (s.state === 'yes') {
      actions.appendChild(btn('Undo', 'cm-link', (e) => act('case_similar_decide', { similar_case_id: s.case_id, answer: 'undo' }, e.currentTarget)));
    } else {
      actions.appendChild(btn('Yes, same issue', 'cm-yes', (e) => act('case_similar_decide', { similar_case_id: s.case_id, answer: 'yes' }, e.currentTarget).then((ok) => ok && open.add(key) && render())));
      actions.appendChild(btn('No', 'cm-no', (e) => act('case_similar_decide', { similar_case_id: s.case_id, answer: 'no' }, e.currentTarget)));
    }
    head.appendChild(actions);
    row.appendChild(head);
    if (open.has(key)) row.appendChild(detail(s.problem, s.solution));
    return row;
  }

  function editor(r) {
    const f = el('form', 'cm-edit');
    const p = el('textarea'); p.rows = 2; p.maxLength = 2000; p.value = r?.problem || ''; p.placeholder = 'What was the problem?';
    const s = el('textarea'); s.rows = 2; s.maxLength = 2000; s.value = r?.solution || ''; s.placeholder = 'How was it solved?';
    const lp = el('label', '', 'Problem'); lp.appendChild(p);
    const ls = el('label', '', 'Solution'); ls.appendChild(s);
    const solved = el('label', 'cm-check'); const cb = el('input'); cb.type = 'checkbox'; cb.checked = r ? r.solved !== false : true;
    solved.append(cb, ' Solved (suggest it for similar new cases)');
    const row = el('div', 'cm-actions');
    const save = el('button', 'cm-btn cm-yes', 'Save'); save.type = 'submit';
    row.append(save, btn('Cancel', 'cm-link', () => { editing = false; render(); }));
    f.append(lp, ls, solved, row);
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (await act('case_solution_save', { problem: p.value, solution: s.value, solved: cb.checked }, save)) { editing = false; render(); toast('Saved. The AI will not change it.'); }
    });
    setTimeout(() => p.focus(), 0);
    return f;
  }

  function resolutionRow() {
    const r = data.resolution;
    const closed = status === 'closed';
    if (!r && !closed) return null;
    const row = el('div', 'cm-row cm-resolution');
    if (editing) { row.appendChild(editor(r)); return row; }
    const head = el('div', 'cm-head');
    head.appendChild(el('span', 'cm-icon', '📘'));
    const text = el('span', 'cm-text');
    const actions = el('span', 'cm-actions');
    if (!r || r.state === 'pending') {
      if (r) text.append(el('span', 'ai-spin', ''), ' The AI is reading the case to write down the problem and its solution…');
      else text.append('No problem and solution summary yet.');
      actions.append(btn(r ? 'Write it now' : 'Write it with AI', 'cm-link', (e) => act('case_solution_write', {}, e.currentTarget)), btn('Write it myself', 'cm-link', () => { editing = true; render(); }));
    } else if (r.state === 'failed') {
      text.append('The AI could not write the problem and solution' + (r.error ? ' (' + r.error + ')' : '') + '.');
      actions.append(btn('Try again', 'cm-link', (e) => act('case_solution_write', {}, e.currentTarget)), btn('Write it myself', 'cm-link', () => { editing = true; render(); }));
    } else {
      text.append(el('strong', '', 'Solution summary: '), r.problem ? r.problem.slice(0, 140) + (r.problem.length > 140 ? '…' : '') : '');
      if (!r.solved) text.append(el('span', 'muted', ' (not solved: not suggested for new cases)'));
      actions.append(btn(open.has('r') ? 'Hide' : 'Show', 'cm-link', () => { open.has('r') ? open.delete('r') : open.add('r'); render(); }), btn('Edit', 'cm-link', () => { editing = true; render(); }));
    }
    head.append(text, actions);
    row.appendChild(head);
    if (r && r.state === 'done' && open.has('r')) {
      row.appendChild(detail(r.problem, r.solution));
      const by = el('p', 'cm-by muted', (r.written_by === 'user' ? 'Written by ' + (r.edited_by || 'a colleague') : 'Written by AI from the emails and notes') + ' · ' + dateOf(r.updated_at));
      if (r.written_by === 'user') by.append(' · ', btn('Let the AI write it again', 'cm-link', (e) => act('case_solution_write', {}, e.currentTarget)));
      row.appendChild(by);
    }
    return row;
  }

  function render() {
    box.textContent = '';
    (data.similar || []).forEach((s) => box.appendChild(similarRow(s)));
    const r = resolutionRow();
    if (r) box.appendChild(r);
    box.hidden = !box.children.length;
    clearTimeout(pollTimer);
    // The summary is written in the background a few seconds after closing.
    if (data.resolution && data.resolution.state === 'pending' && status === 'closed') pollTimer = setTimeout(load, 6000);
  }

  async function load() {
    try { data = await api('case_memory'); render(); } catch (_) { /* quiet: an optional strip */ }
  }

  document.addEventListener('ecw:case-summary', (e) => {
    const s = e.detail?.status || '';
    if (s === status) return;
    const changed = status !== '';
    status = s;
    if (changed) setTimeout(load, s === 'closed' ? 800 : 0); else load();
  });
})();
