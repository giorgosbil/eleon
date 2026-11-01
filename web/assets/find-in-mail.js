/**
 * Find inside the open emails: a small bar that highlights every match in the
 * conversation (mail reading pane) or the case timeline, counts them, and
 * jumps between them. Matching ignores case and accents ("υποθεση" finds
 * "υπόθεση"). Only email text is searched, never the app's own buttons.
 *
 *   const find = window.ecwFindInMail({ root: () => el, scope: 'css of searchable parts',
 *     host: el (the bar goes at its top), expand(hit) (reveal a hit, e.g. un-clamp) });
 *   find.open(text, {words}); find.close(); find.isOpen()
 *
 * Highlights are <mark> elements wrapped around existing text nodes (no HTML
 * is parsed), and are removed again on close or a new search.
 */
(function () {
  'use strict';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const svg = (d) => {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '16'); s.setAttribute('height', '16'); s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2.2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    const p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); s.appendChild(p);
    return s;
  };
  /** Lower case, no accents, final sigma as sigma — one character in, one out. */
  const fold = (c) => {
    const f = c.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace('ς', 'σ');
    return f.length === 1 ? f : c.toLowerCase().charAt(0) || c;
  };
  const foldText = (t) => Array.from(t, fold).join('');

  window.ecwFindInMail = function (opts) {
    let hits = [];
    let current = -1;
    let terms = [];
    let busy = false;
    let observer = null;
    let rerun = null;

    // ------------------------------------------------------------ the bar
    const bar = el('div', 'find-bar');
    bar.setAttribute('role', 'search');
    bar.hidden = true;
    const icon = svg('M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-3.6-3.6');
    const input = el('input');
    input.type = 'search';
    input.placeholder = 'Find in these emails…';
    input.setAttribute('aria-label', 'Find in these emails');
    const count = el('span', 'find-count');
    count.setAttribute('aria-live', 'polite');
    const btn = (d, label, fn) => { const b = el('button', 'find-btn'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); b.appendChild(svg(d)); b.addEventListener('click', fn); return b; };
    const prev = btn('m6 15 6-6 6 6', 'Previous match (Shift+Enter)', () => step(-1));
    const next = btn('m6 9 6 6 6-6', 'Next match (Enter)', () => step(1));
    const close = btn('M6 6l12 12M18 6 6 18', 'Close (Esc)', () => api.close());
    bar.append(icon, input, count, prev, next, close);
    opts.host.prepend(bar);

    let typing = null;
    input.addEventListener('input', () => { clearTimeout(typing); typing = setTimeout(() => search(input.value, false), 180); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); if (terms.join(' ') !== input.value.trim()) search(input.value, false); step(e.shiftKey ? -1 : 1); }
      else if (e.key === 'Escape') { e.preventDefault(); api.close(); }
    });

    // ------------------------------------------------------------ highlight
    function clear() {
      busy = true;
      const parents = new Set();
      opts.root()?.querySelectorAll('mark.find-hit').forEach((m) => { parents.add(m.parentNode); m.replaceWith(document.createTextNode(m.textContent)); });
      parents.forEach((p) => p && p.normalize());
      hits = []; current = -1;
      busy = false;
    }

    let wordsMode = false;
    function search(text, words) {
      wordsMode = !!words;
      clear();
      const q = String(text || '').trim();
      terms = !q ? [] : words ? q.split(/\s+/).filter((w) => w.length >= 2) : [q];
      paint();
      if (terms.length && hits.length) { current = -1; step(1, true); }
    }

    function paint() {
      const root = opts.root();
      if (!root || !terms.length) { count.textContent = ''; return; }
      busy = true;
      const folded = terms.map(foldText).filter(Boolean);
      const parts = [...root.querySelectorAll(opts.scope)];
      parts.forEach((part) => {
        const walker = document.createTreeWalker(part, NodeFilter.SHOW_TEXT, {
          acceptNode: (n) => (n.parentElement.closest('script, style, mark.find-hit, .find-bar') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
        });
        const nodes = [];
        let n;
        while ((n = walker.nextNode())) nodes.push(n);
        nodes.forEach((node) => {
          const raw = node.nodeValue;
          if (!raw || raw.trim().length < 1) return;
          const f = foldText(raw);
          if (f.length !== raw.length) return;   // a character that folds to two: skip safely
          const found = [];
          folded.forEach((t) => { let i = 0; while ((i = f.indexOf(t, i)) !== -1) { found.push([i, i + t.length]); i += t.length; } });
          if (!found.length) return;
          found.sort((a, b) => a[0] - b[0]);
          const merged = [];
          found.forEach(([a, b]) => { const last = merged[merged.length - 1]; if (last && a <= last[1]) last[1] = Math.max(last[1], b); else merged.push([a, b]); });
          const frag = document.createDocumentFragment();
          let at = 0;
          merged.forEach(([a, b]) => {
            if (a > at) frag.appendChild(document.createTextNode(raw.slice(at, a)));
            const m = el('mark', 'find-hit', raw.slice(a, b));
            frag.appendChild(m);
            hits.push(m);
            at = b;
          });
          if (at < raw.length) frag.appendChild(document.createTextNode(raw.slice(at)));
          node.replaceWith(frag);
        });
      });
      busy = false;
      count.textContent = hits.length ? '0 of ' + hits.length : 'No matches';
      bar.classList.toggle('no-hits', !hits.length);
    }

    function step(dir, first) {
      if (!hits.length) return;
      hits[current]?.classList.remove('current');
      current = first ? 0 : (current + dir + hits.length) % hits.length;
      show(true, first ? 'auto' : 'smooth');
    }
    /** Mark the current match; scroll to it only when the person asked (Enter, ↑↓, the search button). */
    function show(scroll, behavior) {
      const h = hits[current];
      if (!h) return;
      h.classList.add('current');
      count.textContent = (current + 1) + ' of ' + hits.length;
      if (!scroll) return;
      opts.expand?.(h);
      h.scrollIntoView({ block: 'center', behavior: behavior || 'smooth' });
    }

    // Emails that load after the search (bodies arrive later) get highlighted too,
    // without moving the page: the person may be scrolling elsewhere by now.
    function watch(on) {
      observer?.disconnect();
      observer = null;
      if (!on) return;
      const root = opts.root();
      if (!root) return;
      observer = new MutationObserver(() => {
        if (busy || !terms.length) return;
        clearTimeout(rerun);
        rerun = setTimeout(() => {
          const keep = current;
          clear(); paint();
          if (hits.length) { current = Math.min(Math.max(keep, 0), hits.length - 1); show(false); }
        }, 350);
      });
      observer.observe(root, { childList: true, subtree: true });
    }

    const api = {
      open(text, o) {
        bar.hidden = false;
        document.body.classList.add('find-open');
        if (text !== undefined) input.value = text;
        watch(true);
        search(input.value, !!(o && o.words));
        if (!(o && o.quiet)) { input.focus(); input.select(); }
      },
      close() {
        clearTimeout(typing);
        watch(false);
        clear();
        terms = [];
        bar.hidden = true;
        document.body.classList.remove('find-open');
        count.textContent = '';
      },
      isOpen: () => !bar.hidden,
      /** The search button again (or Ctrl+F) while open: back to the highlighted match. */
      jump() {
        if (bar.hidden) return api.open();
        if (hits.length) { if (current < 0) current = 0; show(true); }
        input.focus();
      },
      /** Content was redrawn (another conversation): search it again. */
      refresh() { if (!bar.hidden) { watch(true); const t = input.value; clear(); terms = []; search(t, wordsMode); } },
    };
    return api;
  };
})();
