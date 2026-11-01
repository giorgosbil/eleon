/**
 * Help, the way people actually use it:
 *
 *   - Tours: the real page, one thing at a time — the rest dims, a short
 *     caption says what it is for (Next / Back / Esc). One per main page.
 *   - Show me: an answer points at the button to press, on the right page
 *     (it opens that page first when needed).
 *   - Answers: one line first; the details only when asked for.
 *   - A one-time "New here? Take the tour" on a page with a tour.
 *
 * Opened with "?" in the top bar or the ? key; any element with data-help
 * (an article slug) opens that answer, data-help-show (a slug) runs its
 * "Show me", data-help-tour (a page key) starts that tour.
 * Content: help.php?format=json (lib/help.php), fetched the first time it is
 * needed. Article text is written by us (trusted); everything else is textContent.
 */
(function () {
  'use strict';
  const btn = document.getElementById('helpBtn');
  if (!btn || window.self !== window.top) return;
  const base = window.ecwWebBase || '/';
  const page = btn.dataset.page || '';
  const PENDING = 'ecw.help.pending';   // a "Show me" / tour waiting for its page to load
  let data = null;
  let panel = null;
  let topic = '';

  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', cls.startsWith('ecw-') ? cls : 'fa ' + cls); i.setAttribute('aria-hidden', 'true'); return i; };
  const store = {
    get(k, s) { try { return (s ? sessionStorage : localStorage).getItem(k); } catch (_) { return null; } },
    set(k, v, s) { try { (s ? sessionStorage : localStorage).setItem(k, v); } catch (_) {} },
    del(k, s) { try { (s ? sessionStorage : localStorage).removeItem(k); } catch (_) {} },
  };

  async function load() {
    if (data) return data;
    const r = await fetch(base + 'help.php?format=json', { credentials: 'same-origin' });
    if (!r.ok) throw new Error('Help is not available right now.');
    data = await r.json();
    return data;
  }

  // ------------------------------------------------------------------ panel

  function build() {
    panel = el('aside', 'help-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Help');
    const head = el('div', 'help-head');
    const searchWrap = el('label', 'help-searchbox');
    searchWrap.appendChild(icon('fa-magnifying-glass'));
    const search = el('input', 'help-search'); search.type = 'search'; search.placeholder = 'How do I…?'; search.setAttribute('aria-label', 'Search the help');
    search.addEventListener('input', () => render());
    searchWrap.appendChild(search);
    const close = el('button', 'help-close'); close.type = 'button'; close.setAttribute('aria-label', 'Close help');
    close.appendChild(icon('fa-xmark'));
    close.addEventListener('click', hide);
    head.append(searchWrap, close);
    const body = el('div', 'help-content');
    const foot = el('footer', 'help-foot');
    const all = el('a', '', 'All answers'); all.href = base + 'help.php';
    const hint = el('span', 'help-hint'); hint.append(el('kbd', '', '?'), ' anywhere opens this');
    foot.append(all, hint);
    panel.append(head, body, foot);
    document.body.appendChild(panel);
    panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); hide(); btn.focus(); } });
  }

  function answerCard(a, open) {
    const card = el('div', 'help-card' + (open ? ' open' : ''));
    card.dataset.slug = a.slug;
    const head = el('button', 'help-card-head'); head.type = 'button';
    head.setAttribute('aria-expanded', String(!!open));
    const t = el('span', 'help-card-text');
    t.append(el('strong', '', a.title), el('span', 'help-card-sum', a.summary || ''));
    head.append(t, icon('fa-chevron-down'));
    const more = el('div', 'help-card-more');
    const text = el('div', 'help-body');
    text.innerHTML = a.body;   // trusted: written in lib/help.php
    more.appendChild(text);
    if (a.show) {
      const go = el('button', 'help-show'); go.type = 'button';
      const here = a.show.page === page;
      go.append(icon(a.show.target && here ? 'fa-hand-pointer' : 'fa-arrow-right'), a.show.target ? ' Show me' : ' Go there');
      go.addEventListener('click', () => showMe(a));
      more.appendChild(go);
    }
    head.addEventListener('click', () => {
      const isOpen = card.classList.toggle('open');
      head.setAttribute('aria-expanded', String(isOpen));
    });
    card.append(head, more);
    return card;
  }

  function tourCard(tour, here) {
    const b = el('button', 'help-tour' + (here ? ' here' : '')); b.type = 'button';
    const ic = el('span', 'help-tour-icon'); ic.appendChild(icon('fa-play'));
    const t = el('span', 'help-tour-text');
    const steps = here ? tour.steps.filter((s) => visible(document.querySelector(s.target))).length || tour.steps.length : 0;
    t.append(el('strong', '', here ? 'Take the tour of this page' : tour.title), el('span', '', here ? steps + ' quick steps · 30 seconds' : 'Opens the page and shows you around'));
    b.append(ic, t);
    b.addEventListener('click', () => startTour(tour.key));
    return b;
  }

  function render() {
    const body = panel.querySelector('.help-content');
    const q = panel.querySelector('.help-search').value;
    body.textContent = '';
    const words = String(q || '').toLowerCase().split(/\s+/).filter(Boolean);
    const strip = (h) => h.replace(/<[^>]+>/g, ' ');
    if (words.length) {
      const hits = data.articles.filter((a) => words.every((w) => (a.title + ' ' + a.summary + ' ' + a.words + ' ' + strip(a.body)).toLowerCase().includes(w)));
      if (!hits.length) {
        const none = el('div', 'help-empty');
        none.append(icon('fa-circle-question'), el('p', '', 'Nothing found for “' + q.trim() + '”. Try another word, or take a tour.'));
        body.appendChild(none);
      }
      hits.forEach((a, i) => body.appendChild(answerCard(a, hits.length === 1 && i === 0)));
      return;
    }
    const tours = data.tours || {};
    if (tours[page]) body.appendChild(tourCard(tours[page], true));

    const here = data.articles.filter((a) => (a.pages || []).includes(page));
    if (here.length && !topic) {
      body.appendChild(el('div', 'help-group-title', 'On this page'));
      here.forEach((a) => body.appendChild(answerCard(a, false)));
    }

    // Browse by topic.
    const chips = el('div', 'help-chips');
    const chip = (key, label, ic) => {
      const c = el('button', 'help-chip' + (topic === key ? ' active' : '')); c.type = 'button';
      if (ic) c.appendChild(icon(ic));
      c.append(label);
      c.addEventListener('click', () => { topic = topic === key ? '' : key; render(); });
      return c;
    };
    Object.entries(data.topics).forEach(([key, t]) => {
      if (data.articles.some((a) => a.topic === key)) chips.appendChild(chip(key, ' ' + t.title, t.icon));
    });
    body.appendChild(el('div', 'help-group-title', 'Browse'));
    body.appendChild(chips);
    if (topic) {
      data.articles.filter((a) => a.topic === topic).forEach((a) => body.appendChild(answerCard(a, false)));
    }

    const others = Object.values(tours).filter((t) => t.key !== page && t.href);
    if (others.length) {
      body.appendChild(el('div', 'help-group-title', 'Tours'));
      const row = el('div', 'help-tours');
      others.forEach((t) => row.appendChild(tourCard(t, false)));
      body.appendChild(row);
    }
  }

  async function show(slug) {
    if (!panel) build();
    panel.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
    const body = panel.querySelector('.help-content');
    if (!data) { body.textContent = ''; body.appendChild(el('div', 'help-empty', 'Loading…')); }
    try { await load(); } catch (e) { body.textContent = e.message; return; }
    const search = panel.querySelector('.help-search');
    if (slug) {
      const a = data.articles.find((x) => x.slug === slug);
      search.value = '';
      topic = a ? a.topic : '';
      render();
      const card = a && panel.querySelector('.help-card[data-slug="' + CSS.escape(slug) + '"]');
      if (card) {
        card.classList.add('open', 'flash');
        card.querySelector('.help-card-head').setAttribute('aria-expanded', 'true');
        card.scrollIntoView({ block: 'nearest' });
        card.querySelector('.help-card-head').focus();
      }
    } else {
      render();
      search.focus();
    }
  }
  function hide() {
    if (!panel) return;
    panel.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }
  const isOpen = () => panel && panel.classList.contains('open');

  // ------------------------------------------------------- spotlight + tours

  let spot = null;   // the running tour or "Show me"

  const visible = (n) => {
    if (!n || !n.isConnected) return false;
    const r = n.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const st = getComputedStyle(n);
    return st.visibility !== 'hidden' && st.display !== 'none' && n.closest('[hidden]') === null;
  };

  function endSpot() {
    if (!spot) return;
    spot.layer.remove();
    window.removeEventListener('resize', spot.place, true);
    window.removeEventListener('scroll', spot.place, true);
    document.removeEventListener('keydown', spot.keys, true);
    document.removeEventListener('pointerdown', spot.away, true);
    const back = spot.returnFocus;
    spot = null;
    if (back && back.isConnected) back.focus();
  }

  /**
   * steps: [{target, title, text}]. tour: Back/Next and step count, clicks
   * elsewhere are caught; otherwise (Show me) a single highlight that goes
   * away at the next click — which can be the highlighted button itself.
   */
  function runSpot(steps, opts) {
    endSpot();
    hideNudge();
    const usable = steps.filter((s) => visible(document.querySelector(s.target)));
    if (!usable.length) return false;
    const layer = el('div', 'tour-layer' + (opts.tour ? ' is-tour' : ''));
    const hole = el('div', 'tour-hole');
    const pop = el('div', 'tour-pop');
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-live', 'polite');
    pop.tabIndex = -1;
    layer.append(hole, pop);
    document.body.appendChild(layer);
    spot = { layer, index: 0, returnFocus: document.activeElement };

    const draw = () => {
      const s = usable[spot.index];
      pop.textContent = '';
      const top = el('div', 'tour-pop-top');
      if (opts.tour) top.appendChild(el('span', 'tour-step', (spot.index + 1) + ' of ' + usable.length));
      const x = el('button', 'tour-x'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.appendChild(icon('fa-xmark'));
      x.addEventListener('click', endSpot);
      top.appendChild(x);
      pop.append(top);
      if (s.title) pop.appendChild(el('strong', 'tour-title', s.title));
      if (s.text) pop.appendChild(el('p', 'tour-text', s.text));
      if (opts.tour) {
        const nav = el('div', 'tour-nav');
        const dots = el('span', 'tour-dots');
        usable.forEach((_, i) => dots.appendChild(el('i', i === spot.index ? 'on' : '')));
        nav.appendChild(dots);
        if (spot.index > 0) {
          const back = el('button', 'tour-btn', 'Back'); back.type = 'button';
          back.addEventListener('click', () => go(-1));
          nav.appendChild(back);
        }
        const last = spot.index === usable.length - 1;
        const next = el('button', 'tour-btn primary', last ? 'Done' : 'Next'); next.type = 'button';
        next.addEventListener('click', () => (last ? endSpot() : go(1)));
        nav.appendChild(next);
        pop.appendChild(nav);
        next.focus({ preventScroll: true });
      } else {
        const ok = el('button', 'tour-btn primary tour-ok', 'Got it'); ok.type = 'button';
        ok.addEventListener('click', endSpot);
        pop.appendChild(ok);
      }
      const target = document.querySelector(s.target);
      target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      place();
    };
    const place = () => {
      if (!spot) return;
      const s = usable[spot.index];
      const target = document.querySelector(s.target);
      if (!visible(target)) { endSpot(); return; }
      const r = target.getBoundingClientRect();
      const pad = 6;
      const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
      // A huge target (a whole list) is outlined inside the screen.
      const box = { left: Math.max(4, r.left - pad), top: Math.max(4, r.top - pad) };
      box.right = Math.min(vw - 4, r.right + pad); box.bottom = Math.min(vh - 4, r.bottom + pad);
      Object.assign(hole.style, { left: box.left + 'px', top: box.top + 'px', width: (box.right - box.left) + 'px', height: (box.bottom - box.top) + 'px' });
      const pw = pop.offsetWidth, ph = pop.offsetHeight, gap = 12;
      let placeAt = 'bottom';
      let top = box.bottom + gap;
      if (top + ph > vh - 8) { placeAt = 'top'; top = box.top - gap - ph; }
      let left;
      if (top < 8) {
        // No room above or below (a tall pane): beside it, or over it.
        top = Math.min(Math.max(8, box.top + 16), vh - ph - 8);
        if (box.right + gap + pw < vw - 8) { placeAt = 'right'; left = box.right + gap; }
        else if (box.left - gap - pw > 8) { placeAt = 'left'; left = box.left - gap - pw; }
        else { placeAt = 'over'; left = Math.min(Math.max(8, box.left + 16), vw - pw - 8); }
      } else {
        left = Math.min(Math.max(8, (box.left + box.right) / 2 - pw / 2), vw - pw - 8);
      }
      pop.dataset.at = placeAt;
      const arrow = placeAt === 'top' || placeAt === 'bottom'
        ? Math.min(Math.max(16, (box.left + box.right) / 2 - left), pw - 16)
        : Math.min(Math.max(16, (box.top + box.bottom) / 2 - top), ph - 16);
      pop.style.setProperty('--arrow', arrow + 'px');
      Object.assign(pop.style, { left: left + 'px', top: top + 'px' });
    };
    const go = (d) => {
      const n = spot.index + d;
      if (n < 0 || n >= usable.length) return;
      spot.index = n;
      draw();
    };
    spot.place = () => requestAnimationFrame(place);
    spot.keys = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); endSpot(); return; }
      if (!opts.tour) return;
      if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
    };
    // Show me: the next click anywhere ends it (and still does what it was for).
    spot.away = (e) => { if (!opts.tour && !pop.contains(e.target)) endSpot(); };
    window.addEventListener('resize', spot.place, true);
    window.addEventListener('scroll', spot.place, true);
    document.addEventListener('keydown', spot.keys, true);
    document.addEventListener('pointerdown', spot.away, true);
    if (opts.tour) layer.addEventListener('click', (e) => { if (e.target === layer) e.stopPropagation(); });
    draw();
    requestAnimationFrame(() => { if (spot) { layer.classList.add('on'); if (!opts.tour) pop.focus({ preventScroll: true }); } });
    return true;
  }

  async function startTour(key) {
    await load();
    const tour = (data.tours || {})[key];
    if (!tour) return;
    hideNudge();
    store.set('ecw.tour.seen.' + key, '1');
    if (key !== page) {
      if (!tour.href) return;
      store.set(PENDING, JSON.stringify({ tour: key, page: key }), true);
      location.href = tour.href;
      return;
    }
    hide();
    if (!runSpot(tour.steps, { tour: true })) show();   // nothing of it on the screen right now (a narrow window)
  }

  function showMe(a) {
    if (!a.show) return;
    if (a.show.page !== page || !a.show.target) {
      if (a.show.target) store.set(PENDING, JSON.stringify({ show: a.slug, page: a.show.page }), true);
      location.href = a.show.href;
      return;
    }
    hide();
    if (!runSpot([{ target: a.show.target, title: a.title, text: a.show.say || a.summary }], { tour: false })) {
      show(a.slug);   // not on the screen right now (a narrow window): the answer stays open
    }
  }

  // After "Show me" / a tour opened another page.
  async function resume() {
    const raw = store.get(PENDING, true);
    if (!raw) return;
    store.del(PENDING, true);
    let p = null;
    try { p = JSON.parse(raw); } catch (_) { return; }
    if (!p || p.page !== page) return;
    await load();
    // Let the page draw its lists first.
    setTimeout(() => {
      if (p.tour) startTour(p.tour);
      else {
        const a = data.articles.find((x) => x.slug === p.show);
        if (a) showMe(a);
      }
    }, 600);
  }

  // ------------------------------------------------------------------ nudge

  let nudge = null;
  function hideNudge() { if (nudge) { nudge.remove(); nudge = null; } }
  async function offerTour() {
    if (store.get('ecw.tour.seen.' + page) || store.get(PENDING, true)) return;
    if (!['app', 'cases', 'case'].includes(page)) return;
    try { await load(); } catch (_) { return; }
    const tour = (data.tours || {})[page];
    if (!tour || store.get('ecw.tour.seen.' + page)) return;
    nudge = el('div', 'tour-nudge');
    nudge.setAttribute('role', 'status');
    const wave = el('span', 'tour-nudge-icon'); wave.appendChild(icon('fa-wand-magic-sparkles'));
    const t = el('span', 'tour-nudge-text');
    t.append(el('strong', '', 'New here?'), el('span', '', ' A 30-second tour of this page.'));
    const go = el('button', 'tour-btn primary', 'Show me around'); go.type = 'button';
    go.addEventListener('click', () => startTour(page));
    const no = el('button', 'tour-x'); no.type = 'button'; no.setAttribute('aria-label', 'No thanks'); no.title = 'No thanks';
    no.appendChild(icon('fa-xmark'));
    no.addEventListener('click', () => { store.set('ecw.tour.seen.' + page, '1'); hideNudge(); });
    nudge.append(wave, t, go, no);
    document.body.appendChild(nudge);
    requestAnimationFrame(() => nudge && nudge.classList.add('on'));
  }

  // ----------------------------------------------------------------- wiring

  btn.addEventListener('click', (e) => { e.stopPropagation(); if (isOpen()) hide(); else show(); });
  document.addEventListener('click', (e) => {
    const tourLink = e.target.closest('[data-help-tour]');
    if (tourLink) { e.preventDefault(); startTour(tourLink.dataset.helpTour); return; }
    const showLink = e.target.closest('[data-help-show]');
    if (showLink) {
      e.preventDefault();
      load().then(() => { const a = data.articles.find((x) => x.slug === showLink.dataset.helpShow); if (a) showMe(a); });
      return;
    }
    const link = e.target.closest('[data-help]');
    if (link) { e.preventDefault(); show(link.dataset.help); return; }
    if (isOpen() && !panel.contains(e.target) && e.target !== btn && !e.target.closest('.tour-layer')) hide();
  });
  // "?" anywhere except while typing.
  document.addEventListener('keydown', (e) => {
    if (e.key !== '?' || e.ctrlKey || e.metaKey || e.altKey || spot) return;
    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
    e.preventDefault();
    if (isOpen()) hide(); else show();
  });
  const boot = () => { resume(); setTimeout(offerTour, 1500); };
  if (document.readyState === 'complete') boot(); else window.addEventListener('load', boot);
  window.ecwHelp = { show, hide, tour: startTour };
})();
