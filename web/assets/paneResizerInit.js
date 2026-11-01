/* Wires paneResizer.js to navPane and listPane on the mail page. Kept separate
 * from app.js so it only needs the static pane/button markup, not app.js's
 * boot sequence. */
(function () {
  if (!window.ecwInitPaneResizer) return;
  const $ = (id) => document.getElementById(id);

  // Folders and Messages collapse to a thin icon rail (RAIL_W px) rather than
  // disappearing behind a floating tab. Keep RAIL_W in sync with app.php's
  // pre-paint script.
  const RAIL_W = 52;
  // The reading pane keeps at least READ_MIN px; the side panes give way first.
  const READ_MIN = 440;
  const nav = window.ecwInitPaneResizer({
    label:'Folders', pane: $('navPane'), handleSide: 'right', min: 180, max: 420, defaultWidth: 220,
    cssVar: '--ot-nav-w', storageKey: 'ot.pane.nav', collapseButton: $('navCollapseBtn'),
    rail: true, collapsedWidth: RAIL_W, reserve: READ_MIN,
  });
  const list = window.ecwInitPaneResizer({
    label:'Messages', pane: $('listPane'), handleSide: 'right', min: 260, max: 900, defaultWidth: 380,
    cssVar: '--ot-list-w', storageKey: 'ot.pane.list', collapseButton: $('listCollapseBtn'),
    edgeOffset: 'var(--ot-nav-w)', rail: true, collapsedWidth: RAIL_W, reserve: READ_MIN,
  });
  $('listExpandBtn').addEventListener('click', list.expand);
  // The rail's vertical label follows whatever the list header says.
  const title = $('listTitle'), railTitle = $('listRailTitle');
  const mirror = () => { railTitle.textContent = title.textContent; };
  new MutationObserver(mirror).observe(title, { childList: true, characterData: true, subtree: true });
  mirror();
  // Exposed on window: app.js calls setVisible(true) the first time a thread
  // actually opens (the details pane, unlike nav/list, does not exist until
  // then — see threadDetailsPane.js / app.js's renderThread()).
  // Details collapses to a thin rail too (DETAILS_RAIL_W px; keep in sync with app.php's pre-paint script).
  const DETAILS_RAIL_W = 40;
  window.ecwDetailsPaneResizer = window.ecwInitPaneResizer({
    label:'Details', pane: $('detailsPane'), handleSide: 'left', min: 260, max: 480, defaultWidth: 320,
    cssVar: '--ot-details-w', storageKey: 'ot.pane.details', collapseButton: $('detailsCollapseBtn'),
    startVisible: false, rail: true, collapsedWidth: DETAILS_RAIL_W,
  });
  $('detailsExpandBtn')?.addEventListener('click', window.ecwDetailsPaneResizer.expand);

  /*
   * Fit, like Outlook: when the window is too narrow for every open pane plus
   * a readable message, Details shrinks to its icon rail first, then Folders.
   * They come back by themselves when the window is wide enough again. This is
   * never saved as the reader's choice (setAuto), and a pane the reader
   * reopens by hand stays open.
   */
  const details = window.ecwDetailsPaneResizer;
  const panes = $('navPane').parentElement;
  let fitting = false;
  function fit() {
    if (fitting || window.innerWidth < 981) return;
    fitting = true;
    try {
      const room = panes.clientWidth;
      const open = (r) => !r.isCollapsed() || r.isAuto();   // what the reader asked for
      const widthOf = (r, on) => (!r.isVisible() ? 0 : on ? r.minWidth() : r.railWidth());
      let navOn = open(nav), detOn = open(details) && details.isVisible();
      const listW = list.isCollapsed() ? RAIL_W : list.minWidth();
      const need = () => widthOf(nav, navOn) + listW + widthOf(details, detOn) + READ_MIN;
      if (need() > room && detOn && !details.keepsOpen()) detOn = false;
      if (need() > room && navOn && !nav.keepsOpen()) navOn = false;
      if (details.isVisible()) details.setAuto(!detOn && open(details));
      nav.setAuto(!navOn && open(nav));
      [nav, list, details].forEach((r) => r.reclamp());
    } finally { fitting = false; }
  }
  document.addEventListener('ecw:panes-changed', fit);
  window.addEventListener('resize', fit);
  fit();
})();
