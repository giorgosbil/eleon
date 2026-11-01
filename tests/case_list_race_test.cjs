// Task 06 — case list filter bar: debounce and request-ordering.
//
// Same technique as case_refresh_test.cjs: run the real assets/caseList.js
// source in a vm sandbox with a minimal fake DOM, patch the last line to
// expose its closure instead of wiring real event listeners, and drive it
// directly. This is the one piece of the filter bar that is easy to get
// subtly wrong and invisible to a PHP test: a debounced keystroke must
// collapse to a single request, and a request superseded by a newer one
// must never win the render, no matter which one the fake network finishes
// first.
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');

function makeEl(id) {
  return {
    id, value: '', checked: false, hidden: false, disabled: false,
    textContent: '', innerHTML: '', className: '', dataset: {},
    children: [], _listeners: {},
    appendChild(child) { this.children.push(child); return child; },
    addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); },
    setAttribute() {}, removeAttribute() {}, closest() { return null; },
    querySelector() { return null; },
  };
}

const ids = new Map();
const document = {
  getElementById(id) { if (!ids.has(id)) ids.set(id, makeEl(id)); return ids.get(id); },
  createElement(tag) { return makeEl(null); },
  createTextNode(text) { return { nodeType: 3, textContent: text }; },
  querySelectorAll() { return []; },
  addEventListener() {},
};

// Real Node globals (URL/URLSearchParams have been global since Node 10);
// AbortController has not (added in Node 15), so it is faked below,
// matching what caseList.js actually depends on: signal.aborted plus one
// abort listener.
class FakeSignal { constructor() { this.aborted = false; this._listeners = []; } addEventListener(t, fn) { this._listeners.push(fn); } }
let abortCalls = 0;
class FakeAbortController {
  constructor() { this.signal = new FakeSignal(); }
  abort() { abortCalls++; this.signal.aborted = true; this.signal._listeners.forEach((fn) => fn()); }
}

const pending = []; // {url, resolve, reject}
let fetchCalls = 0;
function fetch(url, opts) {
  fetchCalls++;
  return new Promise((resolve, reject) => {
    const entry = { url, resolve: (data) => resolve({ ok: true, json: async () => data }), reject };
    if (opts && opts.signal) {
      if (opts.signal.aborted) { reject(Object.assign(new Error('aborted'), { name: 'AbortError' })); return; }
      opts.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
    }
    pending.push(entry);
  });
}

const window = {
  location: { href: 'https://example.test/other_things/web/cases.php', pathname: '/other_things/web/cases.php', search: '' },
  history: { pushState() {}, replaceState() {} },
  addEventListener() {},
  setTimeout: setTimeout, clearTimeout: clearTimeout,
};

// Seed the bootstrap JSON caseList.js reads on load.
document.getElementById('caseListBootstrap').textContent = JSON.stringify({
  filters: { status: 'open', q: '', mailbox_ids: [], participant: '', date_preset: '', date_from: '', date_to: '', has_attachments: '', sort: 'last_activity', dir: '' },
  total: 0, page: 1, per_page: 50,
});

let source = fs.readFileSync(__dirname + '/../web/assets/caseList.js', 'utf8');
const before = source.length;
source = source.replace(/\}\)\(\);\s*$/, 'globalThis.__test = { state, fetchAndRender, scheduleFetch };\n})();\n');
assert.notEqual(source.length, before, 'the test hook patch must actually match — caseList.js was edited in a way this test no longer recognizes');

const ctx = { window, document, URL, URLSearchParams, AbortController: FakeAbortController, fetch, console, setTimeout, clearTimeout };
vm.createContext(ctx);
vm.runInContext(source, ctx);

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

(async () => {
  // ---------------------------------------------------------- debounce
  ctx.__test.scheduleFetch(false);
  ctx.__test.scheduleFetch(false);
  ctx.__test.scheduleFetch(false);
  await sleep(50);
  assert.equal(fetchCalls, 0, 'three rapid calls inside the 250ms window have not fetched yet');
  await sleep(300);
  assert.equal(fetchCalls, 1, 'three rapid calls collapse into exactly one request');
  pending.shift().resolve({ cases: [], total: 0, page: 1, per_page: 50 });
  await sleep(10);
  console.log('PASS debounce collapses rapid input into a single request');

  // ------------------------------------------------------- request ordering
  fetchCalls = 0; abortCalls = 0; pending.length = 0;
  const first = ctx.__test.fetchAndRender(false);
  const second = ctx.__test.fetchAndRender(false); // supersedes `first` synchronously
  assert.equal(fetchCalls, 2, 'both requests were actually sent');
  assert.equal(abortCalls, 1, 'starting the second request aborted the first — never two in flight at once');
  // The first entry is already rejected (its abort listener fired synchronously
  // above) — resolving it now is a no-op, exactly like a real aborted fetch()
  // promise that a stray late network response could not un-reject.
  pending[0].resolve({ cases: [], total: 99, page: 1, per_page: 50 }); // stale — must not win
  pending[1].resolve({ cases: [], total: 42, page: 1, per_page: 50 }); // the survivor
  await Promise.all([first, second]);
  assert.equal(document.getElementById('caseFilterCount').textContent, '42 cases',
    'only the surviving (second) request ever rendered — a stale response cannot win');
  console.log('PASS a superseded request is aborted and never renders, even if nothing else resolves first');
})().catch((e) => { console.error(e); process.exitCode = 1; });
