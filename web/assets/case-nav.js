/**
 * Case page: ‹ previous · next › next to the case name, so you can go through
 * cases without going back to the list.
 *   - Opened from the Cases list: the same order as that list (caseList.js
 *     remembers it in this tab when a case is opened), with "3 of 12".
 *   - Opened from anywhere else: the neighbouring case numbers you can see
 *     (api case_neighbors).
 */
(function () {
  'use strict';
  const nav = document.getElementById('caseNav');
  if (!nav) return;
  const caseId = Number(document.body.dataset.case);
  const prev = document.getElementById('casePrevLink');
  const next = document.getElementById('caseNextLink');
  const pos = document.getElementById('caseNavPos');

  function set(link, c, word) {
    if (!c) {
      link.removeAttribute('href');
      link.classList.add('is-off');
      link.title = 'No ' + word.toLowerCase() + ' case';
      link.setAttribute('aria-disabled', 'true');
      return;
    }
    link.href = 'case.php?id=' + encodeURIComponent(String(c.id));
    link.classList.remove('is-off');
    link.removeAttribute('aria-disabled');
    link.title = word + ' case: ' + [c.number, c.name].filter(Boolean).join(' · ');
  }

  let list = null;
  try { list = JSON.parse(sessionStorage.getItem('ecw.caseNav') || 'null'); } catch (_) { list = null; }
  const at = list && Array.isArray(list.cases) ? list.cases.findIndex((c) => Number(c.id) === caseId) : -1;
  if (at >= 0) {
    set(prev, list.cases[at - 1], 'Previous');
    set(next, list.cases[at + 1], 'Next');
    pos.textContent = (at + 1) + ' / ' + list.cases.length;
    pos.title = 'Case ' + (at + 1) + ' of ' + list.cases.length + ' in your Cases list' + (list.label ? ' (' + list.label + ')' : '');
    nav.hidden = false;
  } else {
    fetch('api/index.php?action=case_neighbors&case_id=' + caseId).then((r) => r.ok ? r.json() : null).then((d) => {
      if (!d || (!d.prev && !d.next)) return;
      const shape = (c) => c && { id: c.id, number: c.case_number, name: c.name };
      set(prev, shape(d.prev), 'Previous');
      set(next, shape(d.next), 'Next');
      pos.textContent = '';
      nav.hidden = false;
    }).catch(() => {});
  }

  // Alt+[ and Alt+] go to the previous / next case (not while typing).
  document.addEventListener('keydown', (e) => {
    if (!e.altKey || (e.key !== '[' && e.key !== ']')) return;
    if (e.target.closest?.('input, textarea, select, [contenteditable="true"]')) return;
    const link = e.key === '[' ? prev : next;
    if (link.href) { e.preventDefault(); location.href = link.href; }
  });
})();
