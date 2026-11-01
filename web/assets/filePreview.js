/* In-app preview for image/PDF attachments (task 04).
 *
 * Self-contained: attaches its own document-level click listener (event
 * delegation), so app.js/case.js/threadDetailsPane.js only needed one new
 * dataset attribute each on their existing attachment links (mime + size +
 * name) — no wiring calls, no new functions in those already-large files.
 *
 * Exposed as window.ecwOpenPreview({ url, name, mime, size, siblings }),
 * matching the ecwXxx convention every other cross-file module in this
 * folder uses (window.ecwComputeThreadDetails, window.ecwInitPaneResizer,
 * etc.) — never a bare global. Task 05's CAD branch calls this directly to
 * open the same overlay for a type this file doesn't otherwise intercept.
 *
 * CAD attachments (task 05): declared mime is unreliable for STEP/STL/etc —
 * mail clients commonly send application/octet-stream — so those extensions
 * are recognized here by filename instead and routed to a dedicated
 * .file-preview-cad mount point instead of the img/iframe pair. The 3D
 * viewer itself (other_things/cad_viewer/ot-cad-viewer.js, a stripped copy
 * of the Zetisis RFQ tool's viewer, never a reference to it) is lazy-loaded
 * on first use via ensureCadModuleLoaded() below — never on page load.
 *
 * WHY FETCH + BLOB INSTEAD OF A PLAIN <img>/<iframe> src=:
 * The server only trusts its own finfo detection of the bytes (see
 * ecw_inline_preview_allowed() in web/api/index.php) — a supplier's email
 * client can claim any Content-Type it likes. When detection disagrees,
 * the endpoint quietly falls back to today's Content-Disposition: attachment
 * response. An <iframe> pointed straight at that URL would then be a
 * cross-origin-free navigation to an attachment-disposition resource,
 * which browsers do not render in place — behavior for that case (visible
 * blank frame? silent background download?) differs across browsers and
 * neither is a preview. fetch() is never subject to Content-Disposition at
 * all, so this module inspects the real response headers first and only
 * ever hands the browser a blob: URL it has already confirmed is safe to
 * display — the "fake .pdf that's really HTML" case simply surfaces as the
 * ordinary error state, on every browser, with nothing ever written to disk.
 */
(function () {
  'use strict';

  var ALLOWED_MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  // Keep in sync with OT_CAD_EXTENSIONS in cad_viewer/ot-cad-viewer.js — this
  // list has to be known before that module is loaded, since it's what
  // decides whether to load it at all.
  var CAD_EXTENSIONS = ['step', 'stp', 'iges', 'igs', 'stl'];
  var CAD_MODULE_JS = '/other_things/cad_viewer/ot-cad-viewer.js?v=20260920-mobile';
  var CAD_MODULE_CSS = '/other_things/cad_viewer/ot-cad-viewer.css?v=20260920-mobile';
  var LOAD_TIMEOUT_MS = 20000;
  var DRAG_THRESHOLD_PX = 4;
  var MIN_SCALE = 1;
  var MAX_SCALE = 6;

  function isPreviewableMime(mime) {
    return ALLOWED_MIMES.indexOf(mime) !== -1;
  }

  function cadExtensionOf(name) {
    var m = /\.([a-z0-9]+)$/i.exec(String(name || '').trim());
    var ext = m ? m[1].toLowerCase() : '';
    return CAD_EXTENSIONS.indexOf(ext) !== -1 ? ext : null;
  }

  // Excel, CSV, Word, PowerPoint, text, video, audio, extra image types:
  // drawn in the page by docViewers.js from the fetched bytes.
  function docKindOf(item) {
    return item && window.ecwDocViewer ? window.ecwDocViewer.kindOf(item.name, item.mime) : null;
  }

  // Some mail programs label a PDF or a picture "application/octet-stream" (any file): the name
  // says what it is, and the server checks the bytes before showing it here (?inline=1).
  var PREVIEW_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
  function previewableByName(name) {
    var m = /\.([a-z0-9]+)$/i.exec(String(name || '').trim());
    return !!m && PREVIEW_EXTENSIONS.indexOf(m[1].toLowerCase()) !== -1;
  }
  function isPreviewableItem(item) {
    return !!item && (isPreviewableMime(item.mime) || previewableByName(item.name) || !!cadExtensionOf(item.name) || !!docKindOf(item));
  }
  var DOC_MAX_BYTES = 40 * 1024 * 1024;

  var cadModulePromise = null;
  function ensureCadModuleLoaded() {
    if (window.openCadViewer) return Promise.resolve();
    if (cadModulePromise) return cadModulePromise;
    cadModulePromise = new Promise(function (resolve, reject) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = CAD_MODULE_CSS;
      document.head.appendChild(link);

      var script = document.createElement('script');
      script.src = CAD_MODULE_JS;
      script.onload = function () { resolve(); };
      script.onerror = function (e) { cadModulePromise = null; reject(e); };
      document.head.appendChild(script);
    });
    return cadModulePromise;
  }

  function formatSize(bytes) {
    bytes = Number(bytes) || 0;
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  function inlineUrl(url) {
    return url + (url.indexOf('?') !== -1 ? '&' : '?') + 'inline=1';
  }

  function toArray(nodeList) {
    return Array.prototype.slice.call(nodeList);
  }

  // ------------------------------------------------------------- overlay DOM

  var previewCount = 0, previewZ = 200, activePreview = null;
  function createPreview() {
  const listeners = new AbortController();
  var els = null; // built lazily on first use

  function build() {
    var overlay = document.createElement('div');
    overlay.className = 'file-preview-overlay';
    overlay.hidden = true;

    var panel = document.createElement('div');
    panel.className = 'file-preview';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');

    var header = document.createElement('div');
    header.className = 'file-preview-header';

    var name = document.createElement('span');
    name.className = 'file-preview-name';

    var size = document.createElement('span');
    size.className = 'file-preview-size';

    var downloadBtn = document.createElement('button');
    downloadBtn.type = 'button';
    downloadBtn.textContent = 'Download';

    var openBtn = document.createElement('button');
    openBtn.type = 'button';
    openBtn.textContent = 'Open in new tab';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'file-preview-close';
    closeBtn.textContent = '×';
    closeBtn.setAttribute('aria-label', 'Close preview');

    header.append(name, size, downloadBtn, openBtn, closeBtn);

    var body = document.createElement('div');
    body.className = 'file-preview-body';

    var spinner = document.createElement('div');
    spinner.className = 'file-preview-spinner';
    spinner.hidden = true;

    var img = document.createElement('img');
    img.hidden = true;
    img.alt = '';
    img.draggable = false;

    var frame = document.createElement('iframe');
    frame.hidden = true;
    frame.setAttribute('title', 'File preview');

    var cad = document.createElement('div');
    cad.className = 'file-preview-cad';
    cad.hidden = true;

    var doc = document.createElement('div');
    doc.className = 'file-preview-doc';
    doc.hidden = true;

    var error = document.createElement('div');
    error.className = 'file-preview-error';
    error.hidden = true;
    var errorText = document.createElement('p');
    errorText.textContent = 'This file could not be previewed.';
    var errorDownload = document.createElement('button');
    errorDownload.type = 'button';
    errorDownload.textContent = 'Download instead';
    error.append(errorText, errorDownload);

    var prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'file-preview-nav prev';
    prevBtn.textContent = '‹';
    prevBtn.setAttribute('aria-label', 'Previous file');

    var nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'file-preview-nav next';
    nextBtn.textContent = '›';
    nextBtn.setAttribute('aria-label', 'Next file');

    body.append(spinner, img, frame, cad, doc, error, prevBtn, nextBtn);
    panel.append(header, body);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    const offset = (previewCount++ % 6) * 28;
    overlay.style.left = (40 + offset) + 'px'; overlay.style.top = (70 + offset) + 'px';
    const activate = () => { activePreview = overlay; overlay.style.zIndex = String(++previewZ); };
    overlay.addEventListener('pointerdown', activate); activate();
    if (window.ecwFloatingWindow) window.ecwFloatingWindow(overlay, header);
    const duplicate = document.createElement('button'); duplicate.type = 'button'; duplicate.textContent = 'New viewer';
    duplicate.addEventListener('click', () => { const item = state.items[state.index]; createPreview().open({...item, siblings:state.items, index:state.index}); });
    header.insertBefore(duplicate, closeBtn);
    const tile = document.createElement('button'); tile.type = 'button'; tile.textContent = 'Tile viewers';
    tile.addEventListener('click', tileViewers);
    header.insertBefore(tile, closeBtn);
    const expand = document.createElement('button'); expand.className = 'file-preview-expand'; expand.type = 'button'; expand.textContent = 'Expand';
    expand.setAttribute('aria-expanded','false');
    let normalBounds = null;
    expand.addEventListener('click', () => {
      if (overlay.dataset.expanded !== 'true') {
        const box = overlay.getBoundingClientRect();
        normalBounds = {left:box.left,top:box.top,width:box.width,height:box.height};
        overlay.dataset.expanded = 'true';
        Object.assign(overlay.style,{left:'12px',top:'12px',width:(window.innerWidth-24)+'px',height:(window.innerHeight-24)+'px'});
        expand.textContent = 'Restore'; expand.setAttribute('aria-expanded','true');
      } else {
        delete overlay.dataset.expanded;
        Object.assign(overlay.style,{left:normalBounds.left+'px',top:normalBounds.top+'px',width:normalBounds.width+'px',height:normalBounds.height+'px'});
        expand.textContent = 'Expand'; expand.setAttribute('aria-expanded','false');
      }
      activate();
    });
    header.insertBefore(expand, closeBtn);
    const more = document.createElement('details'); more.className = 'file-preview-more';
    const summary = document.createElement('summary'); summary.textContent = 'More'; summary.setAttribute('aria-label', 'Viewer actions');
    const actions = document.createElement('div'); actions.className = 'file-preview-actions';
    for (const button of [downloadBtn, openBtn, duplicate, tile, expand]) {
      actions.appendChild(button);

    }
    actions.addEventListener('click', () => { more.open = false; });
    more.append(summary, actions); header.insertBefore(more, closeBtn);

    els = {
      overlay: overlay, panel: panel, name: name, size: size,
      downloadBtn: downloadBtn, openBtn: openBtn, closeBtn: closeBtn,
      body: body, spinner: spinner, img: img, frame: frame, cad: cad, doc: doc,
      error: error, errorDownload: errorDownload, prevBtn: prevBtn, nextBtn: nextBtn,
    };

    closeBtn.addEventListener('click', closePreview);
    // The outer element owns the native resize grip. Its pointer events
    // must never be interpreted as clicking a modal backdrop to close.
    downloadBtn.addEventListener('click', downloadCurrent);
    errorDownload.addEventListener('click', downloadCurrent);
    openBtn.addEventListener('click', function () {
      var item = state.items[state.index];
      if (item) window.open(inlineUrl(item.url), '_blank', 'noopener');
    });
    prevBtn.addEventListener('click', function () { go(-1); });
    nextBtn.addEventListener('click', function () { go(1); });

    document.addEventListener('keydown', function (event) {
      if (overlay.hidden || activePreview !== overlay) return;
      if ((document.activeElement !== document.body && !panel.contains(document.activeElement)) || event.target.closest('input,textarea,[contenteditable=true]')) return;
      if (event.key === 'Escape') { closePreview(); return; }
      if (event.key === 'ArrowLeft') { go(-1); return; }
      if (event.key === 'ArrowRight') { go(1); return; }
    }, {signal:listeners.signal});

    wireImageZoomPan(img);
  }

  // --------------------------------------------------------- image zoom/pan

  var imgView = { scale: 1, tx: 0, ty: 0 };
  var drag = null;

  function applyImgTransform() {
    els.img.style.transform = 'translate(' + imgView.tx + 'px, ' + imgView.ty + 'px) scale(' + imgView.scale + ')';
    els.img.classList.toggle('zoomed', imgView.scale > 1);
  }

  function resetImgView() {
    imgView = { scale: 1, tx: 0, ty: 0 };
    applyImgTransform();
  }

  function wireImageZoomPan(img) {
    img.addEventListener('click', function () {
      if (drag && drag.moved) return; // was a drag, not a click
      imgView.scale = imgView.scale > 1 ? 1 : 2;
      imgView.tx = 0; imgView.ty = 0;
      applyImgTransform();
    });

    img.addEventListener('wheel', function (event) {
      event.preventDefault();
      var next = imgView.scale + (event.deltaY < 0 ? 0.25 : -0.25);
      imgView.scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, next));
      if (imgView.scale === 1) { imgView.tx = 0; imgView.ty = 0; }
      applyImgTransform();
    }, { passive: false });

    img.addEventListener('mousedown', function (event) {
      if (imgView.scale <= 1) return;
      event.preventDefault();
      drag = { x: event.clientX, y: event.clientY, tx: imgView.tx, ty: imgView.ty, moved: false };
    });
    window.addEventListener('mousemove', function (event) {
      if (!drag) return;
      var dx = event.clientX - drag.x;
      var dy = event.clientY - drag.y;
      if (Math.abs(dx) > DRAG_THRESHOLD_PX || Math.abs(dy) > DRAG_THRESHOLD_PX) drag.moved = true;
      if (!drag.moved) return;
      imgView.tx = drag.tx + dx;
      imgView.ty = drag.ty + dy;
      applyImgTransform();
    }, {signal:listeners.signal});
    window.addEventListener('mouseup', function () {
      // Cleared on the next tick so the click handler above can still see moved=true.
      if (drag) setTimeout(function () { drag = null; }, 0);
    }, {signal:listeners.signal});
  }

  // ------------------------------------------------------------------ state

  var state = { items: [], index: 0, returnFocus: null, objectUrl: null, loadToken: 0, cadAbort: null };

  function destroyDoc() {
    if (!els || !els.doc) return;
    if (typeof els.doc._dvCleanup === 'function') els.doc._dvCleanup();
    els.doc._dvCleanup = null;
    els.doc.textContent = '';
    els.doc.hidden = true;
  }

  function destroyCad() {
    destroyDoc();
    if (state.cadAbort) { state.cadAbort.abort(); state.cadAbort = null; }
    if (els.cad) {
      var viewer = els.cad._otCadViewer;
      if (viewer && typeof viewer.destroy === 'function') viewer.destroy();
      els.cad._otCadViewer = null;
      els.cad.innerHTML = '';
      els.cad.hidden = true;
    }
  }

  function ecwOpenPreview(opts) {
    opts = opts || {};
    if (!els) build();

    var items = (Array.isArray(opts.siblings) && opts.siblings.length)
      ? opts.siblings
      : [{ url: opts.url, name: opts.name, mime: opts.mime, size: opts.size }];

    var index = 0;
    if (typeof opts.index === 'number') {
      index = opts.index;
    } else {
      var found = -1;
      for (var i = 0; i < items.length; i++) { if (items[i].url === opts.url) { found = i; break; } }
      index = found === -1 ? 0 : found;
    }

    state.items = items;
    state.index = index;
    state.returnFocus = document.activeElement;

    // Spreadsheets, documents and slides want a bigger window than a photo.
    if (/^(sheet|word|slides|dxf)$/.test(docKindOf(items[index]) || '') && window.innerWidth > 860) {
      els.overlay.style.width = Math.min(1180, window.innerWidth - 80) + 'px';
      els.overlay.style.height = Math.min(820, window.innerHeight - 100) + 'px';
    }
    els.overlay.hidden = false;

    els.closeBtn.focus();

    loadItem(index);
  }

  function closePreview() {
    if (!els || els.overlay.hidden) return;
    els.overlay.hidden = true;
    listeners.abort();
    els.overlay.remove();
    if (activePreview === els.overlay) activePreview = Array.from(document.querySelectorAll('.file-preview-overlay:not([hidden])')).at(-1) || null;
    if (state.objectUrl) { URL.revokeObjectURL(state.objectUrl); state.objectUrl = null; }
    els.img.src = ''; els.img.hidden = true;
    els.frame.src = 'about:blank'; els.frame.hidden = true;
    destroyCad();
    state.loadToken++; // invalidate any in-flight fetch
    if (state.returnFocus && document.contains(state.returnFocus)) state.returnFocus.focus();
    state.returnFocus = null;
  }

  function go(delta) {
    var next = state.index + delta;
    if (next < 0 || next >= state.items.length) return;
    state.index = next;
    loadItem(next);
  }

  function updateNav() {
    var multi = state.items.length > 1;
    els.prevBtn.hidden = !multi || state.index === 0;
    els.nextBtn.hidden = !multi || state.index === state.items.length - 1;
  }

  function showSpinner() {
    els.spinner.hidden = false;
    els.img.hidden = true;
    els.frame.hidden = true;
    els.cad.hidden = true;
    els.doc.hidden = true;
    els.error.hidden = true;
  }

  function showError(message) {
    els.spinner.hidden = true;
    els.img.hidden = true;
    els.frame.hidden = true;
    els.cad.hidden = true;
    els.doc.hidden = true;
    els.error.querySelector('p').textContent = message || 'This file could not be previewed.';
    els.error.hidden = false;
  }

  function showCad() {
    els.spinner.hidden = true;
    els.img.hidden = true;
    els.frame.hidden = true;
    els.error.hidden = true;
    els.cad.hidden = false;
  }

  function loadItem(index) {
    var item = state.items[index];
    var token = ++state.loadToken;

    els.name.textContent = item.name || 'attachment';
    els.size.textContent = formatSize(item.size);
    updateNav();
    resetImgView();
    destroyCad();
    if (state.objectUrl) { URL.revokeObjectURL(state.objectUrl); state.objectUrl = null; }

    var docKind = docKindOf(item);
    // Opening these "in a new tab" would only download them: the viewer is here.
    els.openBtn.hidden = !!docKind && docKind !== 'video' && docKind !== 'audio' && docKind !== 'image';
    els.overlay.dataset.kind = docKind || (cadExtensionOf(item.name) ? 'cad' : 'file');

    if (cadExtensionOf(item.name)) {
      loadCadItem(item, token);
      return;
    }
    if (docKind) {
      loadDocItem(item, docKind, token);
      return;
    }

    showSpinner();

    var timedOut = false;
    var timer = setTimeout(function () {
      timedOut = true;
      if (token === state.loadToken) showError();
    }, LOAD_TIMEOUT_MS);

    fetch(inlineUrl(item.url), { credentials: 'same-origin' })
      .then(function (response) {
        var disposition = (response.headers.get('Content-Disposition') || '').toLowerCase();
        var contentType = (response.headers.get('Content-Type') || '').split(';')[0].trim();
        if (!response.ok || disposition.indexOf('inline') !== 0 || !isPreviewableMime(contentType)) {
          throw new Error('not previewable');
        }
        return response.blob();
      })
      .then(function (blob) {
        clearTimeout(timer);
        if (timedOut || token !== state.loadToken) return; // stale (closed/navigated away)

        var objectUrl = URL.createObjectURL(blob);
        state.objectUrl = objectUrl;

        if (blob.type.indexOf('image/') === 0) {
          els.img.onload = function () { if (token === state.loadToken) { els.spinner.hidden = true; els.img.hidden = false; } };
          els.img.onerror = function () { if (token === state.loadToken) showError(); };
          els.img.src = objectUrl;
        } else {
          // Chromium's built-in PDF viewer does not reliably fire the
          // iframe's load event (confirmed in headless testing — the PDF
          // renders, the outer frame just never reports it) — a short grace
          // timer avoids a spinner stuck forever on an otherwise-successful
          // load. Whichever fires first wins; the flag stops both firing.
          var revealed = false;
          var reveal = function () {
            if (revealed || token !== state.loadToken) return;
            revealed = true;
            els.spinner.hidden = true;
            els.frame.hidden = false;
          };
          els.frame.onload = reveal;
          els.frame.src = objectUrl;
          setTimeout(reveal, 1200);
        }
      })
      .catch(function () {
        clearTimeout(timer);
        if (token === state.loadToken) showError();
      });
  }

  /** Fetch the bytes (a normal download response; fetch ignores Content-Disposition) and draw them here. */
  function loadDocItem(item, kind, token) {
    showSpinner();
    if (item.size && item.size > DOC_MAX_BYTES) { showError('This file is too large to preview here. Download it to open it.'); return; }
    fetch(item.url, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('download failed');
        return response.arrayBuffer();
      })
      .then(function (buffer) {
        if (token !== state.loadToken) return;
        if (kind === 'image') {
          var url = URL.createObjectURL(new Blob([buffer], { type: window.ecwDocViewer.mediaType(item.name) || 'image/png' }));
          state.objectUrl = url;
          els.img.onload = function () { if (token === state.loadToken) { els.spinner.hidden = true; els.img.hidden = false; } };
          els.img.onerror = function () { if (token === state.loadToken) showError(); };
          els.img.src = url;
          return;
        }
        els.doc.hidden = true;
        return window.ecwDocViewer.render(els.doc, buffer, kind, item).then(function () {
          if (token !== state.loadToken) return;
          els.spinner.hidden = true;
          els.doc.hidden = false;
          els.doc.scrollTop = 0;
        });
      })
      .catch(function () {
        if (token !== state.loadToken) return;
        showError(kind === 'slides' || kind === 'word'
          ? 'This file could not be shown here (older .doc/.ppt files and some layouts are not supported). Download it to open it.'
          : 'This file could not be previewed.');
      });
  }

  function loadCadItem(item, token) {
    showCad();
    ensureCadModuleLoaded()
      .then(function () {
        if (token !== state.loadToken) return; // navigated away while the module was loading
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        state.cadAbort = controller;
        return window.openCadViewer({
          url: item.url,
          name: item.name,
          size: item.size,
          container: els.cad,
          signal: controller ? controller.signal : undefined,
        });
      })
      .catch(function () {
        if (token === state.loadToken) showError();
      });
  }

  function downloadCurrent() {
    var item = state.items[state.index];
    if (!item) return;
    var a = document.createElement('a');
    a.href = item.url;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  // ------------------------------------------------------- click delegation

  return { open: ecwOpenPreview };
  }
  function ecwOpenPreview(opts) { createPreview().open(opts); }

  /** Arrange every open viewer side by side, filling the window. */
  function tileViewers() {
    const windows = Array.from(document.querySelectorAll('.file-preview-overlay:not([hidden])'));
    if (!windows.length) return;
    const cols = Math.max(1, Math.min(windows.length, Math.floor(window.innerWidth / 380)));
    const rows = Math.ceil(windows.length / cols), width = (window.innerWidth - 24) / cols, height = (window.innerHeight - 84) / rows;
    windows.forEach((w, i) => {
      if (w.dataset.expanded === 'true') w.querySelector('.file-preview-expand').click();
      w.style.left = (12 + i % cols * width) + 'px'; w.style.top = (70 + Math.floor(i / cols) * height) + 'px';
      w.style.width = (width - 8) + 'px'; w.style.height = (height - 8) + 'px';
    });
  }

  /** "Open all": every previewable attachment of an email in its own viewer, tiled. */
  function ecwOpenAllPreviews(links) {
    var items = links.map(attachmentInfoFromLink).filter(isPreviewableItem);
    items.slice(0, 8).forEach(function (item, i) { createPreview().open({ url: item.url, name: item.name, mime: item.mime, size: item.size, siblings: items, index: i }); });
    tileViewers();
    return items.length;
  }
  window.ecwOpenAllPreviews = ecwOpenAllPreviews;
  window.ecwIsPreviewableLink = function (link) { return isPreviewableItem(attachmentInfoFromLink(link)); };

  function attachmentInfoFromLink(link) {
    var href = link.getAttribute('href') || '';
    var url;
    try { url = new URL(href, window.location.href); } catch (e) { return null; }
    var action = url.searchParams.get('action');
    // An email's file, or a draft's (api draft_file: the draft cards in Mail and on a case).
    var known = action === 'attachment' ? !!url.searchParams.get('id') : action === 'draft_file' && !!url.searchParams.get('draft_id') && !!url.searchParams.get('file');
    if (!known) return null;
    return {
      url: url.pathname + url.search,
      name: link.dataset.name || link.textContent.trim() || 'attachment',
      mime: link.dataset.mime || '',
      size: Number(link.dataset.size) || 0,
    };
  }

  function siblingLinks(link) {
    var messageList = document.getElementById('messageList');
    if (messageList && messageList.contains(link)) {
      return toArray(messageList.querySelectorAll('a.attachment, a.details-file'));
    }
    var cell = link.closest ? link.closest('.tl-cell') : null;
    if (cell && /^[0-9]+$/.test(cell.dataset.threadId || '')) {
      var cells = toArray(document.querySelectorAll('.tl-cell[data-thread-id="' + cell.dataset.threadId + '"]'));
      cells.sort(function (a, b) { return (parseInt(a.style.gridRow, 10) || 0) - (parseInt(b.style.gridRow, 10) || 0); });
      var out = [];
      cells.forEach(function (c) { out = out.concat(toArray(c.querySelectorAll('a.attachment, a.details-file'))); });
      return out;
    }
    // The files of the email being written (app.js attachmentChip): the arrows go through them.
    var composerFiles = link.closest ? link.closest('#composerAttachments') : null;
    if (composerFiles) {
      return toArray(composerFiles.querySelectorAll('a.att-open'));
    }
    var fileList = link.closest ? link.closest('.details-file-list') : null;
    if (fileList) {
      return toArray(fileList.querySelectorAll('a.attachment, a.details-file'));
    }
    return [link];
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    var link = event.target.closest ? event.target.closest('a.attachment, a.details-file') : null;
    if (!link) return;
    var info = attachmentInfoFromLink(link);
    if (!isPreviewableItem(info)) return; // not an attachment link, or not previewable — default download proceeds

    var siblings = siblingLinks(link)
      .map(attachmentInfoFromLink)
      .filter(isPreviewableItem);

    event.preventDefault();
    ecwOpenPreview({ url: info.url, name: info.name, mime: info.mime, size: info.size, siblings: siblings.length ? siblings : [info] });
  });

  window.ecwOpenPreview = ecwOpenPreview;
})();
