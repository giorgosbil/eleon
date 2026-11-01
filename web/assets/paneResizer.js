/* Drag-to-resize + collapse/expand for a side pane, driven by one CSS custom
 * property on <html> (never per-element inline widths). One instance per
 * pane; handleSide picks which edge the drag handle/expand tab sit on, so
 * the same module works for a pane anchored on the left of its container
 * (handleSide 'right') or on the right (handleSide 'left', for a right-hand
 * panel). */
window.ecwInitPaneResizer = function (opts) {
  const pane = opts.pane;
  const handleSide = opts.handleSide === 'left' ? 'left' : 'right';
  const min = opts.min;
  const max = opts.max;
  const cssVar = opts.cssVar;
  const storageKey = opts.storageKey;
  const collapsedWidth = opts.collapsedWidth || 0;
  const defaultWidth = opts.defaultWidth || min;
  const container = opts.container || pane.parentElement;
  const reserve = opts.reserve || 360;
  const flexSelector = opts.flexSelector || '.pane.reading';
  const collapseButton = opts.collapseButton || null;
  const edgeOffset = opts.edgeOffset || '0px';
  // Below this the side panes are drawers (app.css ≤ 980px), not resizable columns.
  const breakpoint = opts.breakpoint || 981;
  const hasRail = !!opts.rail;

  const openGlyph = handleSide === 'right' ? '‹' : '›';
  const closedGlyph = handleSide === 'right' ? '›' : '‹';

  let width = defaultWidth;
  // What the reader chose. `width` is that, squeezed to fit the window; when
  // the window grows again the pane grows back to `preferred`.
  let preferred = defaultWidth;
  let collapsed = false;
  // Collapsed by the fit logic (paneResizerInit.js) because the window is too
  // narrow — never persisted, and undone when room returns. `keepOpen` is set
  // when the reader expands such a pane by hand: fit then leaves it alone.
  let auto = false;
  let keepOpen = false;
  // Distinct from `collapsed` (a persisted user preference): a pane that is
  // not yet relevant at all — e.g. the details pane before any thread has
  // been opened — starts at width 0 without that ever being written to
  // localStorage as if the reader had collapsed it themselves.
  let visible = opts.startVisible !== false;

  function computeEffectiveMax() {
    const containerWidth = container.getBoundingClientRect().width;
    let others = 0;
    Array.from(container.children).forEach((el) => {
      if (el === pane || el.classList.contains('pane-expand-tab') || el.classList.contains('pane-resize-handle')) return;
      if (flexSelector && el.matches(flexSelector)) return;
      others += el.getBoundingClientRect().width;
    });
    const available = containerWidth - others - reserve;
    return Number.isFinite(available) ? available : max;
  }

  function clamp(px) {
    const upper = Math.min(max, computeEffectiveMax());
    return Math.max(min, Math.min(upper, px));
  }

  function paint() {
    // Not shown yet (details before any thread opens) takes no space at all;
    // collapsed keeps its thin icon rail.
    const px = !visible ? 0 : collapsed ? collapsedWidth : width;
    document.documentElement.style.setProperty(cssVar, px + 'px');
    handle.setAttribute('aria-valuenow', String(px));
  }

  function setWidth(px) {
    width = clamp(px);
    preferred = width;
    if (!collapsed) paint();
  }

  function persist() {
    try {
      localStorage.setItem(storageKey + '.w', String(preferred));
      localStorage.setItem(storageKey + '.c', collapsed ? '1' : '0');
    } catch (_) {}
  }

  function syncCollapsedUI() {
    handle.hidden = collapsed || !visible;
    // A pane with a rail shows a thin icon column when collapsed instead of
    // the floating expand tab (the rail carries its own expand control).
    expandTab.hidden = hasRail || !collapsed || !visible;
    pane.classList.toggle('is-collapsed', hasRail && collapsed && visible);
    if (collapseButton) {
      collapseButton.setAttribute('aria-expanded', String(!collapsed));
      const dir = (collapsed ? closedGlyph : openGlyph) === '‹' ? 'left' : 'right';
      collapseButton.innerHTML = '<i class="fa fa-angles-' + dir + '" aria-hidden="true"></i>';
      const what = (opts.label || 'pane').toLowerCase();
      collapseButton.title = (collapsed ? 'Expand ' : 'Collapse ') + what;
      collapseButton.setAttribute('aria-label', collapseButton.title);
    }
  }

  /** Show/hide the pane without touching the persisted collapsed/width state. */
  function setVisible(nextVisible) {
    visible = nextVisible;
    paint();
    syncCollapsedUI();
    refitOthers();
  }

  function collapse() {
    if (collapsed) return;
    collapsed = true;
    auto = false;
    keepOpen = false;
    paint();
    syncCollapsedUI();
    persist();
    refitOthers();
  }

  function expand() {
    if (!collapsed) return;
    keepOpen = auto;   // reopening an auto-collapsed pane: the reader wants it, despite the space
    collapsed = false;
    auto = false;
    width = clamp(preferred);
    paint();
    syncCollapsedUI();
    persist();
    refitOthers();
  }

  /** Fit logic only: collapse to the rail (true) or restore (false), without persisting. */
  function setAuto(on) {
    if (on && !collapsed && !keepOpen) { collapsed = true; auto = true; }
    else if (!on && auto) { collapsed = false; auto = false; width = clamp(preferred); }
    else return;
    paint();
    syncCollapsedUI();
  }

  function toggleCollapse() {
    collapsed ? expand() : collapse();
  }

  /** Fit the window again, growing back towards the chosen width. */
  function reclamp() {
    if (collapsed || !visible) return;
    width = clamp(preferred);
    paint();
  }

  /** Another pane changed size: the other panes re-fit around it. */
  function refitOthers() {
    (window.__ecwPaneResizers || []).forEach((fn) => { if (fn !== reclamp) fn(); });
    document.dispatchEvent(new CustomEvent('ecw:panes-changed'));
  }

  function beginDragSideEffects() {
    document.body.style.userSelect = 'none';
    document.querySelectorAll('iframe').forEach((f) => { f.style.pointerEvents = 'none'; });
  }

  function endDragSideEffects() {
    document.body.style.userSelect = '';
    document.querySelectorAll('iframe').forEach((f) => { f.style.pointerEvents = ''; });
  }

  const handle = document.createElement('div');
  handle.className = 'pane-resize-handle side-' + handleSide;
  handle.setAttribute('role', 'separator');
  handle.setAttribute('aria-orientation', 'vertical');
  handle.tabIndex = 0;
  container.appendChild(handle);
  if (handleSide === 'right') {
    handle.style.left = 'calc(' + edgeOffset + ' + var(' + cssVar + ') - 5px)'; handle.style.right = 'auto';
  } else {
    handle.style.right = 'calc(var(' + cssVar + ') - 5px)'; handle.style.left = 'auto';
  }
  handle.title = 'Drag to resize · double-click to reset';
  handle.setAttribute('aria-label', 'Resize ' + (opts.label || 'pane'));

  const expandTab = document.createElement('button');
  expandTab.type = 'button';
  expandTab.className = 'pane-expand-tab' + (handleSide === 'left' ? ' side-left' : '');
  expandTab.setAttribute('aria-label', 'Expand');
  expandTab.textContent = closedGlyph + ' ' + (opts.label || 'Expand');
  expandTab.title = 'Expand ' + (opts.label || 'pane');
  expandTab.setAttribute('aria-label', expandTab.title);
  if (opts.tabTop) expandTab.style.top = opts.tabTop;
  expandTab.style[handleSide === 'right' ? 'left' : 'right'] = edgeOffset;
  expandTab.hidden = true;
  expandTab.addEventListener('click', expand);
  container.appendChild(expandTab);

  handle.addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || window.innerWidth < breakpoint) return;
    event.preventDefault();
    handle.setPointerCapture(event.pointerId);
    handle.classList.add('dragging');
    beginDragSideEffects();
    const startX = event.clientX;
    const startWidth = width;
    const move = (e) => {
      const dx = e.clientX - startX;
      setWidth(handleSide === 'right' ? startWidth + dx : startWidth - dx);
    };
    const stop = () => {
      handle.classList.remove('dragging');
      endDragSideEffects();
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', stop);
      handle.removeEventListener('pointercancel', stop);
      persist();
    };
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);
  });

  handle.addEventListener('dblclick', () => { setWidth(defaultWidth); persist(); });

  handle.addEventListener('keydown', (event) => {
    if (window.innerWidth < breakpoint || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    event.preventDefault();
    const sign = event.key === 'ArrowRight' ? 1 : -1;
    setWidth(width + (handleSide === 'right' ? sign : -sign) * 16);
    persist();
  });

  if (collapseButton) collapseButton.addEventListener('click', toggleCollapse);

  (function restore() {
    let storedW = null;
    let storedC = false;
    try {
      const w = localStorage.getItem(storageKey + '.w');
      storedW = w !== null ? Number(w) : null;
      storedC = localStorage.getItem(storageKey + '.c') === '1';
    } catch (_) {}
    preferred = Number.isFinite(storedW) && storedW > 0 ? Math.max(min, Math.min(max, storedW)) : defaultWidth;
    width = clamp(preferred);
    collapsed = storedC;
    paint();
    syncCollapsedUI();
  })();

  window.__ecwPaneResizers = window.__ecwPaneResizers || [];
  window.__ecwPaneResizers.push(reclamp);
  if (!window.__ecwPaneResizerResizeBound) {
    window.__ecwPaneResizerResizeBound = true;
    window.addEventListener('resize', () => { window.__ecwPaneResizers.forEach((fn) => fn()); });
  }

  return {
    collapse, expand, toggleCollapse, isCollapsed: () => collapsed, setVisible, setAuto, reclamp,
    isAuto: () => auto, isVisible: () => visible, keepsOpen: () => keepOpen,
    preferredWidth: () => preferred, railWidth: () => collapsedWidth, minWidth: () => min,
  };
};
