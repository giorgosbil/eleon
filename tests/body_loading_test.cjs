const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor() { this.classList = {add(){},remove(){}}; this.dataset = {}; this.children = []; this.isConnected = true; this.listeners = {}; }
  append(...items) { this.children.push(...items); }
  appendChild(item) { this.append(item); return item; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  querySelectorAll() { return []; }
  getBoundingClientRect() { return { top: 0, bottom: 0, height: 0 }; }
  querySelector(selector) {
    if (selector === '.message-body') return this.children.find(c => c.className === 'message-body');
    return null;
  }
  replaceWith(item) { this.replacement = item; this.isConnected = false; }
}
// The reader's pane and list (keeping the reading place when an email's text arrives).
const byId = new Map();
const document = { body: { dataset: {} }, createElement: () => new Element(),
  getElementById: (id) => { if (!byId.has(id)) { const e = new Element(); e.scrollTop = 0; e.scrollHeight = 0; e.getBoundingClientRect = () => ({ top: 0, bottom: 0, height: 0 }); byId.set(id, e); } return byId.get(id); } };
let request;
const ctx = { document, URL, URLSearchParams, window: { addEventListener() {}, location: { href: 'https://example.test/other_things/web/app.php' } },
  fetch: async url => request(new URL(url)), console };
ctx.Node = Element;   // the test's elements are the page's nodes
require('./support/vm_page.cjs').page(ctx);
vm.createContext(ctx);
const source = fs.readFileSync(__dirname + '/../web/assets/app.js', 'utf8');
vm.runInContext(source.replace('  wire();\n  boot();', '  globalThis.reader = { state, renderMessage, loadMessageBody };'), ctx);
const {state, renderMessage, loadMessageBody} = ctx.reader;
const message = { id: 3, direction: 'incoming', from: {email:'a@example.test'}, occurred_at:'2026-09-18 12:00:00',
  to: [], cc: [], attachments: [], body_state: 'not_fetched', body_html: null };
const thread = {id: 2, messages: [message]};
state.thread = thread; state.threadId = 2; state.mailboxId = 1;
(async () => {
  const article = renderMessage(message);
  assert.equal(article.querySelector('.message-body').textContent, 'Loading message');
  const loaded = {...message, body_state: 'fetched', body_html: '<p>Actual content</p>'};
  request = async url => {
    assert.equal(url.searchParams.get('action'), 'body');
    assert.equal(url.searchParams.get('message_id'), '3');
    assert.equal(url.searchParams.get('mailbox_id'), '1');
    return {ok: true, text: async () => JSON.stringify({messages: [loaded]})};
  };
  await loadMessageBody(message, article, thread, 1);
  assert.equal(article.replacement.querySelector('.message-body').innerHTML, loaded.body_html);
  assert.equal(thread.messages[0].body_state, 'fetched');
  console.log('PASS unloaded message fetches content and updates the reader');

  const failed = renderMessage(message);
  request = async () => ({ok:false, status:502, text: async () => '{"error":"unavailable"}'});
  await loadMessageBody(message, failed, thread, 1);
  assert.match(failed.querySelector('.message-body').textContent, /could not be loaded/);
  const retry = failed.querySelector('.message-body').children.at(-1);
  assert.equal(retry.textContent, 'Retry');
  request = async () => ({ok:true, text:async () => JSON.stringify({messages:[loaded]})});
  await retry.listeners.click();
  assert.ok(failed.replacement);
  console.log('PASS provider failures show a working Retry action');

  const stale = renderMessage(message);
  request = async () => { state.threadId = 9; return {ok:true, text:async () => JSON.stringify({messages:[loaded]})}; };
  await loadMessageBody(message, stale, thread, 1);
  assert.equal(stale.replacement, undefined);
  console.log('PASS a late response cannot replace another conversation');

  const empty = renderMessage({...message, body_state:'fetched'});
  assert.equal(empty.querySelector('.message-body').textContent, '(no content)');
  console.log('PASS no-content is reserved for a downloaded empty body');
})().catch(error => { console.error(error); process.exitCode = 1; });
