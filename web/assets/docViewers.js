/**
 * Attachment viewers beyond PDF/images: spreadsheets (Excel, ODS, CSV),
 * Word (.docx), PowerPoint (.pptx), plain text / code, video, audio and extra
 * image formats. Used by filePreview.js, which fetches the bytes and hands
 * them here; nothing is ever served inline from our origin for these types.
 *
 *   window.ecwDocViewer.kindOf(name, mime) -> 'dxf' | 'sheet' | 'word' | 'slides' |
 *       'text' | 'video' | 'audio' | 'image' | null
 *   window.ecwDocViewer.render(container, arrayBuffer, kind, item) -> Promise
 *
 * Libraries (assets/vendor, see PROVENANCE-viewers.md) load on first use only.
 *
 * XSS: attachments are untrusted. Cell, slide and text content is written with
 * textContent. Word HTML from mammoth goes through DOMPurify (no scripts,
 * styles, iframes or remote images) before it is inserted. Media and images are
 * shown from blob: URLs with a type chosen here, never the sender's.
 */
(function () {
  'use strict';

  const base = (window.ecwWebBase || '/other_things/web/') + 'assets/vendor/';
  const LIBS = {
    xlsx: base + 'sheetjs/xlsx.full.min.js',
    mammoth: base + 'mammoth/mammoth.browser.min.js',
    jszip: base + 'jszip/jszip.min.js',
    purify: base + 'dompurify/purify.min.js',
    dxfparser: base + 'dxf-parser/dxf-parser.js',
    dxfview: (window.ecwWebBase || '/other_things/web/') + 'assets/dxfViewer.js',
  };
  const MAX_ROWS = 5000, MAX_COLS = 200, MAX_TEXT = 3 * 1024 * 1024;

  const EXT = {
    dxf: ['dxf'],
    sheet: ['xlsx', 'xlsm', 'xlsb', 'xls', 'ods', 'csv', 'tsv'],
    word: ['docx', 'docm', 'dotx'],
    slides: ['pptx', 'ppsx', 'pptm', 'potx'],
    text: ['txt', 'log', 'json', 'xml', 'md', 'ini', 'cfg', 'conf', 'yaml', 'yml', 'sql', 'html', 'htm', 'css', 'js', 'ts',
           'php', 'py', 'java', 'c', 'cpp', 'h', 'cs', 'sh', 'bat', 'ps1', 'eml', 'vcf', 'ics', 'rtf', 'srt', 'nc', 'gcode'],
    video: ['mp4', 'webm', 'mov', 'm4v', 'ogv'],
    audio: ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus'],
    image: ['bmp', 'svg', 'ico', 'avif', 'apng', 'jfif'],
  };
  const MEDIA_TYPE = {
    mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mov: 'video/mp4', ogv: 'video/ogg',
    mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', oga: 'audio/ogg', m4a: 'audio/mp4', aac: 'audio/aac', flac: 'audio/flac', opus: 'audio/ogg',
    bmp: 'image/bmp', svg: 'image/svg+xml', ico: 'image/x-icon', avif: 'image/avif', apng: 'image/apng', jfif: 'image/jpeg',
  };

  const extOf = (name) => { const m = /\.([a-z0-9]+)$/i.exec(String(name || '').trim()); return m ? m[1].toLowerCase() : ''; };
  function kindOf(name, mime) {
    const ext = extOf(name);
    for (const k of Object.keys(EXT)) if (EXT[k].includes(ext)) return k;
    mime = String(mime || '').toLowerCase();
    if (/spreadsheet|ms-excel|text\/csv/.test(mime)) return 'sheet';
    if (/wordprocessingml/.test(mime)) return 'word';
    if (/presentationml/.test(mime)) return 'slides';
    if (/^text\/plain/.test(mime)) return 'text';
    return null;
  }

  const loaded = {};
  function load(key) {
    if (!loaded[key]) {
      loaded[key] = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = LIBS[key];
        s.onload = resolve;
        s.onerror = () => { loaded[key] = null; reject(new Error('viewer library failed to load')); };
        document.head.appendChild(s);
      });
    }
    return loaded[key];
  }

  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };

  /** UTF-8, with a fallback for Greek / Western Windows encodings (common in CSV exports). */
  function decodeText(buffer) {
    const bytes = new Uint8Array(buffer);
    let text = new TextDecoder('utf-8').decode(bytes);
    const bad = (text.match(/�/g) || []).length;
    if (bad > 3) {
      for (const enc of ['windows-1253', 'windows-1252']) {
        try { const alt = new TextDecoder(enc).decode(bytes); if (!/�/.test(alt)) { text = alt; break; } } catch (_) {}
      }
    }
    return text.replace(/^﻿/, '');
  }

  // ----------------------------------------------------------- spreadsheets
  async function renderSheet(root, buffer, item) {
    await load('xlsx');
    const XLSX = window.XLSX;
    const ext = extOf(item.name);
    const wb = (ext === 'csv' || ext === 'tsv')
      ? XLSX.read(decodeText(buffer), { type: 'string', FS: ext === 'tsv' ? '\t' : undefined })
      : XLSX.read(new Uint8Array(buffer), { type: 'array', cellStyles: true });

    const wrap = el('div', 'dv-sheet');
    const toolbar = el('div', 'dv-sheet-toolbar');
    const find = el('input');
    find.type = 'search';
    find.placeholder = 'Find in sheet…';
    find.setAttribute('aria-label', 'Find in sheet');
    const info = el('span', 'dv-muted');
    toolbar.append(find, info);
    const grid = el('div', 'dv-sheet-grid');
    const tabs = el('div', 'dv-sheet-tabs');
    tabs.setAttribute('role', 'tablist');
    wrap.append(toolbar, grid, tabs);
    root.appendChild(wrap);

    function drawSheet(name) {
      const ws = wb.Sheets[name];
      grid.textContent = '';
      tabs.querySelectorAll('button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.sheet === name)));
      if (!ws || !ws['!ref']) { grid.appendChild(el('p', 'dv-muted dv-pad', 'This sheet is empty.')); info.textContent = ''; return; }
      const range = XLSX.utils.decode_range(ws['!ref']);
      range.s.r = 0; range.s.c = 0;   // like Excel: always from A1
      const lastR = Math.min(range.e.r, range.s.r + MAX_ROWS - 1), lastC = Math.min(range.e.c, range.s.c + MAX_COLS - 1);
      const truncated = lastR < range.e.r || lastC < range.e.c;
      info.textContent = (range.e.r - range.s.r + 1) + ' rows × ' + (range.e.c - range.s.c + 1) + ' columns'
        + (truncated ? ' · showing the first ' + (lastR - range.s.r + 1) + ' × ' + (lastC - range.s.c + 1) : '');

      // Merged cells: the top-left cell spans, the rest are skipped.
      const skip = new Set(), span = new Map();
      (ws['!merges'] || []).forEach((m) => {
        span.set(m.s.r + ':' + m.s.c, { rows: m.e.r - m.s.r + 1, cols: m.e.c - m.s.c + 1 });
        for (let r = m.s.r; r <= m.e.r; r++) for (let c = m.s.c; c <= m.e.c; c++) if (r !== m.s.r || c !== m.s.c) skip.add(r + ':' + c);
      });

      const table = el('table', 'dv-table');
      const colgroup = el('colgroup');
      colgroup.appendChild(el('col', 'dv-rownum-col'));
      const cols = ws['!cols'] || [];
      for (let c = range.s.c; c <= lastC; c++) {
        const col = el('col');
        const w = cols[c] && (cols[c].wpx || (cols[c].wch ? cols[c].wch * 7 + 10 : 0));
        col.style.width = Math.max(48, Math.min(480, w || 90)) + 'px';
        colgroup.appendChild(col);
      }
      table.appendChild(colgroup);
      const thead = el('thead'), hr = el('tr');
      hr.appendChild(el('th', 'dv-corner'));
      for (let c = range.s.c; c <= lastC; c++) hr.appendChild(el('th', '', XLSX.utils.encode_col(c)));
      thead.appendChild(hr);
      table.appendChild(thead);
      const tbody = el('tbody');
      for (let r = range.s.r; r <= lastR; r++) {
        const tr = el('tr');
        tr.appendChild(el('th', 'dv-rownum', String(r + 1)));
        for (let c = range.s.c; c <= lastC; c++) {
          const key = r + ':' + c;
          if (skip.has(key)) continue;
          const cell = ws[XLSX.utils.encode_cell({ r, c })];
          const td = el('td', '', cell ? (cell.w !== undefined ? cell.w : XLSX.utils.format_cell(cell)) : '');
          if (cell && cell.t === 'n') td.className = 'num';
          if (cell && cell.l && cell.l.Target && /^https?:\/\//i.test(cell.l.Target)) td.title = cell.l.Target;
          const s = span.get(key);
          if (s) { if (s.rows > 1) td.rowSpan = s.rows; if (s.cols > 1) td.colSpan = s.cols; }
          tr.appendChild(td);
        }
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      grid.appendChild(table);
      applyFind();
    }

    function applyFind() {
      const q = find.value.trim().toLowerCase();
      let hits = 0;
      grid.querySelectorAll('tbody tr').forEach((tr) => {
        let match = !q;
        tr.querySelectorAll('td').forEach((td) => {
          const on = !!q && td.textContent.toLowerCase().includes(q);
          td.classList.toggle('hit', on);
          if (on) { match = true; hits++; }
        });
        tr.hidden = !match;
      });
      find.title = q ? hits + ' matching cells' : '';
    }
    let t = null;
    find.addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFind, 150); });

    wb.SheetNames.forEach((name, i) => {
      const b = el('button', 'dv-tab', name);
      b.type = 'button';
      b.dataset.sheet = name;
      b.setAttribute('role', 'tab');
      b.addEventListener('click', () => drawSheet(name));
      tabs.appendChild(b);
      if (i === 0) setTimeout(() => drawSheet(name), 0);
    });
    if (wb.SheetNames.length < 2 && extOf(item.name).match(/csv|tsv/)) tabs.hidden = true;
  }

  // -------------------------------------------------------------- Word
  async function renderWord(root, buffer) {
    await Promise.all([load('mammoth'), load('purify')]);
    const result = await window.mammoth.convertToHtml({ arrayBuffer: buffer }, {
      convertImage: window.mammoth.images.imgElement((image) =>
        image.read('base64').then((b64) => ({ src: 'data:' + image.contentType + ';base64,' + b64 }))),
    });
    const purify = window.DOMPurify;
    purify.addHook('afterSanitizeAttributes', (node) => {
      if (node.tagName === 'A') { node.setAttribute('target', '_blank'); node.setAttribute('rel', 'noopener noreferrer'); }
      if (node.tagName === 'IMG' && !/^data:image\//i.test(node.getAttribute('src') || '')) node.removeAttribute('src');
    });
    const clean = purify.sanitize(result.value, { FORBID_TAGS: ['style', 'iframe', 'form', 'input'], FORBID_ATTR: ['style'] });
    purify.removeAllHooks();
    const page = el('div', 'dv-page dv-word');
    page.innerHTML = clean || '<p class="dv-muted">This document has no text.</p>';   // sanitized above
    const scroller = el('div', 'dv-paper');
    scroller.appendChild(page);
    root.appendChild(scroller);
  }

  // ------------------------------------------------------------ PowerPoint
  const NS = {
    p: 'http://schemas.openxmlformats.org/presentationml/2006/main',
    a: 'http://schemas.openxmlformats.org/drawingml/2006/main',
    r: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    rel: 'http://schemas.openxmlformats.org/package/2006/relationships',
  };
  const kids = (node, ns, name) => Array.from(node ? node.children : []).filter((n) => n.namespaceURI === NS[ns] && n.localName === name);
  const kid = (node, ns, name) => kids(node, ns, name)[0] || null;
  const deep = (node, ns, name) => (node ? Array.from(node.getElementsByTagNameNS(NS[ns], name)) : []);

  async function renderSlides(root, buffer) {
    await load('jszip');
    const zip = await window.JSZip.loadAsync(buffer);
    const xml = async (path) => { const f = zip.file(path); return f ? new DOMParser().parseFromString(await f.async('string'), 'application/xml') : null; };
    const relsOf = async (path) => {
      const dir = path.slice(0, path.lastIndexOf('/'));
      const doc = await xml(dir + '/_rels/' + path.slice(path.lastIndexOf('/') + 1) + '.rels');
      const map = {};
      deep(doc, 'rel', 'Relationship').forEach((r) => { map[r.getAttribute('Id')] = { target: resolve(dir, r.getAttribute('Target')), type: r.getAttribute('Type') || '' }; });
      return map;
    };
    function resolve(dir, target) {
      if (!target) return '';
      if (target.startsWith('/')) return target.slice(1);
      const parts = (dir + '/' + target).split('/'), out = [];
      parts.forEach((p) => { if (p === '..') out.pop(); else if (p && p !== '.') out.push(p); });
      return out.join('/');
    }

    const pres = await xml('ppt/presentation.xml');
    if (!pres) throw new Error('not a presentation');
    const presRels = await relsOf('ppt/presentation.xml');
    const size = deep(pres, 'p', 'sldSz')[0];
    const W = Number(size?.getAttribute('cx')) || 12192000, H = Number(size?.getAttribute('cy')) || 6858000;
    const slidePaths = deep(pres, 'p', 'sldId').map((s) => presRels[s.getAttributeNS(NS.r, 'id')]?.target).filter(Boolean);

    const deck = el('div', 'dv-deck');
    const count = el('div', 'dv-muted dv-pad', slidePaths.length + ' slide' + (slidePaths.length === 1 ? '' : 's'));
    deck.appendChild(count);
    root.appendChild(deck);
    const urls = [];
    root._dvCleanup = () => urls.forEach((u) => URL.revokeObjectURL(u));

    // Placeholder positions come from the layout, then the master, when the slide does not set them.
    async function placeholderMap(path) {
      const map = {};
      const doc = await xml(path);
      deep(doc, 'p', 'sp').forEach((sp) => {
        const ph = deep(sp, 'p', 'ph')[0];
        const xfrm = deep(sp, 'a', 'xfrm')[0];
        if (!ph || !xfrm) return;
        const box = boxOf(xfrm);
        const type = ph.getAttribute('type') || 'body', idx = ph.getAttribute('idx');
        if (idx !== null) map['idx:' + idx] = box;
        if (!map['type:' + type]) map['type:' + type] = box;
      });
      return map;
    }
    function boxOf(xfrm) {
      const off = kid(xfrm, 'a', 'off'), ext = kid(xfrm, 'a', 'ext');
      return { x: +off?.getAttribute('x') || 0, y: +off?.getAttribute('y') || 0, w: +ext?.getAttribute('cx') || 0, h: +ext?.getAttribute('cy') || 0 };
    }

    for (let i = 0; i < slidePaths.length; i++) {
      const path = slidePaths[i];
      const doc = await xml(path);
      const rels = await relsOf(path);
      const layoutPath = Object.values(rels).find((r) => /slideLayout$/.test(r.type))?.target;
      let layout = {}, master = {};
      if (layoutPath) {
        layout = await placeholderMap(layoutPath);
        const mPath = Object.values(await relsOf(layoutPath)).find((r) => /slideMaster$/.test(r.type))?.target;
        if (mPath) master = await placeholderMap(mPath);
      }

      const frame = el('section', 'dv-slide');
      frame.style.aspectRatio = W + ' / ' + H;
      frame.setAttribute('aria-label', 'Slide ' + (i + 1));
      const num = el('div', 'dv-slide-num', String(i + 1));
      const inner = el('div', 'dv-slide-inner');
      frame.append(inner, num);
      deck.appendChild(frame);
      let flowY = 0.06 * H;   // shapes without any position are stacked

      const place = (node, box) => {
        if (!box || !box.w) { box = { x: 0.06 * W, y: flowY, w: 0.88 * W, h: 0 }; flowY += 0.12 * H; }
        node.style.left = (box.x / W * 100) + '%';
        node.style.top = (box.y / H * 100) + '%';
        node.style.width = (box.w / W * 100) + '%';
        if (box.h) node.style.height = (box.h / H * 100) + '%';
        inner.appendChild(node);
      };
      const fontSize = (szHundredths) => (szHundredths / 100) / (W / 12700) * 100 + 'cqw';

      async function walk(container, map) {
        for (const node of Array.from(container.children)) {
          if (node.namespaceURI !== NS.p) continue;
          const xfrm = deep(kid(node, 'p', node.localName === 'graphicFrame' ? 'xfrm' : 'spPr') || node, 'a', 'xfrm')[0]
            || kid(node, 'p', 'xfrm');
          let box = xfrm ? map(boxOf(xfrm)) : null;
          if (node.localName === 'grpSp') {
            const g = deep(kid(node, 'p', 'grpSpPr'), 'a', 'xfrm')[0];
            if (g) {
              const off = boxOf(g), chOff = kid(g, 'a', 'chOff'), chExt = kid(g, 'a', 'chExt');
              const cx = +chOff?.getAttribute('x') || 0, cy = +chOff?.getAttribute('y') || 0;
              const cw = +chExt?.getAttribute('cx') || off.w || 1, ch = +chExt?.getAttribute('cy') || off.h || 1;
              await walk(node, (b) => map({ x: off.x + (b.x - cx) * off.w / cw, y: off.y + (b.y - cy) * off.h / ch, w: b.w * off.w / cw, h: b.h * off.h / ch }));
            } else await walk(node, map);
            continue;
          }
          if (node.localName === 'sp') {
            const body = kid(node, 'p', 'txBody');
            const ph = deep(node, 'p', 'ph')[0];
            if (!box && ph) {
              const type = ph.getAttribute('type') || 'body', idx = ph.getAttribute('idx');
              box = (idx !== null && (layout['idx:' + idx] || master['idx:' + idx])) || layout['type:' + type] || master['type:' + type] || null;
            }
            const fill = deep(kid(node, 'p', 'spPr'), 'a', 'srgbClr')[0];
            if (!body) {
              if (fill && box) { const shape = el('div', 'dv-shape'); shape.style.background = '#' + fill.getAttribute('val').replace(/[^0-9a-f]/gi, ''); place(shape, box); }
              continue;
            }
            const text = el('div', 'dv-text');
            const isTitle = ph && /title/i.test(ph.getAttribute('type') || '');
            kids(body, 'a', 'p').forEach((para) => {
              const p = el('p');
              const pPr = kid(para, 'a', 'pPr');
              const algn = pPr?.getAttribute('algn');
              if (algn === 'ctr') p.style.textAlign = 'center'; else if (algn === 'r') p.style.textAlign = 'right';
              if (pPr && +pPr.getAttribute('lvl') > 0) p.style.paddingLeft = (+pPr.getAttribute('lvl') * 4) + '%';
              Array.from(para.children).forEach((run) => {
                if (run.localName === 'br') { p.appendChild(document.createElement('br')); return; }
                if (run.localName !== 'r' && run.localName !== 'fld') return;
                const t = kid(run, 'a', 't');
                if (!t) return;
                const span = el('span', '', t.textContent);
                const rPr = kid(run, 'a', 'rPr');
                if (rPr) {
                  if (rPr.getAttribute('sz')) span.style.fontSize = fontSize(+rPr.getAttribute('sz'));
                  if (rPr.getAttribute('b') === '1') span.style.fontWeight = '700';
                  if (rPr.getAttribute('i') === '1') span.style.fontStyle = 'italic';
                  const c = deep(rPr, 'a', 'srgbClr')[0];
                  if (c) span.style.color = '#' + c.getAttribute('val').replace(/[^0-9a-f]/gi, '');
                }
                p.appendChild(span);
              });
              if (!p.textContent.trim() && !p.querySelector('br')) p.innerHTML = '&nbsp;';
              text.appendChild(p);
            });
            if (!text.textContent.trim()) continue;
            if (isTitle) text.classList.add('dv-title');
            text.style.fontSize = fontSize(isTitle ? 3600 : 1800);
            if (fill) text.style.background = '#' + fill.getAttribute('val').replace(/[^0-9a-f]/gi, '');
            place(text, box);
          } else if (node.localName === 'pic') {
            const blip = deep(node, 'a', 'blip')[0];
            const target = blip && rels[blip.getAttributeNS(NS.r, 'embed')]?.target;
            const file = target && zip.file(target);
            if (!file) continue;
            const ext = extOf(target);
            const type = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', bmp: 'image/bmp', svg: 'image/svg+xml', webp: 'image/webp' }[ext];
            if (!type) continue;   // EMF/WMF etc. cannot be drawn by a browser
            const url = URL.createObjectURL(new Blob([await file.async('arraybuffer')], { type }));
            urls.push(url);
            const img = el('img', 'dv-pic');
            img.alt = '';
            img.src = url;
            place(img, box);
          } else if (node.localName === 'graphicFrame') {
            const tbl = deep(node, 'a', 'tbl')[0];
            if (!tbl) continue;
            const table = el('table', 'dv-slide-table');
            deep(tbl, 'a', 'tr').forEach((row) => {
              const tr = el('tr');
              kids(row, 'a', 'tc').forEach((tc) => { const td = el('td', '', deep(tc, 'a', 't').map((t) => t.textContent).join(' ')); td.style.fontSize = fontSize(1200); tr.appendChild(td); });
              table.appendChild(tr);
            });
            place(table, box);
          }
        }
      }
      const tree = deep(doc, 'p', 'spTree')[0];
      if (tree) await walk(tree, (b) => b);
      if (!inner.children.length) inner.appendChild(el('p', 'dv-muted dv-pad', '(This slide has no text or pictures that can be shown here.)'));
    }
  }

  // ----------------------------------------------------- text, media, images
  function renderText(root, buffer) {
    const text = decodeText(buffer.byteLength > MAX_TEXT ? buffer.slice(0, MAX_TEXT) : buffer);
    const pre = el('pre', 'dv-text-file', text + (buffer.byteLength > MAX_TEXT ? '\n\n… (only the first 3 MB are shown)' : ''));
    root.appendChild(pre);
  }
  function renderMedia(root, buffer, kind, item) {
    const ext = extOf(item.name);
    const type = MEDIA_TYPE[ext] || (kind === 'video' ? 'video/mp4' : 'audio/mpeg');
    const url = URL.createObjectURL(new Blob([buffer], { type }));
    root._dvCleanup = () => URL.revokeObjectURL(url);
    const media = el(kind === 'video' ? 'video' : 'audio', 'dv-media');
    media.controls = true;
    media.preload = 'metadata';
    media.src = url;
    root.appendChild(media);
  }

  async function render(root, buffer, kind, item) {
    root.textContent = '';
    if (kind === 'sheet') return renderSheet(root, buffer, item);
    if (kind === 'word') return renderWord(root, buffer);
    if (kind === 'slides') return renderSlides(root, buffer);
    if (kind === 'text') return renderText(root, buffer);
    if (kind === 'dxf') {
      await Promise.all([load('dxfparser'), load('dxfview')]);
      return window.ecwDxfView(root, decodeText(buffer), item);
    }
    if (kind === 'video' || kind === 'audio') return renderMedia(root, buffer, kind, item);
    throw new Error('no viewer');
  }

  window.ecwDocViewer = { kindOf, render, mediaType: (name) => MEDIA_TYPE[extOf(name)] || null };
})();
