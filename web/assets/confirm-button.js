/**
 * Choices in place of a button, without a popup.
 *
 *   window.ecwChoice(button, [{ label: 'Save to Drafts', onPick: fn }, { label: 'Delete', danger: true, onPick: fn }])
 *
 * The button steps aside and the choices appear where it was. Picking one
 * runs it; Escape, a click elsewhere or a few seconds of nothing bring the
 * button back. Text is written with textContent.
 *
 *   button.addEventListener('click', () => { if (!window.ecwArm(button, 'Click again to discard')) return; …act… });
 *
 * ecwArm is the same for one risky action: the button is replaced by
 * "Discard" (red) and "Cancel"; "Discard" clicks the button again, which then
 * returns true and acts. Works for submit buttons and onclick="" too.
 */
(function () {
  'use strict';
  let open = null;   // the choice now shown (one at a time)

  function close() {
    if (!open) return;
    const { button, wrap, timer, onKey, onDoc } = open;
    open = null;
    clearTimeout(timer);
    document.removeEventListener('keydown', onKey, true);
    document.removeEventListener('pointerdown', onDoc, true);
    wrap.remove();
    button.hidden = false;
    button.classList.remove('choice-open');
  }

  window.ecwChoice = function (button, choices, ms) {
    close();
    const wrap = document.createElement('span');
    wrap.className = 'choice-inline';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Choose');
    choices.forEach((c, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'choice-btn' + (c.danger ? ' danger' : '') + (c.primary ? ' primary' : '');
      if (c.icon) { const ic = document.createElement('i'); ic.className = 'fa ' + c.icon; ic.setAttribute('aria-hidden', 'true'); b.append(ic, ' '); }
      b.append(document.createTextNode(c.label));
      if (c.title) b.title = c.title;
      b.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        close();
        if (c.onPick) c.onPick();
      });
      wrap.appendChild(b);
      if (i === 0) setTimeout(() => b.focus(), 0);
    });
    button.classList.add('choice-open');
    button.after(wrap);
    button.hidden = true;
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); button.focus(); } };
    const onDoc = (e) => { if (!wrap.contains(e.target)) close(); };
    document.addEventListener('keydown', onKey, true);
    setTimeout(() => document.addEventListener('pointerdown', onDoc, true), 0);
    open = { button, wrap, onKey, onDoc, timer: setTimeout(close, ms || 8000) };
  };

  /** "Click again to discard" → "Discard". */
  const short = (label) => {
    const t = String(label || 'Confirm').replace(/^(click )?again to /i, '').trim();
    return t.charAt(0).toUpperCase() + t.slice(1);
  };

  window.ecwArm = function (button, label, ms) {
    if (button.dataset.armed === '1') {
      button.dataset.armed = '';
      return true;
    }
    window.ecwChoice(button, [
      { label: short(label), danger: true, onPick: () => { button.dataset.armed = '1'; button.click(); button.dataset.armed = ''; } },
      { label: 'Cancel', onPick: () => button.focus() },
    ], ms);
    return false;
  };
})();
