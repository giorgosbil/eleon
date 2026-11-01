/**
 * Case page: what happens next (lib/next_actions.php) — the next step, who
 * does it, who it waits on, the look-again day, and the case's references.
 * Shown as one line under the case name ("➜ Chase the RMA · Maria · waiting
 * on the supplier · look again 14 Oct"); clicking it opens a small panel to
 * change it.
 * Filled by people here, by the "after an email" rule, or by the Next step
 * agent (marked AI, with Undo). Listens for case.js's ecw:case-summary and
 * ecw:org-users; talks to api case_next_save, case_next_undo, case_ref_add,
 * case_ref_delete. Text is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const box = $('caseNext');
  if (!box) return;
  const caseId = Number(document.body.dataset.case);
  const csrf = document.body.dataset.csrf;
  const kinds = JSON.parse($('cnKinds')?.textContent || '{}');
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', 'fa ' + cls); i.setAttribute('aria-hidden', 'true'); return i; };
  async function api(action, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('case_id', String(caseId));
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
  const dayKey = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dayText = (s) => new Date(String(s).replace(' ', 'T') + (String(s).length > 10 ? 'Z' : 'T00:00:00')).toLocaleDateString([], { day: 'numeric', month: 'short' });

  let next = null;
  // ✨ the AI review of the whole case (case-review.js); "Use as next step" repaints the pill.
  const reviewBtn = window.ecwCaseReview?.button(caseId, { onApplied: (n) => { if (n) { next = n; paint(); } } });
  if (reviewBtn) box.after(reviewBtn);
  const action = $('cnAction'), user = $('cnUser'), waiting = $('cnWaiting'), review = $('cnReview');

  function paint() {
    if (!next) return;
    if (document.activeElement !== action) action.value = next.next_action || '';
    if (document.activeElement !== user && user.options.length > 1) user.value = next.next_user_id || '';
    if (document.activeElement !== waiting) waiting.value = next.waiting_on || '';
    if (document.activeElement !== review) review.value = next.review_on || '';
    box.dataset.waiting = next.waiting_on || '';
    box.classList.toggle('cn-empty', !next.next_action);
    paintSummary();
    const today = dayKey(new Date());
    review.classList.toggle('due-late', !!review.value && review.value < today);
    review.classList.toggle('due-today', review.value === today);
    if (next.waiting_on && next.waiting_on !== 'us' && next.waiting_since) waiting.title = 'Since ' + dayText(next.waiting_since);
    else waiting.removeAttribute('title');
    paintBy();
    paintRefs();
  }

  // The one line on the pill.
  const WAITING = { us: 'our turn', customer: 'waiting on the customer', supplier: 'waiting on the supplier', internal: 'waiting on a colleague' };
  function paintSummary() {
    const s = $('cnSummary');
    s.replaceChildren();
    if (!next.next_action && !next.waiting_on) { s.textContent = '+ Next step'; box.title = 'What happens next: click to change'; return; }
    s.append(el('strong', '', next.next_action || 'No next step'));
    const bits = [];
    if (next.next_user_name) bits.push(next.next_user_name.split(' ')[0]);
    if (next.waiting_on) bits.push(WAITING[next.waiting_on] || next.waiting_on);
    if (next.review_on) bits.push('look again ' + dayText(next.review_on));
    if (bits.length) s.append(el('span', 'cn-bits', ' · ' + bits.join(' · ')));
    if (next.by === 'ai') s.append(el('span', 'cn-ai-mini', 'AI'));
    box.title = s.textContent.replace(/AI$/, '').trim() + ' (click to change)';   // all of it, when the pill is shortened
  }

  // The panel: opens under the pill, closes on a click outside or Esc.
  const pop = $('cnPop');
  function setOpen(open) {
    pop.hidden = !open;
    box.setAttribute('aria-expanded', String(open));
    if (open) action.focus();
  }
  box.addEventListener('click', (e) => { e.stopPropagation(); setOpen(pop.hidden); });
  document.addEventListener('click', (e) => { if (!pop.hidden && !pop.contains(e.target) && !box.contains(e.target)) setOpen(false); });
  pop.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !e.target.closest('.cn-ref-form')) { e.stopPropagation(); setOpen(false); box.focus(); } });

  // Who set it last: the AI (with the email it read, and Undo), or the rule after an email.
  function paintBy() {
    const by = $('cnBy');
    by.replaceChildren();
    by.hidden = !next || (next.by !== 'ai' && next.by !== 'rule');
    if (by.hidden) return;
    if (next.by === 'ai') {
      const mark = el('span', 'cn-ai'); mark.append(el('i', 'ecw-ai-icon'), ' AI');
      mark.title = 'Filled in by the AI from the case’s emails. Change anything; your choice stays until a newer email arrives.';
      by.appendChild(mark);
      if (next.source) {
        const src = el('button', 'cn-link', 'from the email of ' + dayText(next.source.at));
        src.type = 'button';
        src.title = next.source.subject || '';
        src.addEventListener('click', () => document.dispatchEvent(new CustomEvent('ecw:jump-to-message', { detail: { message_id: next.source.message_id } })));
        by.appendChild(src);
      }
      if (next.can_undo) {
        const undo = el('button', 'cn-link', 'Undo');
        undo.type = 'button';
        undo.addEventListener('click', async () => {
          try { next = (await api('case_next_undo', {})).next; paint(); toast('Put back as it was'); } catch (e) { toast(e.message, true); }
        });
        by.appendChild(undo);
      }
    } else {
      by.append(icon('fa-envelope'), ' updated after an email');
    }
  }

  async function save(changes, done) {
    try {
      next = (await api('case_next_save', changes)).next;
      paint();
      if (done) toast(done);
    } catch (e) { toast(e.message, true); paint(); }
  }
  action.addEventListener('change', () => save({ next_action: action.value }, action.value.trim() ? 'Next step saved' : 'Next step cleared'));
  action.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); action.blur(); } if (e.key === 'Escape') { action.value = next?.next_action || ''; action.blur(); } });
  user.addEventListener('change', () => save({ next_user_id: user.value || null }, 'Saved'));
  waiting.addEventListener('change', () => save({ waiting_on: waiting.value }, 'Saved'));
  review.addEventListener('change', () => save({ review_on: review.value }, review.value ? 'Reminder on ' + dayText(review.value) : 'No look-again date'));

  // ---------------------------------------------------------------- references
  function paintRefs() {
    const wrap = $('cnRefs');
    if (wrap.contains(document.activeElement) && wrap.querySelector('.cn-ref-form')) return;   // typing a new one
    wrap.replaceChildren();
    (next.refs || []).forEach((r) => {
      const chip = el('span', 'cn-ref' + (r.source === 'ai' ? ' ai' : ''));
      chip.title = r.label + (r.source === 'ai' ? ' — found by the AI in the emails' : '');
      chip.append(el('span', 'cn-ref-kind', r.label), el('span', 'cn-ref-value', r.value));
      const x = el('button', 'cn-ref-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Remove ' + r.label + ' ' + r.value);
      x.addEventListener('click', async () => {
        try { next.refs = (await api('case_ref_delete', { ref_id: r.id })).refs; paintRefs(); } catch (e) { toast(e.message, true); }
      });
      chip.appendChild(x);
      wrap.appendChild(chip);
    });
    const add = el('button', 'cn-ref-add'); add.type = 'button';
    add.append(icon('fa-plus'), ' Reference');
    add.title = 'Add an order, ticket, serial, claim or part number. Searching the case list finds it.';
    add.addEventListener('click', () => openForm(add));
    wrap.appendChild(add);
  }
  function openForm(addBtn) {
    const form = el('form', 'cn-ref-form');
    const kind = el('select', 'ot-select');
    kind.setAttribute('aria-label', 'Kind of reference');
    Object.entries(kinds).forEach(([k, label]) => { const o = el('option', '', label); o.value = k; kind.appendChild(o); });
    const value = el('input'); value.type = 'text'; value.maxLength = 120; value.placeholder = 'Number'; value.setAttribute('aria-label', 'Reference number');
    const ok = el('button', 'cn-ref-ok', 'Add'); ok.type = 'submit';
    const cancel = el('button', 'cn-link', 'Cancel'); cancel.type = 'button';
    form.append(kind, value, ok, cancel);
    addBtn.replaceWith(form);
    value.focus();
    cancel.addEventListener('click', () => { form.remove(); paintRefs(); });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!value.value.trim()) { value.focus(); return; }
      try {
        next.refs = (await api('case_ref_add', { kind: kind.value, value: value.value })).refs;
        form.remove();
        paintRefs();
      } catch (err) { toast(err.message, true); }
    });
    form.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); form.remove(); paintRefs(); } });
  }

  // ------------------------------------------------------------------- wiring
  document.addEventListener('ecw:case-summary', (e) => {
    // A closed case has no next step: hide the pill (it comes back if the case is reopened).
    const wrap = document.querySelector('.cn-wrap');
    if (wrap && e.detail) wrap.hidden = e.detail.status === 'closed';
    if (e.detail && 'next' in e.detail && e.detail.next) { next = e.detail.next; paint(); }
  });
  document.addEventListener('ecw:org-users', (e) => {
    (e.detail || []).filter((u) => !u.disabled_at).forEach((u) => {
      const o = el('option', '', u.name || u.email); o.value = u.id; user.appendChild(o);
    });
    if (next) user.value = next.next_user_id || '';
  });
})();
