// Task 03 — right-hand thread details panel.
//
//   node tests/thread_details_pane_test.cjs
//
// threadDetailsPane.js is pure DOM-building code shared by app.js (one open
// thread) and case.js (the thread with focus among several side by side), so
// it is tested the same way as case.js/app.js elsewhere in this suite: load
// the real file into a vm context with a minimal fake DOM and inspect what it
// builds. No jsdom, no browser — same convention as pane_resizer_test.cjs.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.dataset = {};
    this.attrs = {};
    this.listeners = {};
    this._text = '';
    this.classes = new Set();
    this.hidden = false;
    this.classList = {
      add: (...names) => names.forEach((n) => this.classes.add(n)),
      toggle: (name, force) => (force ? this.classes.add(name) : this.classes.delete(name)),
      contains: (n) => this.classes.has(n),
    };
  }
  get textContent() { return this._text; }
  set textContent(v) { this._text = v; this.children = []; }
  appendChild(child) { this.children.push(child); return child; }
  append(...items) { items.forEach((i) => this.appendChild(i)); }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  setAttribute(name, value) { this.attrs[name] = value; }
  querySelector() { return null; }
}

function newDocument() {
  return { createElement: (tag) => new Element(tag) };
}

function newLocalStorage() {
  const store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    _store: store,
  };
}

function loadModule() {
  const src = fs.readFileSync(__dirname + '/../web/assets/threadDetailsPane.js', 'utf8');
  // Share this realm's Array/Map/Set/etc. with the vm context — otherwise
  // arrays/maps built inside it are cross-realm objects that look identical
  // but fail deepStrictEqual against this file's own literals.
  const ctx = {
    window: {}, document: newDocument(), localStorage: newLocalStorage(), URL, console,
    Array, Map, Set, Math, Number, String, Object, JSON,
  };
  vm.createContext(ctx);
  vm.runInContext(src, ctx);
  return ctx;
}

// Flatten every descendant with a textContent, for assertions that do not
// want to know the exact DOM shape (mirrors how the app itself would just
// read what got rendered).
function allText(el) {
  const out = [el._text || ''];
  (el.children || []).forEach((c) => out.push(...allText(c)));
  return out;
}
function containsText(el, needle) {
  return allText(el).some((t) => t.includes(needle));
}

// --- ecwComputeThreadDetails: overview, participants, files, links --------
{
  const { window } = loadModule();
  const input = {
    subject: 'Re: Bracket RFQ',
    caseInfo: { number: 'CASE-42', name: 'Bracket tooling' },
    mailboxLabel: 'buyer@example.test',
    messages: [
      {
        id: 1,
        from: { name: 'Alex Buyer', email: 'Alex@Example.test' },
        to: [{ name: 'Supplier Co', email: 'sales@supplier.test' }],
        cc: [],
        occurred_at: '2026-09-10 09:00:00',
        attachments: [{ id: 11, filename: 'drawing.pdf', mime_type: 'application/pdf', size: 204800 }],
        body_html: '<p>See <a href="https://supplier.test/spec?rev=2">spec</a> and https://example.test/raw</p>',
      },
      {
        id: 2,
        from: { name: 'Supplier Co', email: 'sales@supplier.test' },
        to: [{ name: null, email: 'alex@example.test' }],
        cc: [{ name: 'Manager', email: 'manager@example.test' }],
        occurred_at: '2026-09-11 14:30:00',
        attachments: [],
        body_html: '<p>Quote attached. Also see https://supplier.test/spec?rev=2 again.</p>',
      },
    ],
  };

  const details = window.ecwComputeThreadDetails(input);

  assert.equal(details.overview.subject, 'Re: Bracket RFQ');
  assert.equal(details.overview.caseName, 'CASE-42 · Bracket tooling');
  assert.equal(details.overview.mailbox, 'buyer@example.test');
  assert.equal(details.overview.messageCount, 2);
  assert.equal(details.overview.firstDate, '2026-09-10 09:00:00');
  assert.equal(details.overview.lastDate, '2026-09-11 14:30:00');
  console.log('PASS overview: subject, case, mailbox, message count and date range');

  // alex@example.test appears as From (msg 1, capitalized) and To (msg 2,
  // lowercase) — must merge into one participant, case-insensitively, with
  // both roles and a message count of 2.
  const alex = details.participants.find((p) => p.email.toLowerCase() === 'alex@example.test');
  assert.ok(alex, 'the two spellings of the same address merge into one participant');
  assert.deepEqual(alex.roles.sort(), ['sender', 'to']);
  assert.equal(alex.messageCount, 2);
  assert.equal(alex.name, 'Alex Buyer', 'the name from either message is kept even though msg 2 has none');

  // Sorted by message count desc: supplier appears in both messages (sender
  // once, to once) same as alex, manager only once — must sort after.
  assert.equal(details.participants[details.participants.length - 1].email, 'manager@example.test');
  console.log('PASS participants: merged by email, roles combined, sorted by message count');

  assert.equal(details.files.length, 1);
  assert.equal(details.files[0].filename, 'drawing.pdf');
  assert.equal(details.files[0].sender.email, 'Alex@Example.test');
  console.log('PASS files: attachments carry filename and sender through');

  // Two distinct URLs (href + bare), the repeated one deduplicated.
  assert.equal(details.links.length, 2);
  const domains = details.links.map((l) => l.domain).sort();
  assert.deepEqual(domains, ['example.test', 'supplier.test']);
  console.log('PASS links: href + bare urls extracted, deduplicated, domain resolved');
}

// --- empty thread: every section has nothing to show -----------------------
{
  const { window } = loadModule();
  const details = window.ecwComputeThreadDetails({ subject: '', caseInfo: null, mailboxLabel: null, messages: [] });
  assert.equal(details.overview.subject, '(no subject)');
  assert.equal(details.overview.caseName, null);
  assert.equal(details.overview.messageCount, 0);
  // .length, not deepEqual([]) — an array literal built inside the vm
  // context is a cross-realm object and fails deepStrictEqual against this
  // file's own [] even when both are, in every observable way, empty.
  assert.equal(details.participants.length, 0);
  assert.equal(details.files.length, 0);
  assert.equal(details.links.length, 0);
  console.log('PASS an empty thread computes empty sections instead of throwing');
}

// --- rendering: escaping, empty states, section collapse persistence ------
{
  const { window, document, localStorage } = loadModule();
  const maliciousName = '<img src=x onerror=alert(1)>';
  const details = window.ecwComputeThreadDetails({
    subject: maliciousName,
    caseInfo: null,
    mailboxLabel: null,
    messages: [{
      id: 1, from: { name: maliciousName, email: 'a@example.test' }, to: [], cc: [],
      occurred_at: '2026-09-10 09:00:00', attachments: [{ id: 5, filename: maliciousName, mime_type: '', size: 10 }],
      body_html: '',
    }],
  });

  const container = document.createElement('div');
  const onParticipantClick = [];
  window.ecwRenderThreadDetailsPane(container, details, {
    formatFull: (v) => 'FULL:' + v,
    onParticipantClick: (p) => onParticipantClick.push(p),
  });

  // The raw string travels through as data (textContent), never becomes markup.
  assert.ok(containsText(container, maliciousName), 'the malicious string is present as plain text content');
  const serialize = (el) => JSON.stringify(el, (k, v) => (k === 'listeners' ? undefined : v));
  assert.ok(!serialize(container).match(/"tag":"img"/), 'no <img> element was ever created from mail data');
  console.log('PASS names/subjects/filenames render as text, never as markup');

  // Links section is empty (no body_html) -> empty state present.
  assert.ok(containsText(container, 'No links found in this thread.'));
  console.log('PASS empty sections show their empty-state copy');

  // Clicking the rendered participant button calls back with that participant.
  const participantButton = container.children
    .find((s) => s.children[0].textContent.startsWith('Participants'))
    .children[1].children[0].children[0].children[0]; // section -> body -> <ul> -> <li> -> <button>
  participantButton.listeners.click();
  assert.equal(onParticipantClick.length, 1);
  assert.equal(onParticipantClick[0].email, 'a@example.test');
  console.log('PASS clicking a participant invokes onParticipantClick with that participant');

  // Section open/collapse state persists to localStorage on toggle, and a
  // fresh render later reads it back instead of defaulting to open.
  const overviewSection = container.children[0];
  assert.equal(overviewSection.open, true, 'sections default open the first time');
  overviewSection.open = false;
  overviewSection.listeners.toggle();
  assert.equal(localStorage.getItem('ot.pane.details.section.overview'), '0');

  const second = document.createElement('div');
  window.ecwRenderThreadDetailsPane(second, details, {});
  assert.equal(second.children[0].open, false, 'a later render restores the collapsed state from localStorage');
  console.log('PASS section collapse state persists across re-renders');
}
