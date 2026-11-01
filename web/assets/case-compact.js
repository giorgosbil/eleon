/**
 * Case page: fold the header away so the emails get the screen.
 *
 * Scrolling the timeline down folds the case details into one line and the
 * thread headers into their titles; scrolling back to the top unfolds them.
 * No click needed. The ⌃ button next to the case name keeps it folded
 * (remembered for every case, in this browser); clicking the one-line summary
 * unfolds it for a moment ("peek") and scrolling on folds it again.
 *
 * Folded thread headers drop their people, date and buttons down on hover
 * (tap on a touch screen) as an overlay, so the timeline never jumps.
 * Desktop only: phones already fold the facts (case-extras.js "More details").
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const body = document.body;
  const top = document.querySelector('.case-top');
  const main = document.querySelector('.case-top-main');
  const ident = document.querySelector('.case-ident');
  const scroller = $('timelineScroll');
  if (!top || !main || !ident || !scroller) return;

  const KEY = 'ecw.caseCompact';
  const FOLD_AT = 60;        // px scrolled before folding
  const UNFOLD_AT = 4;       // back at the top
  const PEEK_RANGE = 240;    // a peek stays open until scrolled this far away
  const wide = window.matchMedia('(min-width: 861px)');
  const touch = window.matchMedia('(hover: none)');

  let pinned = false;
  try { pinned = localStorage.getItem(KEY) === 'pinned'; } catch (_) {}
  let compact = false;
  let peekAt = null;         // scrollTop where a peek was opened

  // ---------------------------------------------------------------- controls
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'case-icon-btn case-compact-btn';
  ident.appendChild(button);

  const mini = document.createElement('button');
  mini.type = 'button';
  mini.className = 'case-facts-mini';
  (document.querySelector('.cn-wrap') || ident).after(mini);

  // ------------------------------------------------------------------ summary
  const text = (id) => {
    const node = $(id);
    if (!node || !node.value) return '';
    return node.tagName === 'SELECT' ? (node.selectedOptions[0]?.textContent || '').trim() : node.value;
  };
  function paintMini() {
    mini.textContent = '';
    const said = [];
    // An icon instead of the word keeps the line short; the tooltip spells it out.
    const add = (icon, label, value, cls) => {
      if (!value) return;
      const chip = document.createElement('span');
      chip.className = 'cfm-chip' + (cls ? ' ' + cls : '');
      if (icon) { const i = document.createElement('i'); i.className = 'fa ' + icon; i.setAttribute('aria-hidden', 'true'); chip.appendChild(i); }
      chip.appendChild(document.createTextNode(value));
      mini.appendChild(chip);
      said.push(label ? label + ': ' + value : value);
    };
    const status = $('caseStatus')?.selectedOptions[0]?.dataset.base || $('caseStatus')?.value || '';
    add('', '', text('caseStatus'), 'cfm-status is-' + status);
    add('fa-user', 'Responsible', text('caseOwner'));
    add('fa-user-gear', 'Handled by', text('caseHandler'));
    add('fa-users', 'Group', text('caseGroup'));
    const due = $('caseDue')?.value;
    if (due) add('fa-calendar', 'Due', new Date(due + 'T00:00:00').toLocaleDateString([], { day: 'numeric', month: 'short' }), $('caseDue').classList.contains('due-late') ? 'is-late' : '');
    mini.title = said.join(' · ') + ' (click to show the details)';
    const caret = document.createElement('i');
    caret.className = 'fa fa-angle-down cfm-caret';
    caret.setAttribute('aria-hidden', 'true');
    mini.appendChild(caret);
  }
  document.addEventListener('ecw:case-summary', () => setTimeout(paintMini, 0));
  document.querySelector('.case-facts')?.addEventListener('change', () => setTimeout(paintMini, 0));

  // -------------------------------------------------------------------- state
  function paintButton() {
    const icon = compact ? 'fa-angles-down' : 'fa-angles-up';
    const label = compact
      ? (pinned ? 'Show the details and stop keeping them folded' : 'Show the details')
      : 'Keep the details folded on every case (they also fold by themselves when you scroll down)';
    button.innerHTML = '<i class="fa ' + icon + '" aria-hidden="true"></i>';
    button.title = label;
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-pressed', String(pinned));
    button.classList.toggle('active', pinned);
  }
  function apply(next) {
    if (next === compact) return;
    compact = next;
    if (next) paintMini();
    body.classList.toggle('case-compact', next);
    mini.setAttribute('aria-hidden', String(!next));
    mini.tabIndex = next ? 0 : -1;
    if (!next) document.querySelectorAll('.tl-head.is-peek').forEach((h) => h.classList.remove('is-peek'));
    paintButton();
  }
  // Only worth folding when there is more to scroll than the fold gives back.
  const roomToScroll = () => scroller.scrollHeight - scroller.clientHeight > 2 * FOLD_AT + (compact ? 0 : top.offsetHeight);

  function update() {
    if (!wide.matches) { peekAt = null; apply(false); return; }
    const y = scroller.scrollTop;
    if (peekAt !== null) {
      if (Math.abs(y - peekAt) < PEEK_RANGE && !(y <= UNFOLD_AT && !pinned)) return;
      peekAt = null;
    }
    if (pinned) { apply(true); return; }
    if (!compact && y > FOLD_AT && roomToScroll()) apply(true);
    else if (compact && y <= UNFOLD_AT) apply(false);
  }
  function peek() {
    peekAt = scroller.scrollTop;
    apply(false);
  }

  let frame = 0;
  scroller.addEventListener('scroll', () => {
    if (frame) return;
    frame = requestAnimationFrame(() => { frame = 0; update(); });
  }, { passive: true });
  wide.addEventListener('change', update);

  // The button folds and keeps folded (pin), or unfolds and stops keeping folded.
  // The summary line only peeks: the pin stays.
  button.addEventListener('click', () => {
    pinned = !compact;
    save();
    if (pinned) { peekAt = null; apply(true); } else peek();
    paintButton();
  });
  mini.addEventListener('click', peek);
  function save() {
    try { pinned ? localStorage.setItem(KEY, 'pinned') : localStorage.removeItem(KEY); } catch (_) {}
  }

  // Touch screens have no hover: a tap on a folded header's title opens its details.
  $('timeline')?.addEventListener('click', (e) => {
    if (!compact || !touch.matches) return;
    const title = e.target.closest('.tl-head-title');
    if (!title) return;
    const head = title.closest('.tl-head');
    document.querySelectorAll('.tl-head.is-peek').forEach((h) => { if (h !== head) h.classList.remove('is-peek'); });
    head.classList.toggle('is-peek');
  });

  paintButton();
  mini.setAttribute('aria-hidden', 'true');
  mini.tabIndex = -1;
  update();
})();
