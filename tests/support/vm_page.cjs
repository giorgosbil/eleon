/**
 * The browser pieces a page script expects, for the screen-code tests that run a script
 * from web/assets/ inside node:vm. A test passes its own fakes (document, window, fetch…);
 * whatever it leaves out gets a quiet stand-in here, so a script that starts using one
 * more browser feature does not break every test that does not care about it.
 *
 *   const ctx = page({ document, window, fetch });  vm.createContext(ctx);
 */
'use strict';

function storage() {
  const m = new Map();
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k), clear: () => m.clear() };
}

function page(ctx) {
  const win = ctx.window || (ctx.window = {});
  win.location = win.location || { href: 'https://example.test/other_things/web/app.php' };
  const loc = win.location;
  if (loc.search === undefined) Object.defineProperty(loc, 'search', { get: () => new URL(loc.href).search, configurable: true });
  if (loc.pathname === undefined) Object.defineProperty(loc, 'pathname', { get: () => new URL(loc.href).pathname, configurable: true });
  if (loc.origin === undefined) Object.defineProperty(loc, 'origin', { get: () => new URL(loc.href).origin, configurable: true });
  win.addEventListener = win.addEventListener || (() => {});
  win.removeEventListener = win.removeEventListener || (() => {});
  win.matchMedia = win.matchMedia || (() => ({ matches: false, addEventListener() {}, removeEventListener() {} }));
  win.localStorage = win.localStorage || storage();
  win.sessionStorage = win.sessionStorage || storage();
  win.history = win.history || { pushState() {}, replaceState() {}, state: null };
  win.navigator = win.navigator || { userAgent: 'node', language: 'en', onLine: true };
  if (win.parent === undefined) win.parent = win;

  const defaults = {
    URL, URLSearchParams, console, AbortController, TextEncoder, TextDecoder,
    setTimeout, clearTimeout, setInterval, clearInterval,
    requestAnimationFrame: (fn) => setTimeout(fn, 0), cancelAnimationFrame: clearTimeout,
    queueMicrotask, structuredClone,
    location: loc, localStorage: win.localStorage, sessionStorage: win.sessionStorage,
    history: win.history, navigator: win.navigator, matchMedia: win.matchMedia,
    CustomEvent: class CustomEvent { constructor(type, o) { this.type = type; this.detail = o && o.detail; } },
    Event: class Event { constructor(type) { this.type = type; } },
    Node: class Node {},
  };
  for (const [k, v] of Object.entries(defaults)) if (ctx[k] === undefined) ctx[k] = v;

  const doc = ctx.document || (ctx.document = {});
  doc.body = doc.body || { dataset: {} };
  doc.addEventListener = doc.addEventListener || (() => {});
  doc.removeEventListener = doc.removeEventListener || (() => {});
  doc.dispatchEvent = doc.dispatchEvent || (() => true);
  doc.querySelector = doc.querySelector || (() => null);
  doc.querySelectorAll = doc.querySelectorAll || (() => []);
  doc.getElementById = doc.getElementById || (() => null);
  win.document = win.document || doc;
  return ctx;
}

module.exports = { page };
