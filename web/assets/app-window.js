/*
 * The installed app (Chrome / Edge "Install app", manifest.webmanifest).
 * A page of the app opened "in a new tab" from inside the app window used to land in an
 * ordinary browser tab, outside the app. In the app it opens in a new app window instead:
 * Ctrl+click, Shift+click and middle-click on a link of the app, links that open in a new tab
 * (target="_blank"), and every "Open in new tab" / "Open case in a new tab" (window.open).
 * Links to other websites still open in the browser, and in the browser nothing changes.
 * When Chrome offers tabs inside the app (display "tabbed"), Chrome opens them there itself.
 */
(() => {
  'use strict';
  const mode = (q) => window.matchMedia && window.matchMedia('(display-mode: ' + q + ')').matches;
  const inAppWindow = () => mode('standalone') || mode('window-controls-overlay');
  if (!inAppWindow()) return;

  // The app's own pages: this folder (the manifest's scope "./", next to this assets/ folder).
  const scope = new URL('..', document.currentScript ? document.currentScript.src : location.href).href;
  const inScope = (url) => { try { const u = new URL(url, location.href); return u.href.startsWith(scope) ? u.href : null; } catch (_) { return null; } };
  // A page of the app (not a file, an attachment or the api): what is worth a window of its own.
  const isPage = (href) => { const p = new URL(href).pathname; return /\.php$/i.test(p) && !p.includes('/api/'); };

  const nativeOpen = window.open.bind(window);
  /** A new app window the size of this one, a little offset, with the page. */
  function openWindow(href) {
    const w = Math.max(800, window.outerWidth || 1200), h = Math.max(600, window.outerHeight || 800);
    const x = (window.screenX || 0) + 32, y = (window.screenY || 0) + 32;
    return nativeOpen(href, '_blank', 'popup,noopener,width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
  }
  window.ecwOpenWindow = (url) => { const href = inScope(url); return href ? openWindow(href) : nativeOpen(url, '_blank', 'noopener'); };

  // window.open(url, '_blank') of a page of the app: a new app window. Named windows (a pop-out
  // email, a draft) and windows that already ask for their size are left as they are.
  window.open = function (url, target, features) {
    const href = url ? inScope(String(url)) : null;
    if (href && isPage(href) && (!target || target === '_blank') && !/\b(popup|width)\b/i.test(String(features || ''))) return openWindow(href);
    return nativeOpen(url, target, features);
  };

  const appLink = (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.hasAttribute('download')) return null;
    const href = inScope(a.getAttribute('href'));
    return href && isPage(href) && !a.getAttribute('href').startsWith('#') ? href : null;
  };
  // On window, after the page's own handlers (an attachment preview, a row that opens in place).
  window.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0) return;
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || !(e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank')) return;
    const href = appLink(e);
    if (!href) return;
    e.preventDefault();
    openWindow(href);
  });
  // Middle-click (the mouse wheel).
  window.addEventListener('auxclick', (e) => {
    if (e.defaultPrevented || e.button !== 1) return;
    const href = appLink(e);
    if (!href) return;
    e.preventDefault();
    openWindow(href);
  });
})();
