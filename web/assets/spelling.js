/**
 * Greek and English spelling, and word suggestions, in the composer (Quill).
 *
 *   window.ecwSpelling.attach(quill, () => mailboxId)
 *
 * Spelling: the words are checked on the server (lib/spelling.php: Hunspell with
 * the Greek and English dictionaries, plus the words this person writes). A wrong
 * word gets a red wavy line, drawn with the CSS Highlight API, so the email itself
 * is never changed by it. Click it: corrections, "Add to dictionary", "Ignore".
 *
 * Suggestions: after 3 letters, the words this person writes most (from their
 * sent emails) that start that way, accents optional ("καλημερα" offers
 * "καλημέρα"). Tab (or a click) takes the first, ↓/↑ choose, Esc closes.
 * Text is written with textContent.
 */
(function () {
  'use strict';
  if (window.ecwSpelling) return;
  const base = window.ecwWebBase || '/';
  const WORD = /[\p{L}][\p{L}'’]*/gu;
  const isLetter = (ch) => /[\p{L}'’]/u.test(ch || '');
  const fold = (w) => String(w).toLocaleLowerCase('el').normalize('NFD').replace(/\p{Mn}/gu, '').replace(/ς/g, 'σ');

  async function call(action, params, body) {
    const url = new URL(base + 'api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => { if (v) url.searchParams.set(k, String(v)); });
    const init = body === undefined ? { credentials: 'same-origin' }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify(body) };
    const r = await fetch(url, init);
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }

  const verdict = new Map();   // word → null (fine) or [corrections] (wrong), shared by every composer on the page
  const ignored = new Set();
  const vocabs = new Map();    // mailbox → Promise<list of words>

  function attach(quill, getMailbox) {
    if (!quill || quill.__ecwSpelling) return quill && quill.__ecwSpelling;
    const root = quill.root;
    root.setAttribute('spellcheck', 'false');   // ours instead of the browser's (which has no Greek in most browsers)
    const box = document.createElement('div');
    box.className = 'spell-suggest';
    box.setAttribute('role', 'listbox');
    box.hidden = true;
    const menu = document.createElement('div');
    menu.className = 'spell-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    quill.container.append(box, menu);
    let timer = null, checking = false, again = false;
    let options = [], chosen = 0, prefixAt = 0, prefix = '', suppress = false;
    const highlightName = 'ecw-spell';

    // ------------------------------------------------------------ spelling
    async function check() {
      if (checking) { again = true; return; }
      const words = (quill.getText().match(WORD) || []).map((w) => w.replace(/['’]+$/u, ''));
      const unknown = [...new Set(words)].filter((w) => w.length >= 2 && !verdict.has(w));
      if (unknown.length) {
        checking = true;
        try {
          const r = await call('spell_check', { mailbox_id: getMailbox() }, { words: unknown.slice(0, 400) });
          if (r.unavailable) return;
          unknown.slice(0, 400).forEach((w) => verdict.set(w, Object.prototype.hasOwnProperty.call(r.wrong || {}, w) ? r.wrong[w] : null));
        } catch (_) { return; } finally { checking = false; }
        if (again) { again = false; setTimeout(check, 0); }
      }
      paint();
    }
    function paint() {
      if (!window.CSS || !CSS.highlights || typeof Highlight === 'undefined') return;
      const ranges = [];
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
      for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const text = node.nodeValue || '';
        WORD.lastIndex = 0;
        for (let m = WORD.exec(text); m; m = WORD.exec(text)) {
          const w = m[0].replace(/['’]+$/u, '');
          if (ignored.has(w) || !Array.isArray(verdict.get(w))) continue;
          const r = new Range();
          r.setStart(node, m.index);
          r.setEnd(node, m.index + w.length);
          ranges.push(r);
        }
      }
      const others = CSS.highlights.get(highlightName);
      // Several composers can share the page: keep the ranges of the others.
      const keep = others ? [...others].filter((r) => !root.contains(r.startContainer)) : [];
      CSS.highlights.set(highlightName, new Highlight(...keep, ...ranges));
    }
    const soon = () => { clearTimeout(timer); timer = setTimeout(check, 700); };

    /** The word at a position of the text: [start, length, word] or null. */
    function wordAt(index) {
      const text = quill.getText();
      let s = index, e = index;
      while (s > 0 && isLetter(text[s - 1])) s--;
      while (e < text.length && isLetter(text[e])) e++;
      const w = text.slice(s, e).replace(/['’]+$/u, '');
      return w ? [s, w.length, w] : null;
    }
    function replaceWord(start, length, word) {
      const formats = quill.getFormat(start, length);
      suppress = true;
      quill.deleteText(start, length, 'user');
      quill.insertText(start, word, formats, 'user');
      quill.setSelection(start + word.length, 0, 'user');
      suppress = false;
    }
    function closeMenu() { menu.hidden = true; }
    function openMenu(start, length, word) {
      menu.textContent = '';
      const add = (label, cls, fn) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = cls; b.setAttribute('role', 'menuitem'); b.textContent = label;
        b.addEventListener('mousedown', (e) => e.preventDefault());   // keep the caret in the email
        b.addEventListener('click', () => { closeMenu(); fn(); });
        menu.appendChild(b);
      };
      const fixes = verdict.get(word) || [];
      if (!fixes.length) { const p = document.createElement('div'); p.className = 'spell-none'; p.textContent = 'No suggestions'; menu.appendChild(p); }
      fixes.forEach((f) => add(f, 'spell-fix', () => replaceWord(start, length, f)));
      const sep = document.createElement('div'); sep.className = 'spell-sep'; menu.appendChild(sep);
      add('Add to dictionary', 'spell-other', () => {
        verdict.set(word, null); paint();
        call('spell_learn', {}, { word }).catch(() => {});
      });
      add('Ignore', 'spell-other', () => { ignored.add(word); paint(); });
      const b = quill.getBounds(start, length);
      menu.style.left = Math.max(0, b.left) + 'px';
      menu.style.top = (b.bottom + 4) + 'px';
      menu.hidden = false;
    }
    root.addEventListener('click', () => {
      const sel = quill.getSelection();
      if (!sel || sel.length) { closeMenu(); return; }
      const at = wordAt(sel.index);
      if (at && Array.isArray(verdict.get(at[2])) && !ignored.has(at[2])) openMenu(at[0], at[1], at[2]); else closeMenu();
    });
    document.addEventListener('mousedown', (e) => { if (!menu.hidden && !menu.contains(e.target)) closeMenu(); });

    // ------------------------------------------------------------ suggestions
    function vocab() {
      const mb = String(getMailbox() || '');
      if (!vocabs.has(mb)) vocabs.set(mb, call('spell_vocab', { mailbox_id: mb }).then((r) => (r.words || []).map((w) => [w, fold(w)])).catch(() => []));
      return vocabs.get(mb);
    }
    function hideBox() { box.hidden = true; options = []; }
    function showBox(at) {
      box.textContent = '';
      options.forEach((w, i) => {
        const o = document.createElement('div');
        o.className = 'spell-option' + (i === chosen ? ' is-on' : '');
        o.setAttribute('role', 'option');
        o.setAttribute('aria-selected', String(i === chosen));
        const typed = document.createElement('span'); typed.className = 'spell-typed'; typed.textContent = w.slice(0, prefix.length);
        o.append(typed, document.createTextNode(w.slice(prefix.length)));
        o.addEventListener('mousedown', (e) => { e.preventDefault(); chosen = i; accept(); });
        box.appendChild(o);
      });
      const hint = document.createElement('div'); hint.className = 'spell-hint'; hint.textContent = 'Tab ↹ to use';
      box.appendChild(hint);
      const b = quill.getBounds(at);
      box.style.left = Math.max(0, b.left) + 'px';
      box.style.top = (b.bottom + 4) + 'px';
      box.hidden = false;
    }
    async function suggest() {
      const sel = quill.getSelection();
      if (!sel || sel.length) { hideBox(); return; }
      const before = quill.getText(Math.max(0, sel.index - 40), Math.min(40, sel.index));
      const m = before.match(/[\p{L}'’]+$/u);
      if (!m || m[0].length < 3 || isLetter(quill.getText(sel.index, 1))) { hideBox(); return; }
      const typed = m[0], f = fold(typed);
      const list = await vocab();
      const found = [];
      for (const [w, wf] of list) {
        if (wf.length > f.length && wf.startsWith(f) && !found.some((x) => fold(x) === wf)) found.push(w);
        if (found.length >= 3) break;
      }
      // The selection may have moved while the words loaded.
      const now = quill.getSelection();
      if (!found.length || !now || now.index !== sel.index) { hideBox(); return; }
      // Written as typed: a capital first letter stays a capital.
      const cap = typed[0] !== typed[0].toLocaleLowerCase('el');
      options = found.map((w) => (cap ? w[0].toLocaleUpperCase('el') + w.slice(1) : w));
      prefix = typed; prefixAt = sel.index - typed.length; chosen = 0;
      showBox(sel.index);
    }
    function accept() {
      const w = options[chosen];
      if (!w) return;
      const next = quill.getText(prefixAt + prefix.length, 1);
      hideBox();
      suppress = true;
      const formats = quill.getFormat(prefixAt, prefix.length);
      quill.deleteText(prefixAt, prefix.length, 'user');
      quill.insertText(prefixAt, w + (next === ' ' ? '' : ' '), formats, 'user');
      quill.setSelection(prefixAt + w.length + 1, 0, 'user');
      suppress = false;
    }
    // Before Quill's own keys (Tab would insert a tab, arrows move the caret).
    root.addEventListener('keydown', (e) => {
      if (box.hidden) return;
      if (e.key === 'Tab' && !e.shiftKey) { e.preventDefault(); e.stopImmediatePropagation(); accept(); }
      else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); e.stopImmediatePropagation();
        chosen = (chosen + (e.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        showBox(prefixAt + prefix.length);
      } else if (e.key === 'Escape') { e.stopImmediatePropagation(); hideBox(); }
      else if (e.key === 'Enter') hideBox();
    }, true);

    quill.on('text-change', (delta, old, source) => {
      soon();
      // A moment later: when the change is announced, Quill has not moved the caret yet.
      if (source === 'user' && !suppress) setTimeout(suggest, 0); else if (!suppress) hideBox();
    });
    quill.on('selection-change', (range) => { if (!range) { hideBox(); closeMenu(); } });
    root.addEventListener('focus', () => { vocab(); soon(); });
    const api = { check: soon, paint, suggest: () => suggest() };
    quill.__ecwSpelling = api;
    soon();
    return api;
  }

  window.ecwSpelling = { attach };
})();
