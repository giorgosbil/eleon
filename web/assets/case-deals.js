/**
 * Case page → Deals tab (lib/deals.php): the sales and purchasing path of the case,
 * RFQ → offer (revisions) → PO → confirmation → delivery → invoice, for what we sell
 * and what we buy. Each document: 📍 Go to email (in the case, or in Mail), Open,
 * Correct (kept as corrected), Not this. "Read with AI" when the AI assistant is on.
 * "Add a document from an email": click the email in the case, Apply (the AI or the rules say what it is).
 * Everything is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const root = $('dealsBody');
  if (!root) return;
  const caseId = Number(document.body.dataset.case);
  const csrf = document.body.dataset.csrf;
  let data = null;
  let side = null;
  let editing = null;
  let busy = false;

  async function api(action, body, params) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('case_id', String(caseId));
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const r = await fetch(url, body === undefined ? { credentials: 'same-origin' } : {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body),
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  const toast = (m, err) => (window.ecwPageToast ? window.ecwPageToast(m, err) : null);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined && text !== null) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; };
  const when = (at) => { if (!at) return ''; const d = new Date(String(at).replace(' ', 'T') + 'Z'); return isNaN(d) ? at : d.toLocaleDateString([], { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' }); };
  const day = (ymd) => { if (!ymd) return ''; const d = new Date(ymd + 'T12:00:00'); return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }); };
  const money = (a, c) => (a === null || a === undefined ? '' : Number(a).toLocaleString([], { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (c ? ' ' + c : ''));
  const STAGE_ICON = { rfq: 'fa-circle-question', offer: 'fa-file-invoice-dollar', po: 'fa-file-signature', confirm: 'fa-circle-check', delivery: 'fa-truck', invoice: 'fa-receipt' };

  /** 📍 the email in this case's timeline; not on screen (a hidden conversation): opened in Mail. */
  function goTo(doc) {
    if (!doc.message_id) return;
    if (document.getElementById('msg-' + doc.message_id)) {
      document.dispatchEvent(new CustomEvent('ecw:jump-to-message', { detail: { message_id: doc.message_id } }));
    } else {
      window.open('app.php?mailbox=' + (doc.mailbox_id || '') + '&thread=' + doc.thread_id + '#msg-' + doc.message_id, '_blank', 'noopener');
    }
  }
  const mailLink = (doc) => 'app.php?mailbox=' + (doc.mailbox_id || '') + '&thread=' + doc.thread_id + '#msg-' + doc.message_id;

  async function load(quiet) {
    if (!quiet) root.replaceChildren(el('p', 'muted', 'Reading the emails…'));
    try { data = await api('case_deals'); render(); }
    catch (e) { root.replaceChildren(el('p', 'muted', e.message)); }
  }

  async function readWithAi(btn) {
    if (busy) return;
    busy = true; btn.disabled = true;
    const label = btn.querySelector('span'); const was = label.textContent; label.textContent = 'The AI is reading the emails…';
    try {
      data = await api('case_deals_ai', {}, { fresh: 1 });
      const r = data.ai.read || {};
      toast(r.documents ? 'The AI read ' + r.read + ' emails and found ' + r.documents + ' document' + (r.documents === 1 ? '' : 's') : 'The AI found no new documents');
      render();
    } catch (e) { toast(e.message, true); label.textContent = was; btn.disabled = false; }
    finally { busy = false; }
  }

  function render() {
    const count = Object.values(data.sides).reduce((n, s) => n + Object.values(s.stages).reduce((m, list) => m + list.length, 0), 0);
    const badge = $('dealsCount');
    if (badge) badge.textContent = data.alerts.length ? '!' : (count ? String(count) : '');
    if (!side || !data.active.includes(side)) side = data.active[0] || 'sell';
    const out = [];

    // Header: AI reading and its line on the deal.
    const head = el('div', 'deal-head');
    const ai = el('button', 'deal-ai-btn');
    ai.type = 'button';
    ai.append(icon('ecw-ai-icon'), el('span', '', data.ai.at ? (data.ai.fresh ? 'Read by the AI ' + when(data.ai.at) + ' · read again' : 'New emails: read with AI') : 'Read with AI'));
    if (!data.ai.available) { ai.disabled = true; ai.title = data.ai.paused ? 'AI is paused for this workspace (Settings → AI). Smart matching still works.' : 'The AI assistant is not available for your account.'; }
    else ai.addEventListener('click', () => readWithAi(ai));
    const refresh = el('button', 'deal-icon-btn'); refresh.type = 'button'; refresh.title = 'Look again'; refresh.setAttribute('aria-label', 'Look again');
    refresh.appendChild(icon('fa fa-rotate-right'));
    refresh.addEventListener('click', () => load());
    head.append(ai, refresh);
    out.push(head);
    if (data.ai.note) out.push(el('p', 'deal-ai-note', data.ai.note));
    // Selling and buying in one case: what we sell it for against what the supplier asks.
    if (data.margin) {
      const m = data.margin;
      const box = el('div', 'deal-margin' + (m.amount < 0 ? ' negative' : ''));
      box.append(el('span', 'deal-margin-label', 'Margin'), el('strong', '', money(m.amount, m.currency) + ' · ' + m.percent + '%'),
        el('span', 'muted small', 'our offer ' + money(m.sell, m.currency) + ' − supplier ' + money(m.buy, m.currency)));
      box.title = 'Latest offer we sent against the latest offer the supplier sent.';
      out.push(box);
    }

    // What needs attention.
    if (data.alerts.length) {
      const list = el('ul', 'deal-alerts');
      data.alerts.forEach((a) => {
        const li = el('li', 'deal-alert ' + a.level);
        li.append(icon('fa ' + (a.level === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-info')), el('span', '', (data.active.length > 1 ? (a.side === 'sell' ? 'Selling: ' : 'Buying: ') : '') + a.text));
        if (a.message_id) {
          const b = el('button', 'deal-link'); b.type = 'button'; b.textContent = '📍';
          b.title = 'Go to the email'; b.setAttribute('aria-label', 'Go to the email');
          b.addEventListener('click', () => goTo(findDoc(a.doc) || { message_id: a.message_id }));
          li.appendChild(b);
        }
        list.appendChild(li);
      });
      out.push(list);
    }

    if (!data.active.length) {
      const empty = el('div', 'deal-empty');
      empty.append(icon('fa fa-handshake'), el('strong', '', 'No RFQ, offer or order found in this case yet'),
        el('p', 'muted small', 'Requests for quotation, offers and their revisions, purchase orders, confirmations and deliveries show here by themselves, '
          + 'from the emails and their files. One was missed? Add it below from its email.'));
      out.push(empty);
    } else {
      if (data.active.length > 1) {
        const tabs = el('div', 'deal-sides'); tabs.setAttribute('role', 'tablist');
        data.active.forEach((k) => {
          const b = el('button', 'deal-side' + (k === side ? ' active' : ''), k === 'sell' ? 'Selling' : 'Buying');
          b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', String(k === side));
          b.addEventListener('click', () => { side = k; render(); });
          tabs.appendChild(b);
        });
        out.push(tabs);
      } else {
        out.push(el('div', 'deal-side-label', side === 'sell' ? 'Selling · we are the supplier' : 'Buying · we are the customer'));
      }
      out.push(pipeline(data.sides[side]));
    }
    out.push(addForm());
    root.replaceChildren(...out);
  }

  function findDoc(id) {
    for (const s of Object.values(data.sides)) for (const list of Object.values(s.stages)) for (const d of list) if (d.id === id) return d;
    return null;
  }

  function pipeline(s) {
    const ol = el('ol', 'deal-pipe');
    const order = Object.keys(data.stages);
    const reached = s.reached ? order.indexOf(s.reached) : -1;
    order.forEach((stage, i) => {
      const docs = s.stages[stage];
      const li = el('li', 'deal-step' + (docs.length ? ' done' : (i < reached ? ' skipped' : '')) + (stage === s.reached ? ' current' : ''));
      const title = el('div', 'deal-step-title');
      title.append(el('span', 'deal-dot'), icon('fa ' + STAGE_ICON[stage]), el('span', '', data.stages[stage]));
      if (stage === 'offer' && s.offers.length) {
        const revs = s.offers.reduce((n, c) => n + c.docs.length, 0);
        if (revs > 1) title.appendChild(el('span', 'deal-step-meta', revs + ' versions'));
      }
      li.appendChild(title);
      if (stage === 'offer') {
        s.offers.forEach((chain, ci) => li.appendChild(offerChain(chain, ci === 0)));
      } else {
        docs.slice().reverse().forEach((d, di) => li.appendChild(card(d, { older: di > 0 })));
      }
      ol.appendChild(li);
    });
    return ol;
  }

  /** One offer and its revisions: the latest as a card, the earlier ones folded under it. */
  function offerChain(chain, newest) {
    const box = el('div', 'deal-chain');
    box.appendChild(card(chain.latest, { current: newest, chainSize: chain.docs.length }));
    const earlier = chain.docs.slice(0, -1).reverse();
    if (earlier.length) {
      const det = el('details', 'deal-revisions');
      det.appendChild(el('summary', '', 'Earlier revisions (' + earlier.length + ')'));
      earlier.forEach((d) => det.appendChild(card(d, { compact: true })));
      box.appendChild(det);
    }
    return box;
  }

  function card(d, opt) {
    opt = opt || {};
    if (editing === d.id) return editCard(d);
    const c = el('div', 'deal-doc' + (opt.compact ? ' compact' : '') + (opt.current ? ' current' : '') + (opt.older ? ' older' : ''));
    c.dataset.doc = String(d.id);
    const top = el('div', 'deal-doc-top');
    const ref = el('strong', 'deal-ref', d.ref || (d.stage === 'rfq' ? 'Request' : data.stages[d.stage]));
    top.appendChild(ref);
    if (d.rev_label) top.appendChild(el('span', 'deal-rev', d.rev_label));
    if (opt.current && opt.chainSize > 1) top.appendChild(el('span', 'deal-current', 'Current'));
    if (d.amount !== null) top.appendChild(el('span', 'deal-amount', money(d.amount, d.currency)));
    const src = el('span', 'deal-src ' + d.source, d.source === 'ai' ? 'AI' : d.source === 'user' ? 'Corrected' : 'Auto');
    src.title = d.source === 'ai' ? 'Read by the AI' : d.source === 'user' ? 'Corrected by a person: kept as it is' : 'Found by smart matching (' + d.confidence + '%)';
    top.appendChild(src);
    c.appendChild(top);
    const conv = d.conversation === 'customer' ? ' · customer conversation' : d.conversation === 'supplier' ? ' · supplier conversation' : d.conversation === 'internal' ? ' · internal' : '';
    const meta = el('div', 'deal-meta muted small', when(d.date) + ' · ' + (d.direction === 'outgoing' ? 'sent by us' : 'from ' + (d.from || 'them')) + conv);
    c.appendChild(meta);
    if (!opt.compact && d.summary) c.appendChild(el('p', 'deal-summary', d.summary));
    const facts = [];
    if (d.valid_until) facts.push(['Valid until', day(d.valid_until)]);
    if (d.delivery_date) facts.push(['Delivery', day(d.delivery_date)]);
    if (d.tracking) facts.push(['Tracking', d.tracking]);
    if (facts.length && !opt.compact) {
      const dl = el('dl', 'deal-facts');
      facts.forEach(([k, v]) => dl.append(el('dt', '', k), el('dd', '', v)));
      c.appendChild(dl);
    }
    if (d.items && d.items.length && !opt.compact) {
      const t = el('table', 'deal-items');
      d.items.forEach((it) => {
        const tr = el('tr');
        tr.append(el('td', 'num', it.qty !== null && it.qty !== undefined ? String(it.qty) + ' ×' : ''), el('td', '', it.desc), el('td', 'num', it.unit_price !== null && it.unit_price !== undefined ? money(it.unit_price, d.currency) : ''));
        t.appendChild(tr);
      });
      c.appendChild(t);
    }
    const actions = el('div', 'deal-actions');
    const go = el('button', 'deal-go'); go.type = 'button';
    go.append(el('span', '', '📍'), el('span', '', 'Go to email'));
    go.title = d.subject ? 'Go to: ' + d.subject : 'Go to the email';
    go.addEventListener('click', () => goTo(d));
    actions.appendChild(go);
    if (d.attachment) {
      const a = el('a', 'attachment deal-file');
      a.href = 'api/index.php?action=attachment&id=' + d.attachment.id;
      a.dataset.name = d.attachment.name;
      a.target = '_blank'; a.rel = 'noopener';
      if (window.ecwFileIcon) a.appendChild(window.ecwFileIcon(d.attachment.name, '')); else a.appendChild(icon('fa fa-paperclip'));
      a.appendChild(el('span', '', d.attachment.name));
      a.title = 'View ' + d.attachment.name;
      // The same viewer as the email's attachments (filePreview.js): PDF, images, Office, CAD.
      a.addEventListener('click', (e) => {
        if (!window.ecwOpenPreview || e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        window.ecwOpenPreview({ url: a.href, name: d.attachment.name });
      });
      actions.appendChild(a);
    }
    const open = el('a', 'deal-icon-btn'); open.href = mailLink(d); open.target = '_blank'; open.rel = 'noopener';
    open.title = 'Open in Mail'; open.setAttribute('aria-label', 'Open in Mail'); open.appendChild(icon('fa fa-arrow-up-right-from-square'));
    const edit = el('button', 'deal-icon-btn'); edit.type = 'button'; edit.title = 'Correct it'; edit.setAttribute('aria-label', 'Correct it');
    edit.appendChild(icon('fa fa-pen'));
    edit.addEventListener('click', () => { editing = d.id; render(); });
    const no = el('button', 'deal-icon-btn'); no.type = 'button'; no.title = 'Not this: hide it'; no.setAttribute('aria-label', 'Not this');
    no.appendChild(icon('fa fa-xmark'));
    no.addEventListener('click', async () => {
      try { data = await api('case_deal_update', { doc_id: d.id, dismissed: true }); render(); toast('Hidden: it will not be found again'); }
      catch (e) { toast(e.message, true); }
    });
    actions.append(open, edit, no);
    c.appendChild(actions);
    return c;
  }

  function editCard(d) {
    const f = el('form', 'deal-doc deal-edit');
    const field = (label, input) => { const l = el('label', ''); l.append(el('span', '', label), input); return l; };
    const input = (name, value, type) => { const i = el('input'); i.name = name; i.type = type || 'text'; i.value = value === null || value === undefined ? '' : String(value); return i; };
    const select = (name, options, value) => { const s = el('select', 'ot-select'); s.name = name; Object.entries(options).forEach(([k, v]) => s.add(new Option(v, k, false, k === value))); return s; };
    const grid = el('div', 'deal-edit-grid');
    grid.append(
      field('Stage', select('stage', data.stages, d.stage)),
      field('Side', select('side', { sell: 'Selling', buy: 'Buying' }, d.side)),
      field('Number', input('ref', d.ref)),
      field('Revision', input('revision', d.revision, 'number')),
      field('Total', input('amount', d.amount)),
      field('Currency', input('currency', d.currency || '')),
      field('Valid until', input('valid_until', d.valid_until, 'date')),
      field('Delivery', input('delivery_date', d.delivery_date, 'date')),
      field('Tracking', input('tracking', d.tracking)),
    );
    const summary = el('textarea'); summary.name = 'summary'; summary.rows = 2; summary.value = d.summary || '';
    const row = el('div', 'deal-edit-row');
    const save = el('button', 'primary', 'Save'); save.type = 'submit';
    const cancel = el('button', '', 'Cancel'); cancel.type = 'button';
    cancel.addEventListener('click', () => { editing = null; render(); });
    row.append(save, cancel, el('span', 'muted small', 'Kept as you leave it: the AI and the matching will not change it.'));
    f.append(grid, field('Summary', summary), row);
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fields = Object.fromEntries(new FormData(f));
      save.disabled = true;
      try { data = await api('case_deal_update', { doc_id: d.id, fields }); editing = null; render(); toast('Saved'); }
      catch (err) { toast(err.message, true); save.disabled = false; }
    });
    return f;
  }

  /**
   * A document the matching missed: "Choose the email" makes the case's emails clickable;
   * click one, say what it is (or let the AI decide), Apply. Esc or Cancel: nothing changes.
   */
  function addForm() {
    const box = el('div', 'deal-add');
    const b = el('button', 'deal-add-btn'); b.type = 'button';
    b.append(icon('fa fa-hand-pointer'), el('span', '', 'Add a document from an email'));
    b.title = 'Choose the email in the case, then what it is';
    b.addEventListener('click', startPick);
    box.appendChild(b);
    return box;
  }

  let pick = null;
  const phone = () => window.matchMedia('(max-width: 860px)').matches;
  function startPick() {
    if (pick) return;
    if (!document.querySelector('article.bubble[id^="msg-"]')) { toast('No emails on the page to choose from', true); return; }
    const bar = el('div', 'deal-pick-bar'); bar.setAttribute('role', 'dialog'); bar.setAttribute('aria-label', 'Choose the email');
    const msg = el('div', 'deal-pick-msg');
    const hint = el('strong', '', 'Click the email that is the document');
    const chosen = el('span', 'deal-pick-chosen muted small', 'Scroll the conversation and click an email.');
    msg.append(hint, chosen);
    const stage = el('select', 'ot-select'); stage.setAttribute('aria-label', 'What it is');
    stage.add(new Option(data.ai.available ? '✨ Let the AI decide' : 'Decide for me', ''));
    Object.entries(data.stages).forEach(([k, v]) => stage.add(new Option(v, k)));
    const sideSel = el('select', 'ot-select'); sideSel.setAttribute('aria-label', 'Side');
    [['', 'Side: decide for me'], ['sell', 'Selling'], ['buy', 'Buying']].forEach(([k, v]) => sideSel.add(new Option(v, k)));
    const apply = el('button', 'primary', 'Apply'); apply.type = 'button'; apply.disabled = true;
    const cancel = el('button', '', 'Cancel'); cancel.type = 'button';
    const row = el('div', 'deal-pick-row'); row.append(stage, sideSel, apply, cancel);
    bar.append(msg, row);
    document.body.appendChild(bar);
    document.body.classList.add('deal-picking');
    pick = { bar, id: 0, apply, chosen, hint };
    // On a phone the panel covers the emails: out of the way while choosing.
    if (phone()) $('casePanelClose')?.click();
    document.addEventListener('click', onPickClick, true);
    document.addEventListener('keydown', onPickKey, true);
    cancel.addEventListener('click', () => endPick(false));
    apply.addEventListener('click', async () => {
      if (!pick.id) return;
      apply.disabled = cancel.disabled = stage.disabled = sideSel.disabled = true;
      apply.textContent = stage.value ? 'Adding…' : 'Reading…';
      bar.classList.add('busy');
      pick.hint.replaceChildren(icon('fa fa-spinner fa-spin'), document.createTextNode(stage.value ? ' Adding it…' : data.ai.available ? ' The AI is reading this email… (10–20 seconds)' : ' Reading this email…'));
      try {
        data = await api('case_deal_read', { message_id: pick.id, stage: stage.value, side: sideSel.value });
        const p = data.picked || {};
        side = (findDoc(p.doc_id) || {}).side || null;
        endPick(true);
        render();
        const what = data.stages[p.stage] || p.stage;
        toast(p.existed ? 'It was already in the deal as ' + what + (p.by === 'ai' ? ' — the AI agrees' : '') + '. Kept as you confirmed it.'
          : 'Added as ' + what + (p.by === 'ai' ? ' (read by the AI)' : ''));
        showDoc(p.doc_id);
      } catch (err) {
        bar.classList.remove('busy');
        pick.hint.textContent = 'Click the email that is the document';
        toast(err.message, true);
        apply.textContent = 'Apply';
        apply.disabled = cancel.disabled = stage.disabled = sideSel.disabled = false;
        stage.focus();
      }
    });
  }
  /** The document in the panel: its revisions opened, scrolled to and lit for a moment. */
  function showDoc(id) {
    setTimeout(() => {
      const c = root.querySelector('.deal-doc[data-doc="' + id + '"]');
      if (!c) return;
      for (let d = c.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) d.open = true;
      c.scrollIntoView({ block: 'center', behavior: 'smooth' });
      c.classList.add('deal-flash');
      setTimeout(() => c.classList.remove('deal-flash'), 2600);
    }, 120);
  }
  function onPickClick(e) {
    if (!pick || pick.bar.contains(e.target) || pick.bar.classList.contains('busy')) return;
    const art = e.target.closest && e.target.closest('article.bubble[id^="msg-"]');
    if (!art) return;
    // The whole email is the target while choosing: its links and buttons wait.
    e.preventDefault(); e.stopPropagation();
    document.querySelectorAll('.deal-picked').forEach((n) => n.classList.remove('deal-picked'));
    art.classList.add('deal-picked');
    pick.id = Number(art.id.slice(4));
    const m = (data.emails || []).find((x) => x.id === pick.id);
    const who = art.querySelector('.bubble-from')?.textContent || (m ? m.from : '');
    const at = art.querySelector('.bubble-time')?.textContent || (m ? when(m.date) : '');
    pick.hint.textContent = 'Chosen: ' + (m && m.direction === 'outgoing' ? 'us' : who);
    pick.chosen.textContent = [at, m ? m.subject : ''].filter(Boolean).join(' · ');
    pick.apply.disabled = false;
  }
  function onPickKey(e) { if (e.key === 'Escape' && pick) { e.stopPropagation(); endPick(false); } }
  function endPick(done) {
    if (!pick) return;
    document.removeEventListener('click', onPickClick, true);
    document.removeEventListener('keydown', onPickKey, true);
    document.querySelectorAll('.deal-picked').forEach((n) => n.classList.remove('deal-picked'));
    document.body.classList.remove('deal-picking');
    pick.bar.remove();
    pick = null;
    // Back to the Deals tab, where the new document is.
    if (done || phone()) {
      const panel = $('casePanel') || root.closest('aside, section');
      if (panel && panel.hidden) $('casePanelToggle')?.click();
      document.querySelector('[role="tab"][data-tab="deals"]')?.click();
    }
  }

  // Load with the page for the tab's count; drawn again when the tab is opened (new emails may have come).
  load(true);
  document.querySelector('[role="tab"][data-tab="deals"]')?.addEventListener('click', () => load(true));
})();
