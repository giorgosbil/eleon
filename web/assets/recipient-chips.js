/*
 * Recipients as chips in To / Cc / Bcc, like Outlook: each address is a chip
 * that can be dragged to another field (To → Cc, Cc → Bcc…), removed with its
 * ×, or double-clicked to edit. Typing works as before: a comma, semicolon,
 * Enter or leaving the field turns what was typed into a chip.
 *
 * The rest of the composer keeps reading and writing input.value as
 * "a@x.com, b@y.com" (app.js, mail-extras.js, compose-meeting.js): `value` on
 * these inputs is the chips plus whatever is being typed, and setting it
 * draws the chips. Only the text being typed is in the input itself, which
 * input._ecwRaw reads and writes (the address suggestions use it).
 */
(() => {
  'use strict';
  const IDS = ['fieldTo', 'fieldCc', 'fieldBcc'];
  const native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  const looksLikeEmail = (s) => /^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]{2,}$/.test(s);
  const tokenEmail = (s) => { const m = String(s).match(/<([^>]+)>/); return (m ? m[1] : String(s)).trim(); };
  let dragging = null;   // { email, from: input }

  function setup(input) {
    if (!input || input._ecwChips) return;
    const box = document.createElement('div');
    box.className = 'rcpt-box';
    input.parentNode.insertBefore(box, input);
    box.appendChild(input);
    input.classList.add('rcpt-input');
    let emails = [];

    const raw = { get: () => native.get.call(input), set: (v) => native.set.call(input, v) };
    const changed = () => input.dispatchEvent(new Event('input', { bubbles: true }));   // autosave; the suggestions ignore it

    function draw() {
      box.querySelectorAll('.rcpt-chip').forEach((c) => c.remove());
      emails.forEach((email, i) => {
        const chip = document.createElement('span');
        chip.className = 'rcpt-chip' + (looksLikeEmail(email) ? '' : ' invalid');
        chip.draggable = true;
        chip.title = looksLikeEmail(email) ? email + ' · drag to To, Cc or Bcc, double-click to edit' : 'Not a valid address: double-click to fix';
        chip.dataset.email = email;
        const text = document.createElement('span');
        text.className = 'rcpt-chip-text';
        text.textContent = email;
        const x = document.createElement('button');
        x.type = 'button'; x.className = 'rcpt-chip-x'; x.textContent = '×'; x.tabIndex = -1;
        x.setAttribute('aria-label', 'Remove ' + email);
        x.addEventListener('mousedown', (e) => e.preventDefault());
        x.addEventListener('click', (e) => { e.stopPropagation(); remove(i); input.focus(); });
        chip.append(text, x);
        chip.addEventListener('dblclick', () => {   // back to text, to fix a typo
          remove(i, true);
          raw.set(raw.get() ? raw.get() + ', ' + email : email);
          input.focus();
          input.setSelectionRange(raw.get().length, raw.get().length);
        });
        chip.addEventListener('dragstart', (e) => {
          dragging = { email, from: input };
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', email);
          chip.classList.add('dragging');
          // Cc / Bcc folded away: show them, so there is somewhere to drop.
          const cc = document.getElementById('ccBccFields');
          if (cc && cc.hidden) cc.hidden = false;
          document.body.classList.add('rcpt-dragging');
        });
        chip.addEventListener('dragend', () => { chip.classList.remove('dragging'); dragging = null; document.body.classList.remove('rcpt-dragging'); document.querySelectorAll('.rcpt-box.drop-here').forEach((b) => b.classList.remove('drop-here')); });
        box.insertBefore(chip, input);
      });
      box.classList.toggle('has-chips', emails.length > 0);
    }
    function add(list) {
      let added = false;
      list.forEach((s) => {
        const email = tokenEmail(s);
        if (email && !emails.some((x) => x.toLowerCase() === email.toLowerCase())) { emails.push(email); added = true; }
      });
      if (added) draw();
      return added;
    }
    function remove(i, quiet) { emails.splice(i, 1); draw(); if (!quiet) changed(); }
    // What was typed becomes chips: every finished address (followed by , or ;), or all of it.
    function commit(all) {
      const v = raw.get();
      const parts = v.split(/[,;]/);
      const rest = all ? '' : parts.pop();
      const done = parts.map((s) => s.trim()).filter(Boolean);
      if (all && v.trim()) done.push(v.trim().replace(/[,;]\s*$/, ''));
      if (!done.length) return false;
      add(done);
      raw.set(all ? '' : rest.replace(/^\s+/, ''));
      return true;
    }

    Object.defineProperty(input, 'value', {
      configurable: true,
      get: () => emails.concat(raw.get().trim() ? [raw.get().trim()] : []).join(', '),
      set: (v) => {
        emails = [];
        const parts = String(v ?? '').split(/[,;]/).map((s) => s.trim()).filter(Boolean);
        emails = parts.map(tokenEmail).filter((e, i, all) => e && all.findIndex((x) => x.toLowerCase() === e.toLowerCase()) === i);
        raw.set('');
        draw();
      },
    });
    input._ecwRaw = raw;
    input._ecwChips = { add: (email) => { add([email]); changed(); }, emails: () => emails.slice(), remove: (email) => { const i = emails.findIndex((x) => x.toLowerCase() === email.toLowerCase()); if (i >= 0) remove(i); } };

    input.addEventListener('input', (e) => { if (e.isTrusted && /[,;]/.test(raw.get())) { commit(false); changed(); } });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && raw.get().trim() && !e.defaultPrevented) {
        // The suggestion list handles Enter itself (mail-extras.js) when it is open.
        if (document.querySelector('.address-suggest:not([hidden])')) return;
        e.preventDefault(); commit(true); changed();
      } else if (e.key === 'Backspace' && !raw.get() && input.selectionStart === 0 && emails.length) {
        e.preventDefault(); remove(emails.length - 1);
      }
    });
    input.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== input && raw.get().trim() && commit(true)) changed(); }, 200));
    box.addEventListener('mousedown', (e) => { if (e.target === box) { e.preventDefault(); input.focus(); } });
    input.addEventListener('paste', () => setTimeout(() => { if (/[,;\s]/.test(raw.get().trim()) && commit(false)) changed(); }, 0));

    // A chip dropped here from another field (or this one: nothing changes).
    box.addEventListener('dragover', (e) => { if (!dragging) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; box.classList.add('drop-here'); });
    box.addEventListener('dragleave', (e) => { if (!box.contains(e.relatedTarget)) box.classList.remove('drop-here'); });
    box.addEventListener('drop', (e) => {
      if (!dragging) return;
      e.preventDefault();
      box.classList.remove('drop-here');
      const { email, from } = dragging;
      if (from === input) return;
      from._ecwChips.remove(email);
      add([email]);
      changed();
    });
  }

  function init() { IDS.forEach((id) => setup(document.getElementById(id))); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
