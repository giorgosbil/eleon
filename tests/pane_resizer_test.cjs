const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const src = fs.readFileSync(__dirname + '/../web/assets/paneResizer.js', 'utf8');

function make(className) {
  const el = {
    style: {}, attrs: {}, events: {}, hidden: false, className: className || '',
    setAttribute(k, v) { this.attrs[k] = v; },
    setPointerCapture() {},
    addEventListener(k, v) { this.events[k] = v; },
    removeEventListener(k) { delete this.events[k]; },
    getBoundingClientRect() { return { width: el._w || 0 }; },
    appendChild(x) { (this.children = this.children || []).push(x); },
  };
  el.classList = {
    add: (c) => { el.className = (el.className + ' ' + c).trim(); },
    remove: (c) => { el.className = el.className.split(' ').filter((x) => x && x !== c).join(' '); },
    contains: (c) => el.className.split(' ').includes(c),
    toggle: (c, on) => { const has = el.classList.contains(c); const want = on === undefined ? !has : !!on; if (want && !has) el.classList.add(c); if (!want && has) el.classList.remove(c); return want; },
  };
  el.matches = (sel) => sel.split('.').filter(Boolean).every((c) => el.className.split(' ').includes(c));
  return el;
}

function newCtx() {
  const docEl = { style: { vars: {}, setProperty(k, v) { this.vars[k] = v; } } };
  const iframes = [make(), make()];
  const saved = {};
  const listeners = {};
  const window = {
    innerWidth: 1280,
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    fireResize() { (listeners.resize || []).forEach((fn) => fn()); },
  };
  const document = {
    documentElement: docEl,
    body: { style: {} },
    createElement: (tag) => make(),
    querySelectorAll: (sel) => (sel === 'iframe' ? iframes : []),
  };
  const localStorage = { getItem: (k) => (k in saved ? saved[k] : null), setItem: (k, v) => { saved[k] = String(v); } };
  const ctx = require('./support/vm_page.cjs').page({ window, document, localStorage, Array, Math, Number, String });
  vm.createContext(ctx);
  vm.runInContext(src, ctx);
  return { ctx, window, document, docEl, iframes, saved };
}

function makeContainer(width, siblingWidths) {
  const container = make();
  container._w = width;
  container.children = siblingWidths.map((w) => { const s = make(w.cls || ''); s._w = w.width; return s; });
  container.appendChild = (x) => container.children.push(x);
  return container;
}

// --- basic init: defaults, ARIA wiring, no stored state -------------------
{
  const { ctx, docEl } = newCtx();
  const pane = make('pane nav');
  const listSibling = { width: 380 };
  const readingSibling = { width: 700, cls: 'pane reading' };
  const container = makeContainer(1280, [listSibling, readingSibling]);
  const collapseBtn = make();
  ctx.window.ecwInitPaneResizer({
    pane, handleSide: 'right', min: 180, max: 420, defaultWidth: 220,
    cssVar: '--ot-nav-w', storageKey: 'ot.pane.nav', collapseButton: collapseBtn, container,
  });
  assert.equal(docEl.style.vars['--ot-nav-w'], '220px');
  const handle = container.children.find(el => el.classList.contains('pane-resize-handle'));
  assert.equal(handle.attrs['aria-orientation'], 'vertical');
  assert.equal(collapseBtn.attrs['aria-expanded'], 'true');
  assert.match(collapseBtn.innerHTML, /fa-angles-left/);   // an icon pointing the way the pane folds
  console.log('PASS default width applied, handle ARIA wired, collapse button starts expanded');
}

// --- drag resize: static max clamp, then reserve-driven effective clamp ---
{
  const { ctx, docEl, iframes, document } = newCtx();
  const pane = make('pane nav');
  const listSibling = { width: 380 };
  const readingSibling = { width: 700, cls: 'pane reading' };
  const container = makeContainer(1280, [listSibling, readingSibling]);
  ctx.window.ecwInitPaneResizer({
    pane, handleSide: 'right', min: 180, max: 420, defaultWidth: 220,
    cssVar: '--ot-nav-w', storageKey: 'ot.pane.nav', container,
  });
  const handle = container.children.find(el => el.classList.contains('pane-resize-handle'));
  handle.events.pointerdown({ button: 0, clientX: 300, preventDefault() {}, pointerId: 1 });
  assert.equal(document.body.style.userSelect, 'none');
  assert.equal(iframes[0].style.pointerEvents, 'none');
  handle.events.pointermove({ clientX: 350 });
  assert.equal(docEl.style.vars['--ot-nav-w'], '270px');
  handle.events.pointermove({ clientX: 1000 });
  assert.equal(docEl.style.vars['--ot-nav-w'], '420px'); // static max wins, 1280-380-360=540 available
  handle.events.pointerup();
  assert.equal(document.body.style.userSelect, '');
  assert.equal(iframes[0].style.pointerEvents, '');
  assert.equal(ctx.localStorage.getItem('ot.pane.nav.w'), '420');
  assert.equal(ctx.localStorage.getItem('ot.pane.nav.c'), '0');
  assert.equal(handle.events.pointermove, undefined); // cleaned up after stop

  // Narrower container: reserve (360) + sibling (380) leaves only 180 available.
  container._w = 920;
  handle.events.pointerdown({ button: 0, clientX: 0, preventDefault() {}, pointerId: 2 });
  handle.events.pointermove({ clientX: 500 });
  assert.equal(docEl.style.vars['--ot-nav-w'], '180px'); // min floor, main content stays >= reserve
  handle.events.pointerup();
  console.log('PASS drag resize respects static max and the main-content reserve');
}

// --- collapse/expand via header button and expand tab ---------------------
{
  const { ctx, docEl } = newCtx();
  const pane = make('pane list');
  const container = makeContainer(1280, [{ width: 220 }, { width: 700, cls: 'pane reading' }]);
  const collapseBtn = make();
  const api = ctx.window.ecwInitPaneResizer({
    pane, handleSide: 'right', min: 260, max: 640, defaultWidth: 380,
    cssVar: '--ot-list-w', storageKey: 'ot.pane.list', collapseButton: collapseBtn,
    edgeOffset: 'var(--ot-nav-w)', container,
  });
  const handle = container.children.find(el => el.classList.contains('pane-resize-handle'));
  const tab = container.children.find((c) => c.className.includes('pane-expand-tab'));
  assert.equal(tab.style.left, 'var(--ot-nav-w)');
  assert.equal(tab.hidden, true);

  collapseBtn.events.click();
  assert.equal(api.isCollapsed(), true);
  assert.equal(docEl.style.vars['--ot-list-w'], '0px');
  assert.equal(handle.hidden, true);
  assert.equal(tab.hidden, false);
  assert.equal(collapseBtn.attrs['aria-expanded'], 'false');
  assert.equal(ctx.localStorage.getItem('ot.pane.list.c'), '1');
  assert.equal(ctx.localStorage.getItem('ot.pane.list.w'), '380'); // remembered, not zeroed

  tab.events.click();
  assert.equal(api.isCollapsed(), false);
  assert.equal(docEl.style.vars['--ot-list-w'], '380px');
  assert.equal(handle.hidden, false);
  assert.equal(tab.hidden, true);
  console.log('PASS collapse/expand toggles width, ARIA state and remembers the pre-collapse width');
}

// --- dblclick reset, arrow-key resize, sub-900 breakpoint disables drag ---
{
  const { ctx, docEl } = newCtx();
  const pane = make('pane nav');
  const container = makeContainer(1280, [{ width: 380 }, { width: 700, cls: 'pane reading' }]);
  ctx.window.ecwInitPaneResizer({
    pane, handleSide: 'right', min: 180, max: 420, defaultWidth: 220,
    cssVar: '--ot-nav-w', storageKey: 'ot.pane.nav', container,
  });
  const handle = container.children.find(el => el.classList.contains('pane-resize-handle'));

  handle.events.keydown({ key: 'ArrowRight', preventDefault() {} });
  assert.equal(docEl.style.vars['--ot-nav-w'], '236px');
  handle.events.keydown({ key: 'ArrowLeft', preventDefault() {} });
  handle.events.keydown({ key: 'ArrowLeft', preventDefault() {} });
  assert.equal(docEl.style.vars['--ot-nav-w'], '204px');
  assert.equal(ctx.localStorage.getItem('ot.pane.nav.w'), '204');

  handle.events.dblclick();
  assert.equal(docEl.style.vars['--ot-nav-w'], '220px');

  ctx.window.innerWidth = 800;
  handle.events.pointerdown({ button: 0, clientX: 0, preventDefault() {}, pointerId: 9 });
  assert.equal(handle.events.pointermove, undefined); // drag never armed below the breakpoint
  handle.events.keydown({ key: 'ArrowRight', preventDefault() {} });
  assert.equal(docEl.style.vars['--ot-nav-w'], '220px'); // keyboard resize disabled too
  console.log('PASS dblclick reset, 16px arrow-key steps and the sub-900px drag cutoff');
}

// --- restore from localStorage before a later init (simulated reload) -----
{
  const { ctx, docEl } = newCtx();
  ctx.localStorage.setItem('ot.pane.nav.w', '300');
  ctx.localStorage.setItem('ot.pane.nav.c', '0');
  const pane1 = make('pane nav');
  const container1 = makeContainer(1280, [{ width: 380 }, { width: 700, cls: 'pane reading' }]);
  ctx.window.ecwInitPaneResizer({
    pane: pane1, handleSide: 'right', min: 180, max: 420, defaultWidth: 220,
    cssVar: '--ot-nav-w', storageKey: 'ot.pane.nav', container: container1,
  });
  assert.equal(docEl.style.vars['--ot-nav-w'], '300px');

  ctx.localStorage.setItem('ot.pane.list.w', '400');
  ctx.localStorage.setItem('ot.pane.list.c', '1');
  const pane2 = make('pane list');
  const container2 = makeContainer(1280, [{ width: 300 }, { width: 700, cls: 'pane reading' }]);
  const api2 = ctx.window.ecwInitPaneResizer({
    pane: pane2, handleSide: 'right', min: 260, max: 640, defaultWidth: 380,
    cssVar: '--ot-list-w', storageKey: 'ot.pane.list', container: container2,
  });
  assert.equal(docEl.style.vars['--ot-list-w'], '0px'); // restored collapsed
  assert.equal(api2.isCollapsed(), true);
  api2.expand();
  assert.equal(docEl.style.vars['--ot-list-w'], '400px'); // remembered width survives the reload
  console.log('PASS a later init restores width and collapsed state from localStorage');
}
