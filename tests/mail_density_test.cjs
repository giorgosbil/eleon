// Step 05C tests — Mail UI density and message style.
//
//   node tests/mail_density_test.cjs
//
// app.js's renderMessage() is DOM-building code with no return value the
// harness can compare structurally without a browser — same situation as
// case.js's bubbleCell() in tests/case_bubble_layout_test.cjs, and the same
// fix: load the real file into a vm context with a minimal fake DOM and pull
// the functions under test out through the IIFE's tail.
//
// What this proves, per docs/steps/05C-mail-ui-density.md:
//   - a message header collapses to "Sender · time" by default and only the
//     most recent message in a thread opens expanded ("Collapsed Message
//     Headers" / "Mail List Density" sections);
//   - the expanded header carries From/To/Cc/Date, never permanently, and Cc
//     is only present on the line when the message actually has one.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor() {
    this.dataset = {};
    this.children = [];
    this.listeners = {};
    this.isConnected = true;
    this.hidden = false;
  }
  append(...items) { this.children.push(...items); }
  appendChild(item) { this.append(item); return item; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  querySelectorAll() { return []; }
  querySelector(selector) {
    if (selector === '.message-body') return this.children.find((c) => c.className === 'message-body');
    if (selector === '.message-head') return this.children.find((c) => c.className === 'message-head');
    return null;
  }
}

const document = { body: { dataset: {}, classList: { toggle: () => {} } }, createElement: () => new Element() };
const ctx = {
  document, URL, URLSearchParams,
  window: { addEventListener() {}, location: { href: 'https://example.test/other_things/web/app.php' } },
  console,
};
ctx.Node = Element;   // the test's elements are the page's nodes
require('./support/vm_page.cjs').page(ctx);
vm.createContext(ctx);

const source = fs.readFileSync(__dirname + '/../web/assets/app.js', 'utf8');
const tail = '  wire();\n  boot();';
assert.ok(source.includes(tail), 'app.js still ends the way this harness expects it to');
vm.runInContext(
  source.replace(tail, '  globalThis.mailModule = { renderMessage, latestMessageId, formatCompact };'),
  ctx
);
const { renderMessage, latestMessageId, formatCompact } = ctx.mailModule;

function metaLabels(article) {
  const head = article.querySelector('.message-head');
  const meta = head.children.find((c) => c.className === 'message-meta');
  return meta.children.map((line) => line.children[0].textContent);
}

// ---- collapsed by default, only the newest message opens -------------------
const incoming = {
  id: 1, direction: 'incoming', from: { email: 'john@customer.test', name: 'John Smith' },
  to: [{ email: 'us@example.test' }], cc: [], occurred_at: '2026-09-18 09:02:00',
  attachments: [], body_state: 'fetched', body_html: '<p>Hi</p>', removed: false,
};

const collapsed = renderMessage(incoming, false);
const collapsedHead = collapsed.querySelector('.message-head');
assert.ok(!collapsedHead.open, 'an older message renders with its header collapsed');
console.log('PASS an older message in a thread starts with a collapsed header');

const expanded = renderMessage(incoming, true);
assert.equal(expanded.querySelector('.message-head').open, true, 'the current/newest message opens expanded');
console.log('PASS the message passed isExpanded=true opens its header');

// ---- the compact summary is exactly "Sender · time", never the full header -
const summary = collapsed.querySelector('.message-head').children.find((c) => c.className === 'message-summary');
assert.equal(summary.children[1].textContent, 'John Smith <john@customer.test>');
assert.equal(summary.children[2].textContent, formatCompact(incoming.occurred_at));
console.log('PASS the collapsed summary is exactly the sender and a compact time, nothing else');

// ---- expanding reveals From/To/Cc/Date, Cc only when the message has one ---
assert.deepEqual(metaLabels(collapsed), ['From', 'To', 'Date'],
  'no Cc line is rendered for a message with no Cc recipients');

const withCc = renderMessage({ ...incoming, id: 2, cc: [{ email: 'boss@customer.test' }] }, false);
assert.deepEqual(metaLabels(withCc), ['From', 'To', 'Cc', 'Date'],
  'a Cc recipient adds exactly one extra line to the expanded header');
console.log('PASS the expanded header lists From/To/Cc/Date, Cc only when present');

// ---- the newest message is chosen by occurred_at, not by array order -------
const outOfOrder = [
  { id: 10, occurred_at: '2026-09-18 09:00:00' },
  { id: 12, occurred_at: '2026-09-18 08:00:00' },  // earlier, but listed second
  { id: 11, occurred_at: '2026-09-18 11:45:00' },  // actually latest
];
assert.equal(latestMessageId(outOfOrder), 11,
  'the most recent message is found by comparing timestamps, not by its position in the array');
console.log('PASS the default-expanded message is chosen chronologically, independent of display order');

// ---- compact time formatting: today is a bare time, otherwise date + time --
// The naive DB-style stamp for "right now" in UTC — formatCompact reinterprets
// it as UTC the same way the reading pane's real data does, so this is the
// same instant compared against itself and carries no timezone risk.
const todayStamp = new Date().toISOString().slice(0, 19).replace('T', ' ');
assert.doesNotMatch(formatCompact(todayStamp), /·/, 'a message from today shows only a time, no date clutter');

// Exact day/month order is locale output (Intl), same as the rest of this
// file's formatWhen/formatFull — only the shape (date, a separator, a time)
// is this step's concern.
const oldStamp = '2020-01-02 08:05:00';
const oldCompact = formatCompact(oldStamp);
assert.match(oldCompact, /·/, 'an older message keeps a date next to the time, not a bare time');
assert.match(oldCompact, /02/, 'the short date includes the day');
assert.match(oldCompact, /\d{1,2}:\d{2}/, 'the compact header still carries a time');
console.log('PASS the compact header time drops the date only for messages from today');
