/* The thin loading line along the top edge of every page (as YouTube or GitHub show it).
 *
 * Only for what the person did: going to another page (a link, a form), and the loads a
 * click or a key starts on the page itself (fetch within a moment of it: opening a
 * conversation, a case, a folder). The app's own background calls (new mail, badges,
 * presence) never move it. Nothing shows for what is done within ECW_DELAY, so quick
 * clicks do not flicker; it creeps while waiting, finishes when all is done, and gives up
 * after a while so it never stays stuck. window.ecwProgress.start() / .done() for a script
 * that wants it for its own work. Loaded early by partials/nav.php, before the page's scripts.
 */
(function () {
  if (window.ecwProgress) return;
  var DELAY = 250;          // ms: shorter than this, nothing is shown
  var USER_WINDOW = 1200;   // ms after a click / key / submit: a fetch then counts as the person's
  var GIVE_UP = 20000;      // ms: never stuck for longer

  var style = document.createElement('style');
  style.textContent =
    '.ecw-progress{position:fixed;top:0;left:0;right:0;height:3px;z-index:2147483000;pointer-events:none;opacity:0;transition:opacity .25s}' +
    '.ecw-progress.on{opacity:1}' +
    '.ecw-progress i{display:block;height:100%;width:100%;background:var(--accent,#0057ff);transform-origin:0 50%;transform:scaleX(0);transition:transform .25s ease-out;box-shadow:0 0 6px var(--accent,#0057ff)}' +
    '@media (prefers-reduced-motion: reduce){.ecw-progress i{transition:none}}';
  document.head.appendChild(style);

  var bar = null, fill = null;
  var pending = 0, value = 0, showTimer = null, trickleTimer = null, giveUpTimer = null, lastAct = 0;

  function ensure() {
    if (bar) return;
    bar = document.createElement('div');
    bar.className = 'ecw-progress';
    bar.setAttribute('aria-hidden', 'true');
    fill = document.createElement('i');
    bar.appendChild(fill);
    (document.body || document.documentElement).appendChild(bar);
  }
  function set(v) { value = v; if (fill) fill.style.transform = 'scaleX(' + v + ')'; }
  function show() {
    ensure();
    bar.classList.add('on');
    set(Math.max(value, 0.08));
    clearInterval(trickleTimer);
    // Creeps towards 90 %, slower the further it gets: it never seems finished before it is.
    trickleTimer = setInterval(function () { set(value + (0.9 - value) * 0.08); }, 300);
  }
  function start() {
    pending++;
    clearTimeout(giveUpTimer);
    giveUpTimer = setTimeout(reset, GIVE_UP);
    if (pending !== 1) return;
    if (bar && bar.classList.contains('on')) show();   // still fading out from the last one: it goes on
    else if (!showTimer) showTimer = setTimeout(function () { showTimer = null; if (pending > 0) show(); }, DELAY);
  }
  function done() {
    if (pending > 0) pending--;
    if (pending > 0) return;
    clearTimeout(showTimer); showTimer = null;
    clearTimeout(giveUpTimer);
    if (!bar || !bar.classList.contains('on')) { value = 0; return; }
    clearInterval(trickleTimer);
    set(1);
    setTimeout(function () { if (pending === 0) { bar.classList.remove('on'); setTimeout(function () { if (pending === 0) set(0); }, 250); } }, 200);
  }
  function reset() { pending = 1; done(); }

  // What the person does: the fetches it starts within a moment count, the background ones do not.
  ['pointerdown', 'keydown', 'submit'].forEach(function (type) {
    window.addEventListener(type, function (e) {
      var k = e.key || '';
      if (type === 'keydown' && !(k === 'Enter' || k === ' ' || k.length === 1 || /^Arrow/.test(k))) return;
      lastAct = Date.now();
    }, true);
  });
  if (window.fetch) {
    var realFetch = window.fetch;
    window.fetch = function () {
      if (Date.now() - lastAct > USER_WINDOW) return realFetch.apply(this, arguments);
      start();
      var p = realFetch.apply(this, arguments);
      p.then(done, done);
      return p;
    };
  }

  // Going to another page: a link in this tab (not a new tab, a download, a #jump), or a form.
  function leaving() { pending = 0; start(); }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target && a.target !== '_self' || a.hasAttribute('download')) return;
    var href = a.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    var url;
    try { url = new URL(href, location.href); } catch (_) { return; }
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
    if (/[?&]action=(attachment|draft_file|export|download)/.test(url.search)) return;   // files: no new page comes
    // A script may still take the click over (preview, a menu): only if the page really goes.
    setTimeout(function () { if (!e.defaultPrevented) leaving(); }, 0);
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.method !== 'dialog' && !f.target) setTimeout(function () { if (!e.defaultPrevented) leaving(); }, 0);
  });
  // Back to a page kept in the browser's memory (Back button): nothing is loading any more.
  window.addEventListener('pageshow', function () { pending = 0; clearTimeout(showTimer); showTimer = null; if (bar) { bar.classList.remove('on'); set(0); } clearInterval(trickleTimer); });

  window.ecwProgress = { start: start, done: done };
})();
