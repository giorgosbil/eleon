/**
 * The ✨ AI review of a case (lib/case_review.php), on the case page next to
 * "Next step" and in the case list's Next step column.
 *
 *   window.ecwCaseReview.button(caseId, { onApplied })  -> the small icon button
 *
 * The panel says where the case stands for support and sales: how long the
 * customer has gone without news from us, how long a supplier has been silent
 * (days counted by the server from the emails), the open points and risks, a
 * suggested next step ("Use as next step") and, ready to edit, an update for
 * the customer and a chaser for the supplier — each opens as a reply in its
 * conversation. A kept review opens instantly and costs nothing.
 * Text is written with textContent.
 */
(function () {
  'use strict';
  if (window.ecwCaseReview) return;
  if (window.ecwFeatures && window.ecwFeatures.ai === false) { window.ecwCaseReview = { button: () => null }; return; }
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; };
  const csrf = () => document.body.dataset.csrf || window.ecwCsrf || '';
  const WAITING = { us: 'Us', customer: 'Customer', supplier: 'Supplier', internal: 'Colleague' };
  const URGENCY = { ok: ['Up to date', 'ok'], soon: ['Needs attention', 'soon'], now: ['Act today', 'now'] };

  async function api(action, caseId, params, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('case_id', String(caseId));
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body || {}) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  function toast(msg, isError) {
    const t = document.getElementById('toast');
    if (!t) return;
    const m = document.getElementById('toastMsg') || t;
    m.textContent = msg; t.classList.toggle('error', !!isError); t.hidden = false;
    const a = document.getElementById('toastAction'); if (a) a.hidden = true;
    clearTimeout(t._timer); t._timer = setTimeout(() => { t.hidden = true; }, isError ? 6000 : 2600);
  }
  const days = (n) => n === 0 ? 'today' : n === 1 ? '1 working day' : n + ' working days';
  const ago = (s) => {
    const mins = Math.max(0, Math.round((Date.now() - new Date(String(s).replace(' ', 'T') + 'Z').getTime()) / 60000));
    return mins < 1 ? 'just now' : mins < 60 ? mins + ' min ago' : mins < 1440 ? Math.round(mins / 60) + ' h ago' : new Date(String(s).replace(' ', 'T') + 'Z').toLocaleDateString();
  };

  let pop = null;
  function close() {
    if (!pop) return;
    pop.remove(); pop = null;
    document.removeEventListener('pointerdown', outside, true);
    document.removeEventListener('keydown', onKey, true);
  }
  function outside(e) { if (pop && !pop.contains(e.target) && !e.target.closest('.crv-btn')) close(); }
  function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }

  function place(anchor) {
    const r = anchor.getBoundingClientRect();
    const w = Math.min(440, window.innerWidth - 16);
    pop.style.width = w + 'px';
    pop.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left)) + 'px';
    const below = window.innerHeight - r.bottom - 12;
    if (below >= 320 || below >= r.top) { pop.style.top = (r.bottom + 6) + 'px'; pop.style.maxHeight = Math.max(240, below) + 'px'; }
    else { pop.style.bottom = (window.innerHeight - r.top + 6) + 'px'; pop.style.maxHeight = (r.top - 12) + 'px'; }
  }

  function open(anchor, caseId, opts) {
    if (pop && pop.dataset.case === String(caseId)) { close(); return; }
    close();
    pop = el('section', 'crv-pop');
    pop.dataset.case = String(caseId);
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', 'AI review of the case');
    document.body.appendChild(pop);
    place(anchor);
    setTimeout(() => { document.addEventListener('pointerdown', outside, true); document.addEventListener('keydown', onKey, true); }, 0);
    load(caseId, opts, false);
  }

  async function load(caseId, opts, fresh) {
    const box = pop;
    if (!box) return;
    box.replaceChildren(header(caseId, opts, null), loading());
    try {
      const r = await api('case_review', caseId, fresh ? { fresh: 1 } : {});
      if (pop !== box) return;
      draw(box, caseId, opts, r.review);
    } catch (e) {
      if (pop !== box) return;
      const err = el('div', 'crv-error');
      err.append(icon('fa fa-triangle-exclamation'), document.createTextNode(' ' + e.message));
      box.replaceChildren(header(caseId, opts, null), err);
    }
  }
  function loading() {
    const l = el('div', 'crv-loading');
    l.setAttribute('role', 'status');
    l.append(icon('fa fa-circle-notch fa-spin'), document.createTextNode(' Reading the whole case…'));
    return l;
  }

  function header(caseId, opts, review) {
    const h = el('header', 'crv-head');
    const title = el('strong', 'crv-title');
    title.append(icon('ecw-ai-icon'), document.createTextNode(' Case review'));
    h.appendChild(title);
    if (review) {
      const [label, cls] = URGENCY[review.facts?.urgency] || URGENCY.ok;
      h.appendChild(el('span', 'crv-urgency ' + cls, label));
    }
    const tools = el('span', 'crv-tools');
    if (review) {
      const again = el('button', 'crv-icon');
      again.type = 'button'; again.title = 'Review again (reads the case anew)'; again.setAttribute('aria-label', again.title);
      again.appendChild(icon('fa fa-rotate-right'));
      again.addEventListener('click', () => load(caseId, opts, true));
      tools.appendChild(again);
    }
    const x = el('button', 'crv-icon');
    x.type = 'button'; x.title = 'Close'; x.setAttribute('aria-label', 'Close');
    x.appendChild(icon('fa fa-xmark'));
    x.addEventListener('click', close);
    tools.appendChild(x);
    h.appendChild(tools);
    return h;
  }

  function lane(kind, facts, sentence, draft) {
    const f = facts[kind] || {};
    const lane = el('div', 'crv-lane');
    let line = '', alarm = false;
    if (kind === 'customer') {
      if (!f.known) line = 'No customer conversation in this case';
      else if (f.unanswered) { line = 'Waiting for our answer: ' + days(f.unanswered_days); alarm = f.needs_update; }
      else if (f.days_without_update !== null) { line = f.days_without_update === 0 ? 'We updated them today' : 'No news from us for ' + days(f.days_without_update); alarm = f.needs_update; }
    } else {
      if (!f.known) line = 'No supplier conversation in this case';
      else if (f.waiting) { line = f.silent_days === 0 ? 'Asked today' : 'No answer for ' + days(f.silent_days); alarm = f.needs_chase; }
      else line = 'Nothing pending: they answered last';
    }
    if (!f.known && !sentence && !draft) return null;
    lane.classList.toggle('alarm', !!alarm);
    const head = el('div', 'crv-lane-head');
    head.append(icon('fa ' + (kind === 'customer' ? 'fa-user' : 'fa-industry')), el('strong', '', kind === 'customer' ? 'Customer' : 'Supplier'), el('span', 'crv-fact', line));
    lane.appendChild(head);
    if (sentence) lane.appendChild(el('p', 'crv-sentence', sentence));
    if (draft) {
      const b = el('button', 'crv-draft' + (alarm ? ' primary' : ''));
      b.type = 'button';
      b.append(icon('fa fa-envelope'), document.createTextNode(kind === 'customer' ? ' Update the customer' : ' Chase the supplier'));
      b.title = 'Opens a reply in “' + draft.label + '” with this text, to check and send:\n\n' + draft.text;
      b.addEventListener('click', () => reply(draft));
      lane.appendChild(b);
    }
    return lane;
  }

  /** A reply in the draft's conversation, with the text in it. Nothing is sent without the person. */
  function reply(draft) {
    try { sessionStorage.setItem('ecw.composePrefill', JSON.stringify({ reply: draft.reply_to, text: draft.text, at: Date.now() })); } catch (e) { /* no storage */ }
    close();
    if (typeof window.ecwCaseReply === 'function') { window.ecwCaseReply(draft.reply_to, draft.thread_id, draft.mailbox_id); return; }
    location.href = 'app.php?reply=' + encodeURIComponent(draft.reply_to) + '&mode=reply&thread=' + encodeURIComponent(draft.thread_id) + '&mailbox=' + encodeURIComponent(draft.mailbox_id);
  }

  function draw(box, caseId, opts, r) {
    const facts = r.facts || {};
    const body = el('div', 'crv-body');
    if (r.headline) body.appendChild(el('p', 'crv-headline', r.headline));
    const lanes = el('div', 'crv-lanes');
    [lane('customer', facts, r.customer, r.customer_update), lane('supplier', facts, r.supplier, r.supplier_chase)].forEach((l) => { if (l) lanes.appendChild(l); });
    if (lanes.childElementCount) body.appendChild(lanes);
    if (r.status) body.append(el('h4', '', 'Where it stands'), el('p', 'crv-status', r.status));
    const list = (title, items, cls) => {
      if (!items || !items.length) return;
      body.appendChild(el('h4', '', title));
      const ul = el('ul', cls);
      items.forEach((t) => ul.appendChild(el('li', '', t)));
      body.appendChild(ul);
    };
    list('Open points', r.open_points, 'crv-points');
    list('Risks', r.risks, 'crv-risks');
    const n = r.next_step || {};
    if (n.action) {
      const next = el('div', 'crv-next');
      next.appendChild(el('span', 'crv-next-label', 'Suggested next step'));
      next.appendChild(el('strong', 'crv-next-action', n.action));
      const meta = el('span', 'crv-next-meta');
      if (n.waiting_on) meta.appendChild(el('span', 'cl-wait ' + n.waiting_on, n.waiting_on === 'us' ? 'Our turn' : 'Waiting: ' + WAITING[n.waiting_on]));
      if (n.review_on) meta.appendChild(el('span', '', 'Look again ' + new Date(n.review_on + 'T00:00:00').toLocaleDateString([], { day: 'numeric', month: 'short' })));
      if (meta.childElementCount) next.appendChild(meta);
      const use = el('button', 'crv-use', 'Use as next step');
      use.type = 'button';
      use.addEventListener('click', async () => {
        use.disabled = true;
        try {
          const res = await api('case_review_apply', caseId, {}, n);
          toast('Next step saved');
          use.textContent = '✓ Next step saved';
          opts?.onApplied?.(res.next);
        } catch (e) { use.disabled = false; toast(e.message, true); }
      });
      next.appendChild(use);
      body.appendChild(next);
    }
    const foot = el('footer', 'crv-foot');
    foot.textContent = 'Read ' + (r.emails_read || 0) + ' emails · ' + (r.cached ? 'saved review from ' : 'reviewed ') + ago(r.created_at) + ' · AI can be wrong: check before sending.';
    box.replaceChildren(header(caseId, opts, r), body, foot);
  }

  window.ecwCaseReview = {
    button(caseId, opts) {
      const b = el('button', 'crv-btn');
      b.type = 'button';
      b.title = 'AI review of the whole case: is the customer kept informed, is a supplier silent, what to do next';
      b.setAttribute('aria-label', 'AI review of the case');
      b.appendChild(icon('ecw-ai-icon'));
      b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); open(b, caseId, opts); });
      return b;
    },
  };
})();
