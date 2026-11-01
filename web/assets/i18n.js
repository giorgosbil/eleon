/**
 * Translates the interface in the browser (Greek today). The app is written in
 * English; window.ECW_DICT (assets/lang/el.js) maps each English interface text
 * to its translation, and window.ECW_DICT_PATTERNS handles texts with numbers
 * or names in them ("3 selected").
 *
 * It works on whole text nodes and on title / placeholder / aria-label / alt,
 * as the page is parsed and whenever scripts add or change something
 * (MutationObserver). It NEVER touches mail, notes, signatures, editors or
 * anything people typed: those areas are skipped (SKIP below), and only exact
 * known interface texts are replaced.
 */
(function () {
  'use strict';
  const dict = window.ECW_DICT;
  if (!dict) return;
  const map = new Map(Object.entries(dict));
  const patterns = (window.ECW_DICT_PATTERNS || []).map(([en, tr]) => {
    // "{n} selected" → /^(\d+) selected$/, keeping {n} / {name} values in order.
    const names = [];
    const rx = new RegExp('^' + en.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\{(\w+)\\\}/g, (_, n) => { names.push(n); return n === 'n' ? '(\\d+(?:[.,]\\d+)?)' : '(.+?)'; }) + '$');
    return { rx, names, tr };
  });
  const SKIP = '.message-body, .message-quoted, .signature-content, .message-signature, .compose-signature-preview, .ql-editor, [contenteditable="true"], '
    + '.np-note-body, .note-body, .tl-note-body, .bubble-body, .bubble-quoted, .row-subject, .row-preview, .row-from, .message-from, '
    + '#threadSubject, .contact-notes, .contact-title h1, .attachment, .notranslate, [data-no-i18n], textarea, pre, code, script, style, .tag-chip, .case-badge, '
    // Names people gave things: cases, groups, contacts, rules.
    + '#caseName, .case-name, a[href^="case.php?id="], .group-badge, .case-group-tab[data-group-id]:not([data-group-id="0"]), '
    + '#caseGroup option:not([value=""]), #fGroup option:not([value="0"]):not([value="-1"]), .np-card-text, .contact-row-text strong, .contact-case-name, .contact-conv-subject, .rule-text strong';
  const ATTRS = ['title', 'placeholder', 'aria-label', 'alt'];

  /**
   * One English word, two meanings ("Open" the action / "Open" the status): an
   * ancestor with data-i18n-ctx="status" makes "status:Open" win.
   */
  function tr(text, el) {
    const key = text.trim();
    if (!key || key.length > 400) return null;
    const ctx = el && el.closest ? el.closest('[data-i18n-ctx]')?.dataset.i18nCtx : null;
    const inCtx = ctx ? map.get(ctx + ':' + key) : undefined;
    if (inCtx !== undefined) return text.replace(key, inCtx);
    const hit = map.get(key);
    if (hit !== undefined) return text.replace(key, hit);
    for (const p of patterns) {
      const m = key.match(p.rx);
      if (!m) continue;
      let out = p.tr;
      p.names.forEach((n, i) => { out = out.replace('{' + n + '}', m[i + 1]); });
      return text.replace(key, out);
    }
    return null;
  }
  const skipped = (el) => !!(el && el.closest && el.closest(SKIP));

  function doText(node) {
    if (!node.nodeValue || !/[A-Za-z]/.test(node.nodeValue) || skipped(node.parentElement)) return;
    const t = tr(node.nodeValue, node.parentElement);
    if (t !== null && t !== node.nodeValue) node.nodeValue = t;
  }
  function doAttrs(el) {
    if (skipped(el)) return;
    for (const a of ATTRS) {
      const v = el.getAttribute(a);
      if (!v || !/[A-Za-z]/.test(v)) continue;
      const t = tr(v, el);
      if (t !== null && t !== v) el.setAttribute(a, t);
    }
    if (el.tagName === 'INPUT' && (el.type === 'submit' || el.type === 'button') && el.value) {
      const t = tr(el.value);
      if (t !== null) el.value = t;
    }
    if (el.tagName === 'OPTION' && el.firstChild === null && el.label) {
      const t = tr(el.label);
      if (t !== null) el.label = t;
    }
  }
  function walk(root) {
    if (root.nodeType === 3) { doText(root); return; }
    if (root.nodeType !== 1) return;
    if (skipped(root)) return;
    doAttrs(root);
    const w = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
      acceptNode: (n) => (n.nodeType === 1 && n.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
    });
    let n;
    while ((n = w.nextNode())) {
      if (n.nodeType === 3) doText(n); else doAttrs(n);
    }
  }

  const titleT = () => { const t = tr(document.title); if (t !== null && t !== document.title) document.title = t; };
  const obs = new MutationObserver((list) => {
    for (const m of list) {
      if (m.type === 'childList') m.addedNodes.forEach(walk);
      else if (m.type === 'characterData') doText(m.target);
      else if (m.type === 'attributes') doAttrs(m.target);
    }
  });
  obs.observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
  walk(document.documentElement);
  titleT();
  new MutationObserver(titleT).observe(document.querySelector('title') || document.head, { childList: true, characterData: true, subtree: true });
  document.addEventListener('DOMContentLoaded', () => { walk(document.body); titleT(); });

  /** For scripts that build text themselves: window.ecwT('Saved') → 'Αποθηκεύτηκε'. */
  window.ecwT = (text) => { const t = tr(String(text)); return t === null ? String(text) : t; };
})();
