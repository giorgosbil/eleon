/*
 * Small page-wide helpers, loaded on every page by partials/nav.php.
 *
 *  - Dropdowns (details.menu) stay on screen, and escape boxes that would
 *    clip them (a toolbar that scrolls sideways on a phone).
 *  - "Saved" / error banners of settings pages show as the bottom toast,
 *    the same place as "Saved to Drafts".
 *  - Touch screens: a long press works as a right-click (iPhone Safari never
 *    fires contextmenu), and text boxes do not grab focus on their own, so
 *    opening a panel does not pop the keyboard or zoom the page.
 */
(function () {
  'use strict';
  var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

  // ------------------------------------------------------------- dropdowns
  function clipped(el) {
    for (var p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      var s = getComputedStyle(p);
      if (/(auto|scroll|hidden|clip)/.test(s.overflow + s.overflowX + s.overflowY)) return true;
    }
    return false;
  }
  function place(d) {
    var body = d.querySelector('.menu-body');
    if (!body) return;
    body.style.position = ''; body.style.left = ''; body.style.right = ''; body.style.top = ''; body.style.maxWidth = ''; body.style.maxHeight = '';
    var pad = 8, vw = document.documentElement.clientWidth, vh = window.innerHeight;
    var sum = (d.querySelector('summary') || d).getBoundingClientRect();
    if (clipped(d)) {
      // Fixed to the screen, under its button, so no scrolling box cuts it off.
      body.style.position = 'fixed';
      body.style.right = 'auto';
      body.style.maxWidth = (vw - pad * 2) + 'px';
      var w = body.getBoundingClientRect().width;
      body.style.left = Math.max(pad, Math.min(sum.right - w, vw - w - pad)) + 'px';
      var below = vh - sum.bottom - pad;
      body.style.top = (sum.bottom + 4) + 'px';
      body.style.maxHeight = Math.max(160, below - 4) + 'px';
      body.style.overflowY = 'auto';
      return;
    }
    var r = body.getBoundingClientRect();
    if (r.width > vw - pad * 2) body.style.maxWidth = (vw - pad * 2) + 'px';
    r = body.getBoundingClientRect();
    if (r.left < pad) { body.style.right = 'auto'; body.style.left = (pad - d.getBoundingClientRect().left) + 'px'; }
    else if (r.right > vw - pad) { body.style.left = 'auto'; body.style.right = (d.getBoundingClientRect().right - (vw - pad)) + 'px'; }
  }
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (!d.matches || !d.matches('details.menu') || !d.open) return;
    place(d);
  }, true);
  // A fixed dropdown would stay put while its box scrolls away: close it instead.
  // Only when what scrolled holds the dropdown (the page, or its own box): the
  // mail list refreshing or another panel scrolling must not close it.
  document.addEventListener('scroll', function (e) {
    var open = document.querySelectorAll('details.menu[open] > .menu-body[style*="fixed"]');
    var t = e.target;
    for (var i = 0; i < open.length; i++) {
      var d = open[i].parentElement;
      if (open[i].contains(t)) continue;
      if (t === document || t === document.documentElement || t === document.body || (t.contains && t.contains(d))) d.open = false;
    }
  }, true);
  window.addEventListener('resize', function () {
    var open = document.querySelectorAll('details.menu[open]');
    for (var i = 0; i < open.length; i++) place(open[i]);
  });

  // ------------------------------------------------- banners as the toast
  function toast(text, isError) {
    var t = document.createElement('div');
    t.className = 'toast page-toast' + (isError ? ' error' : '');
    t.setAttribute('role', isError ? 'alert' : 'status');
    var span = document.createElement('span');
    span.textContent = text;
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'toast-action'; x.textContent = '×'; x.setAttribute('aria-label', 'Close');
    x.addEventListener('click', function () { t.remove(); });
    t.appendChild(span); t.appendChild(x);
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('leaving'); setTimeout(function () { t.remove(); }, 300); }, isError ? 8000 : 3200);
  }
  window.ecwPageToast = toast;

  // ------------------------------------------------- people in From / To / Cc / Bcc
  // Each person is their name; a click shows the address beside it (and a copy button),
  // a second click hides it. The address is always in the tooltip. Used by Mail and Cases.
  window.ecwAddressList = function (list) {
    var wrap = document.createElement('span');
    wrap.className = 'addr-list';
    (list || []).forEach(function (r, i) {
      if (!r || (!r.name && !r.email)) return;
      if (i > 0) wrap.appendChild(document.createTextNode(', '));
      var person = document.createElement('span');
      person.className = 'addr';
      var name = document.createElement('button');
      name.type = 'button';
      name.className = 'addr-name';
      name.textContent = r.name || r.email;
      name.title = r.email || '';
      person.appendChild(name);
      if (r.email && r.name && r.name.toLowerCase() !== String(r.email).toLowerCase()) {
        name.setAttribute('aria-expanded', 'false');
        name.addEventListener('click', function (e) {
          e.stopPropagation();
          var shown = person.querySelector('.addr-email');
          if (shown) { shown.remove(); name.setAttribute('aria-expanded', 'false'); return; }
          var box = document.createElement('span');
          box.className = 'addr-email';
          box.appendChild(document.createTextNode('<' + r.email + '>'));
          var copy = document.createElement('button');
          copy.type = 'button';
          copy.className = 'addr-copy';
          copy.title = 'Copy the address';
          copy.setAttribute('aria-label', 'Copy ' + r.email);
          copy.innerHTML = '<i class="fa fa-copy" aria-hidden="true"></i>';
          copy.addEventListener('click', function (ev) {
            ev.stopPropagation();
            (navigator.clipboard ? navigator.clipboard.writeText(r.email) : Promise.reject()).then(function () { toast('Copied ' + r.email); }, function () { toast(r.email); });
          });
          box.appendChild(copy);
          person.appendChild(box);
          name.setAttribute('aria-expanded', 'true');
        });
      } else {
        name.classList.add('addr-plain');
      }
      wrap.appendChild(person);
    });
    return wrap;
  };
  function bannersToToast() {
    var flashes = document.querySelectorAll('main .flash.ok, main .flash.err, .settings-shell .flash.ok, .settings-shell .flash.err');
    var seen = [];
    for (var i = 0; i < flashes.length; i++) {
      var f = flashes[i];
      if (seen.indexOf(f) >= 0 || f.dataset.keep === '1') continue;
      seen.push(f);
      // A banner with links or buttons (a new invitation link…) stays where it is.
      if (f.querySelector('a, button, input, code')) continue;
      var text = f.textContent.trim();
      if (!text) continue;
      toast(text, f.classList.contains('err'));
      f.hidden = true;
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bannersToToast); else bannersToToast();

  // ------------------------------------------------- toast colours
  // Every page's toast: red when something went wrong (.error, set by the
  // page), green when something worked ("Saved", "Sent", "Case updated"…),
  // black for everything else (information, "Sending… Undo"). One place for
  // all the pages' own toast functions, so none of them has to know.
  var OK_START = /^(✓|saved|sent|done|copied|linked|unlinked|added|created|updated|moved|archived|deleted|removed|restored|merged|marked|assigned|scheduled|snoozed|unsnoozed|flagged|unflagged|renamed|closed|reopened|connected|enabled|disabled|imported|exported|uploaded|attached|invited|subscribed|thanks|reply drafted|your notes are now|case updated|case created|draft saved|rule saved|message sent|email sent|all set|ready)\b/i;
  var OK_END = /\b(saved|sent|updated|linked|unlinked|created|added|copied|moved|deleted|removed|archived|restored|merged|scheduled|done|connected|uploaded|attached|renamed|closed|reopened|assigned)\s*[.!]?\s*$/i;
  function paintToast(t) {
    if (t.classList.contains('error')) { if (t.classList.contains('ok')) t.classList.remove('ok'); return; }
    var m = t.querySelector('#toastMsg') || t.querySelector('span') || t;
    var text = (m.textContent || '').trim();
    var ok = !!text && (OK_START.test(text) || OK_END.test(text));
    if (t.classList.contains('ok') !== ok) t.classList.toggle('ok', ok);
  }
  var watched = [];
  function watchToast(t) {
    if (watched.indexOf(t) >= 0) return;
    watched.push(t);
    paintToast(t);
    new MutationObserver(function () { paintToast(t); }).observe(t, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'hidden'] });
  }
  function watchAll(root) {
    if (root.classList && root.classList.contains('toast')) watchToast(root);
    var list = root.querySelectorAll ? root.querySelectorAll('.toast') : [];
    for (var i = 0; i < list.length; i++) watchToast(list[i]);
  }
  function startToasts() {
    watchAll(document.body);
    // Toasts some pages create on the fly (calendar, settings banners).
    new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        for (var j = 0; j < records[i].addedNodes.length; j++) if (records[i].addedNodes[j].nodeType === 1) watchAll(records[i].addedNodes[j]);
      }
    }).observe(document.body, { childList: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startToasts); else startToasts();

  if (!coarse) return;

  // --------------------------------------------- long press = right-click
  var timer = null, sx = 0, sy = 0, nativeFired = false, swallowClick = false, target = null;
  document.addEventListener('touchstart', function (e) {
    if (e.touches.length !== 1) { clearTimeout(timer); return; }
    target = e.target;
    if (target.closest && target.closest('input, textarea, select, [contenteditable="true"], .ql-editor')) return;
    var t = e.touches[0];
    sx = t.clientX; sy = t.clientY; nativeFired = false;
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (nativeFired) return;
      var ev = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: sx, clientY: sy, view: window });
      var handled = !target.dispatchEvent(ev);
      if (handled) {
        swallowClick = true;   // the finger lifting must not also open the email
        if (navigator.vibrate) { try { navigator.vibrate(12); } catch (_) {} }
      }
    }, 520);
  }, { passive: true });
  document.addEventListener('touchmove', function (e) {
    var t = e.touches[0];
    if (t && Math.hypot(t.clientX - sx, t.clientY - sy) > 10) clearTimeout(timer);
  }, { passive: true });
  document.addEventListener('touchend', function () { clearTimeout(timer); }, { passive: true });
  document.addEventListener('touchcancel', function () { clearTimeout(timer); }, { passive: true });
  // Android fires its own contextmenu on a long press: then ours is not needed.
  document.addEventListener('contextmenu', function (e) { if (e.isTrusted) { nativeFired = true; clearTimeout(timer); } }, true);
  document.addEventListener('click', function (e) {
    if (!swallowClick) return;
    swallowClick = false;
    e.preventDefault(); e.stopPropagation();
  }, true);

  // ----------------------------------------- no surprise keyboard / zoom
  // Opening a panel (Teams, the assistant, rules…) used to focus its text box,
  // which pops the keyboard and zooms on a phone. Programmatic focus of a
  // text field is skipped on touch screens; tapping the field still works.
  var typing = /^(text|search|email|url|tel|password|number|date|time|datetime-local)$/;
  var origFocus = HTMLElement.prototype.focus;
  HTMLElement.prototype.focus = function (opts) {
    var el = this;
    var isText = (el.tagName === 'TEXTAREA') || (el.tagName === 'INPUT' && typing.test(el.type || 'text')) || el.isContentEditable;
    if (isText && !el.hasAttribute('data-focus-on-touch') && document.activeElement !== el) return;
    return origFocus.call(el, opts);
  };
})();
