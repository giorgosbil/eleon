// Step 05B tests — case columns as compact chat conversations.
//
//   node tests/case_bubble_layout_test.cjs
//
// case.js is DOM-building code with no return value the way lib/timeline.php
// has — the only way to check it produces WhatsApp-style bubbles (left for
// incoming, right for outgoing, via .tl-cell's flex alignment in case.css) is
// to actually run the DOM-building functions and inspect what they made.
// Same technique as tests/body_loading_test.cjs: load the real file into a
// vm context with a minimal fake DOM, and swap the IIFE's tail (which would
// otherwise call wire()/load() against a real page) for an export of the
// functions under test.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor() {
    this.dataset = {};
    this.style = { setProperty() {} };
    this.classes = new Set();
    this.classList = { add: (...names) => names.forEach(n => this.classes.add(n)),
      toggle: (name, force) => force ? this.classes.add(name) : this.classes.delete(name) };
    this.scrollHeight = 300;
    this.children = [];
    this.listeners = {};
    this.isConnected = true;
    this.hidden = false;
  }
  append(...items) { this.children.push(...items); }
  appendChild(item) { this.append(item); return item; }
  insertBefore(item, ref) { const i = ref ? this.children.indexOf(ref) : -1; i < 0 ? this.children.push(item) : this.children.splice(i, 0, item); return item; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  setAttribute(name, value) { this[name] = value; }
  getAttribute(name) { return this[name]; }
  closest() { return null; }
}

const ids = new Map();
const document = { body: { dataset: {} }, createElement: () => new Element(), addEventListener() {},
  getElementById: id => { if (!ids.has(id)) ids.set(id, new Element()); return ids.get(id); } };
const window = { addEventListener() {}, matchMedia: () => ({ matches: false, addEventListener: () => {} }) };
const ctx = { document, window, URL, console, requestAnimationFrame: fn => fn() };
vm.createContext(ctx);

const source = fs.readFileSync(__dirname + '/../web/assets/case.js', 'utf8');
const tail = '  wire();\n  load().then(loadOwners);';
assert.ok(source.includes(tail), 'case.js still ends the way this harness expects it to');
vm.runInContext(
  source.replace(tail, '  globalThis.caseModule = { bubbleCell, gapRow, columnHeader, renderTimeline, state, installBubbleClamp };'),
  ctx
);
const { bubbleCell, gapRow, columnHeader } = ctx.caseModule;

function baseBubble(direction) {
  return {
    from: { email: 'a@example.test', name: 'A' },
    to: [], cc: [], subject: 'Re: axis problem', direction, removed: false,
    body_state: 'fetched', body_html: '<p>Text</p>', signature_html: '',
    quoted_html: '', has_quoted: false, has_attachments: false, attachments: [],
    web_link: null, reply_to: null,
  };
}

// ---- bubble alignment: the whole point of this step -----------------------
const incomingCell = bubbleCell(
  { message_id: 1, thread_id: 1, occurred_at: '2026-09-17 09:00:00', bubble: baseBubble('incoming') },
  4, 2
);
assert.equal(incomingCell.className, 'tl-cell dir-incoming',
  'an incoming message gets the class that makes case.css left-align it');

const outgoingCell = bubbleCell(
  { message_id: 2, thread_id: 1, occurred_at: '2026-09-17 09:01:00', bubble: baseBubble('outgoing') },
  5, 2
);
assert.equal(outgoingCell.className, 'tl-cell dir-outgoing',
  'an outgoing message gets the class that makes case.css right-align it');

// The row/column placement that keeps one row a global moment is untouched
// by the alignment change.
assert.equal(incomingCell.style.gridRow, '4');
assert.equal(incomingCell.style.gridColumn, '2');
assert.equal(outgoingCell.style.gridRow, '5');
console.log('PASS bubble cells carry a direction class case.css uses to align them left/right');

// The bubble itself (the <article>) still carries its own direction class,
// independent of the cell's — case.css uses that one for the accent border.
const article = incomingCell.children.find((c) => c.id === 'msg-1');
assert.ok(article, 'the bubble article is still nested inside the cell');
assert.equal(article.className, 'bubble incoming');
console.log('PASS the bubble article keeps its own incoming/outgoing class for the accent border');

// ---- gap rows: six buckets, none of them proportional ----------------------
for (const [size, px] of [['xs', '8'], ['sm', '14'], ['md', '20'], ['lg', '28'], ['xl', '36']]) {
  const row = gapRow({ seconds: 999, label: '+something', size, new_day: false }, '2026-09-17 10:00:00', 3);
  assert.equal(row.className, 'tl-gap size-' + size,
    'a "' + size + '" gap gets the class case.css maps to ' + px + 'px, not a proportional height');
}
const dayRow = gapRow({ seconds: 90000, label: '+1 day 1h', size: 'xl', new_day: true }, '2026-09-18 10:00:00', 3);
assert.equal(dayRow.className, 'tl-gap size-xl new-day', 'crossing midnight adds the day-separator styling hook');
console.log('PASS gap rows carry the six-bucket size class, never a proportional inline height');

// ---- column header copy: "N messages", matching the step's own examples ---
const one = columnHeader({ label: 'Customer', subject: '', message_count: 1, participants: [], latest_at: null, thread_id: 1, mailbox_id: 1 });
const many = columnHeader({ label: 'Hexagon Italy', subject: '', message_count: 8, participants: [], latest_at: null, thread_id: 2, mailbox_id: 1 });
assert.equal(one.children[0].children[1].textContent, '1 message');
assert.equal(many.children[0].children[1].textContent, '8 messages');
console.log('PASS the column header pluralizes the message count the way the spec\'s examples do');

// One sender appears in two distinct conversations. It must never merge columns.
const {renderTimeline, state, installBubbleClamp} = ctx.caseModule;
const columns = [
  {thread_id:11, mailbox_id:1, label:'Project one', subject:'Project one', participants:[{name:'A'},{name:'B'}], message_count:2},
  {thread_id:22, mailbox_id:1, label:'Project two', subject:'Project two', participants:[{name:'B'},{name:'C'}], message_count:1},
];
renderTimeline({columns, events:[
  {type:'email', message_id:11, thread_id:11, occurred_at:'2026-09-17 09:00:00', bubble:baseBubble('incoming')},
  {type:'email', message_id:22, thread_id:22, occurred_at:'2026-09-17 09:01:00', bubble:baseBubble('outgoing')},
  {type:'email', message_id:33, thread_id:11, occurred_at:'2026-09-17 09:02:00', bubble:baseBubble('outgoing')},
]});
const grid = ids.get('timeline');
const headers = grid.children.filter(c => c.className === 'tl-head');
assert.deepEqual(headers.map(c => c.style.gridColumn), ['2','3']);
assert.deepEqual(headers.map(c => c.style.gridRow), ['1','1']);
assert.equal(headers[0].children[1].children[0].textContent, 'A ↔ B');
assert.equal(headers[1].children[1].children[0].textContent, 'B ↔ C');
const cells = grid.children.filter(c => c.className.startsWith('tl-cell'));
assert.deepEqual(cells.map(c => c.style.gridColumn), ['2','3','2']);
assert.deepEqual(cells.map(c => c.dataset.threadId), ['11','22','11']);
console.log('PASS columns group messages by thread even when participants overlap');

const content = new Element(); content.scrollHeight = 600;   // taller than the 420px an email folds from
const container = new Element();
installBubbleClamp(content, container);
const more = container.children[0];
assert.equal(more.hidden, false);
assert.equal(more.textContent, 'Show the whole email');
assert.equal(content.classes.has('is-collapsed'), true);
more.listeners.click();
assert.equal(content.classes.has('is-collapsed'), false);
assert.equal(more.textContent, 'Show less');
more.listeners.click();
assert.equal(content.classes.has('is-collapsed'), true);
const short = new Element(); short.scrollHeight = 50; const shortContainer = new Element();
installBubbleClamp(short, shortContainer);
assert.equal(shortContainer.children[0].hidden, true);
console.log('PASS long bodies collapse and expand while short messages need no expand control');

state.mobile = true;
state.activeThreadId = 22;
grid.children = [];
renderTimeline({columns, events:[]});
assert.equal(grid.children.filter(c => c.className === 'tl-head').length, 1);
assert.equal(grid.classes.has('mobile-compare'), false);
state.activeThreadId = 'all';
grid.children = [];
renderTimeline({columns, events:[]});
assert.equal(grid.children.filter(c => c.className === 'tl-head').length, 2);
assert.equal(grid.classes.has('mobile-compare'), true);
state.mobile = false;
grid.children = [];
renderTimeline({columns, events:[]});
assert.equal(grid.classes.has('mobile-compare'), false);
assert.equal(grid.children.filter(c => c.className === 'tl-head').length, 2);
console.log('PASS mobile single thread, swipe comparison, and desktop transition');
