/**
 * 2D DXF viewer for the attachment preview (docViewers.js loads it on first use).
 *
 *   window.ecwDxfView(container, text, item) -> Promise
 *
 * Parses with dxf-parser (vendor/dxf-parser, MIT), flattens everything into
 * polylines and texts in drawing coordinates once — blocks (INSERT, nested,
 * with arrays), dimensions, arcs, bulges, ellipses and splines included — and
 * draws them on a canvas: drag to pan, wheel / pinch to zoom at the pointer,
 * Fit, a layer list, dark or light background, and the pointer's drawing
 * coordinates. Text from the file is drawn on the canvas, never into the DOM
 * as HTML.
 */
(function () {
  'use strict';
  const TAU = Math.PI * 2;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };

  // ---------------------------------------------------------------- geometry
  // 2D affine matrix [a, b, c, d, e, f]: x' = a x + c y + e, y' = b x + d y + f
  const I = [1, 0, 0, 1, 0, 0];
  const mul = (m, n) => [m[0] * n[0] + m[2] * n[1], m[1] * n[0] + m[3] * n[1], m[0] * n[2] + m[2] * n[3], m[1] * n[2] + m[3] * n[3], m[0] * n[4] + m[2] * n[5] + m[4], m[1] * n[4] + m[3] * n[5] + m[5]];
  const apply = (m, x, y) => [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];
  const scaleOf = (m) => Math.sqrt(Math.abs(m[0] * m[3] - m[1] * m[2])) || 1;
  const rotOf = (m) => Math.atan2(m[1], m[0]);

  function arcPoints(cx, cy, r, a0, a1, ccw = true) {
    let sweep = ccw ? a1 - a0 : a0 - a1;
    while (sweep <= 0) sweep += TAU;
    if (sweep > TAU) sweep = TAU;
    const n = Math.max(8, Math.ceil(sweep / TAU * 96));
    const out = [];
    for (let i = 0; i <= n; i++) { const t = a0 + (ccw ? 1 : -1) * sweep * i / n; out.push([cx + r * Math.cos(t), cy + r * Math.sin(t)]); }
    return out;
  }
  /** Polyline vertices with bulges -> points. */
  function bulgePath(vs, closed) {
    const out = [];
    const n = vs.length;
    for (let i = 0; i < n; i++) {
      const p = vs[i], q = vs[(i + 1) % n];
      out.push([p.x, p.y]);
      if (!closed && i === n - 1) break;
      const b = p.bulge || 0;
      if (b && q) {
        const theta = 4 * Math.atan(b);
        const dx = q.x - p.x, dy = q.y - p.y, chord = Math.hypot(dx, dy);
        if (chord > 0) {
          const r = chord / (2 * Math.sin(Math.abs(theta) / 2));
          const mx = (p.x + q.x) / 2, my = (p.y + q.y) / 2;
          const h = Math.sqrt(Math.max(0, r * r - chord * chord / 4)) * (Math.abs(theta) > Math.PI ? -1 : 1);
          const sgn = b > 0 ? 1 : -1;
          const cx = mx - sgn * h * dy / chord, cy = my + sgn * h * dx / chord;
          const a0 = Math.atan2(p.y - cy, p.x - cx), a1 = Math.atan2(q.y - cy, q.x - cx);
          const pts = arcPoints(cx, cy, Math.abs(r), a0, a1, b > 0);
          for (let k = 1; k < pts.length - 1; k++) out.push(pts[k]);
        }
      }
    }
    if (closed && n) out.push([vs[0].x, vs[0].y]);
    return out;
  }
  /** Non-rational B-spline by de Boor; falls back to the fit or control points. */
  function splinePoints(e) {
    const cps = e.controlPoints || [], knots = e.knotValues || [], deg = e.degreeOfSplineCurve || 3;
    if (cps.length > deg && knots.length === cps.length + deg + 1) {
      const out = [], lo = knots[deg], hi = knots[knots.length - deg - 1], steps = Math.min(400, cps.length * 12);
      for (let s = 0; s <= steps; s++) {
        const t = s === steps ? hi - 1e-9 * (hi - lo) : lo + (hi - lo) * s / steps;
        let k = deg; while (k < knots.length - deg - 2 && t >= knots[k + 1]) k++;
        const d = []; for (let j = 0; j <= deg; j++) { const c = cps[k - deg + j]; d.push([c.x, c.y]); }
        for (let r = 1; r <= deg; r++) for (let j = deg; j >= r; j--) {
          const i = k - deg + j, den = knots[i + deg - r + 1] - knots[i], a = den ? (t - knots[i]) / den : 0;
          d[j] = [(1 - a) * d[j - 1][0] + a * d[j][0], (1 - a) * d[j - 1][1] + a * d[j][1]];
        }
        out.push(d[deg]);
      }
      return out;
    }
    return (e.fitPoints && e.fitPoints.length ? e.fitPoints : cps).map((p) => [p.x, p.y]);
  }
  /** MTEXT formatting codes out, paragraphs as line breaks. */
  function plainMText(s) {
    return String(s || '')
      .replace(/\\P/g, '\n').replace(/\\~/g, ' ')
      .replace(/\\[ACcFfHhLlOoQqTtWwKk][^;\\{}]*;?/g, '').replace(/\\S([^;]*);/g, (m, a) => a.replace(/[#^]/, '/'))
      .replace(/[{}]/g, '').replace(/\\\\/g, '\\');
  }
  const ACI7 = 16777215;

  // ---------------------------------------------------------------- flatten
  function flatten(dxf) {
    const layers = (dxf.tables && dxf.tables.layer && dxf.tables.layer.layers) || {};
    const blocks = dxf.blocks || {};
    const prims = [];
    const layerNames = new Set();
    function colorOf(e, inherited) {
      if (e.colorIndex === 0 && inherited !== undefined) return inherited;            // BYBLOCK
      if (e.color !== undefined && e.colorIndex !== 256) return e.color;
      const layer = layers[e.layer];
      return layer && layer.color !== undefined ? layer.color : (inherited !== undefined ? inherited : ACI7);
    }
    function path(pts, m, color, layer, closed, fill) {
      if (pts.length < 2 && !fill) return;
      prims.push({ k: 'p', pts: pts.map(([x, y]) => apply(m, x, y)), color, layer, closed, fill });
    }
    function walk(entities, m, depth, blockColor, blockLayer) {
      for (const e of entities || []) {
        if (e.visible === false || e.inPaperSpace) continue;
        const layer = (e.layer === '0' && blockLayer) ? blockLayer : (e.layer || '0');
        if (layers[layer] && (layers[layer].frozen || layers[layer].visible === false)) continue;
        layerNames.add(layer);
        const color = colorOf(e, blockColor);
        // Arcs and circles drawn from the "back" (extrusion -Z) are mirrored in X.
        const mirror = e.extrusionDirectionZ === -1 || (e.extrusionDirection && e.extrusionDirection.z === -1);
        const mm = mirror ? mul(m, [-1, 0, 0, 1, 0, 0]) : m;
        switch (e.type) {
          case 'LINE': if (e.vertices.length >= 2) path(e.vertices.map((v) => [v.x, v.y]), m, color, layer); break;
          case 'LWPOLYLINE': case 'POLYLINE':
            if (e.vertices && e.vertices.length) path(bulgePath(e.vertices.filter((v) => v.x !== undefined), !!e.shape), mm, color, layer, !!e.shape);
            break;
          case 'CIRCLE': path(arcPoints(e.center.x, e.center.y, e.radius, 0, TAU), mm, color, layer, true); break;
          case 'ARC': path(arcPoints(e.center.x, e.center.y, e.radius, e.startAngle || 0, e.endAngle || TAU), mm, color, layer); break;
          case 'ELLIPSE': {
            const c = e.center, M = e.majorAxisEndPoint, r = e.axisRatio || 1;
            let a0 = e.startAngle || 0, a1 = e.endAngle === undefined ? TAU : e.endAngle;
            if (a1 <= a0) a1 += TAU;
            const n = Math.max(16, Math.ceil((a1 - a0) / TAU * 96)), pts = [];
            for (let i = 0; i <= n; i++) { const t = a0 + (a1 - a0) * i / n; pts.push([c.x + Math.cos(t) * M.x - Math.sin(t) * r * M.y, c.y + Math.cos(t) * M.y + Math.sin(t) * r * M.x]); }
            path(pts, mm, color, layer);
            break;
          }
          case 'SPLINE': path(splinePoints(e), m, color, layer, !!e.closed); break;
          case 'SOLID': {
            const p = e.points.filter(Boolean);
            if (p.length >= 3) path([p[0], p[1], p[3] || p[2], p[2]].filter(Boolean).map((q) => [q.x, q.y]), m, color, layer, true, true);
            break;
          }
          case '3DFACE': if (e.vertices.length >= 3) path(e.vertices.map((v) => [v.x, v.y]), m, color, layer, true); break;
          case 'POINT': {
            const [x, y] = apply(m, e.position.x, e.position.y);
            prims.push({ k: 'x', x, y, color, layer });
            break;
          }
          case 'TEXT': case 'ATTDEF': {
            if (e.type === 'ATTDEF' && (e.invisible || depth === 0)) break;
            const anchor = (e.halign || e.valign) && e.endPoint ? e.endPoint : e.startPoint;
            if (!anchor || !e.text) break;
            const [x, y] = apply(m, anchor.x, anchor.y);
            prims.push({ k: 't', x, y, h: (e.textHeight || 1) * scaleOf(m), rot: (e.rotation || 0) * Math.PI / 180 + rotOf(m), text: plainMText(e.text), color, layer,
              ha: [0, 1, 2, 1, 1, 0][e.halign || 0] ?? 0, va: e.valign || 0 });
            break;
          }
          case 'MTEXT': {
            if (!e.position || !e.text) break;
            const [x, y] = apply(m, e.position.x, e.position.y);
            const ap = (e.attachmentPoint || 1) - 1;
            const rot = e.directionVector ? Math.atan2(e.directionVector.y, e.directionVector.x) : (e.rotation || 0) * Math.PI / 180;
            prims.push({ k: 't', x, y, h: (e.height || 1) * scaleOf(m), rot: rot + rotOf(m), text: plainMText(e.text), color, layer, ha: ap % 3, va: 3 - Math.floor(ap / 3), mtext: true });
            break;
          }
          case 'INSERT': case 'DIMENSION': {
            const b = blocks[e.type === 'INSERT' ? e.name : e.block];
            if (!b || depth > 8) break;
            if (e.type === 'DIMENSION') { walk(b.entities, m, depth + 1, color, layer); break; }
            const bp = b.position || { x: 0, y: 0 }, pos = e.position || { x: 0, y: 0 };
            const sx = e.xScale ?? 1, sy = e.yScale ?? 1, r = (e.rotation || 0) * Math.PI / 180;
            const cols = Math.max(1, e.columnCount || 1), rows = Math.max(1, e.rowCount || 1);
            for (let ci = 0; ci < Math.min(cols, 50); ci++) for (let ri = 0; ri < Math.min(rows, 50); ri++) {
              const ox = ci * (e.columnSpacing || 0), oy = ri * (e.rowSpacing || 0);
              const local = mul([Math.cos(r), Math.sin(r), -Math.sin(r), Math.cos(r), pos.x + ox * Math.cos(r) - oy * Math.sin(r), pos.y + ox * Math.sin(r) + oy * Math.cos(r)],
                mul([sx, 0, 0, sy, 0, 0], [1, 0, 0, 1, -bp.x, -bp.y]));
              walk(b.entities, mul(mm, local), depth + 1, color, layer);
            }
            break;
          }
          default: break;
        }
      }
    }
    walk(dxf.entities, I, 0, undefined, undefined);
    return { prims, layers: [...layerNames].sort((a, b) => a.localeCompare(b, undefined, { numeric: true })) };
  }

  function bounds(prims) {
    let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    const add = (x, y) => { if (!Number.isFinite(x) || !Number.isFinite(y)) return; if (x < x0) x0 = x; if (y < y0) y0 = y; if (x > x1) x1 = x; if (y > y1) y1 = y; };
    for (const p of prims) {
      if (p.k === 'p') for (const q of p.pts) add(q[0], q[1]);
      else add(p.x, p.y);
    }
    if (!Number.isFinite(x0)) return { x0: -10, y0: -10, x1: 10, y1: 10 };
    if (x1 - x0 < 1e-9) { x0 -= 1; x1 += 1; }
    if (y1 - y0 < 1e-9) { y0 -= 1; y1 += 1; }
    return { x0, y0, x1, y1 };
  }

  // ------------------------------------------------------------------ view
  async function view(container, text, item) {
    if (/^AutoCAD Binary DXF/.test(text.slice(0, 22))) throw new Error('binary DXF is not supported');
    const dxf = new window.DxfParser().parseSync(text);
    if (!dxf) throw new Error('not a DXF file');
    const { prims, layers } = flatten(dxf);
    const box = bounds(prims);
    const hidden = new Set();
    let dark = true;
    try { dark = localStorage.getItem('ecw.dxf.light') !== '1'; } catch (_) {}

    const root = el('div', 'dxf-view');
    const canvas = el('canvas', 'dxf-canvas');
    canvas.setAttribute('aria-label', 'Drawing ' + (item.name || ''));
    canvas.tabIndex = 0;
    const bar = el('div', 'dxf-bar');
    const btn = (icon, label, fn) => { const b = el('button', 'dxf-btn'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); const i = el('i', 'fa ' + icon); i.setAttribute('aria-hidden', 'true'); b.append(i); b.addEventListener('click', fn); return b; };
    const coords = el('span', 'dxf-coords', '');
    const info = el('span', 'dxf-info', prims.length.toLocaleString() + ' objects · ' + layers.length + ' layers');
    const layerBtn = btn('fa-layer-group', 'Layers', () => { layerPanel.hidden = !layerPanel.hidden; });
    bar.append(btn('fa-expand', 'Fit to window', fit), btn('fa-magnifying-glass-plus', 'Zoom in', () => zoomAt(cw() / 2, ch() / 2, 1.4)),
      btn('fa-magnifying-glass-minus', 'Zoom out', () => zoomAt(cw() / 2, ch() / 2, 1 / 1.4)), layerBtn,
      btn('fa-circle-half-stroke', 'Dark / light background', () => { dark = !dark; try { localStorage.setItem('ecw.dxf.light', dark ? '0' : '1'); } catch (_) {} draw(); }),
      info, coords);
    const layerPanel = el('div', 'dxf-layers'); layerPanel.hidden = true;
    const allRow = el('label', 'dxf-layer all');
    const all = el('input'); all.type = 'checkbox'; all.checked = true;
    all.addEventListener('change', () => { layers.forEach((l) => (all.checked ? hidden.delete(l) : hidden.add(l))); layerPanel.querySelectorAll('input[data-layer]').forEach((c) => { c.checked = all.checked; }); draw(); });
    allRow.append(all, el('span', '', 'All layers'));
    layerPanel.append(allRow);
    const layerColor = {};
    prims.forEach((p) => { if (layerColor[p.layer] === undefined) layerColor[p.layer] = p.color; });
    layers.forEach((l) => {
      const row = el('label', 'dxf-layer');
      const c = el('input'); c.type = 'checkbox'; c.checked = true; c.dataset.layer = l;
      c.addEventListener('change', () => { c.checked ? hidden.delete(l) : hidden.add(l); draw(); });
      const sw = el('span', 'dxf-swatch'); sw.style.background = hex(layerColor[l] ?? ACI7);
      row.append(c, sw, el('span', '', l));
      layerPanel.append(row);
    });
    root.append(canvas, bar, layerPanel);
    container.append(root);

    const ctx = canvas.getContext('2d');
    let scale = 1, cx = (box.x0 + box.x1) / 2, cy = (box.y0 + box.y1) / 2, dpr = 1;
    const cw = () => canvas.clientWidth, ch = () => canvas.clientHeight;
    function hex(c) { return '#' + (c >>> 0 & 0xffffff).toString(16).padStart(6, '0'); }
    function ink(c) {
      // White/black (ACI 7) follows the background; very light colours stay visible on white.
      if (c === ACI7 || c === undefined) return dark ? '#f2f4f7' : '#111418';
      if (!dark) { const r = c >> 16 & 255, g = c >> 8 & 255, b = c & 255; if (r * 0.3 + g * 0.59 + b * 0.11 > 215) return '#222'; }
      return hex(c);
    }
    function fit() {
      const w = cw(), h = ch();
      scale = Math.min(w / (box.x1 - box.x0), h / (box.y1 - box.y0)) * 0.92;
      cx = (box.x0 + box.x1) / 2; cy = (box.y0 + box.y1) / 2;
      draw();
    }
    const toScreen = (x, y) => [(x - cx) * scale + cw() / 2, -(y - cy) * scale + ch() / 2];
    const toWorld = (sx, sy) => [(sx - cw() / 2) / scale + cx, -(sy - ch() / 2) / scale + cy];
    function zoomAt(sx, sy, f) {
      const [wx, wy] = toWorld(sx, sy);
      scale = Math.max(1e-9, Math.min(1e9, scale * f));
      cx = wx - (sx - cw() / 2) / scale; cy = wy + (sy - ch() / 2) / scale;
      draw();
    }
    let raf = 0;
    function draw() { if (!raf) raf = requestAnimationFrame(paint); }
    function paint() {
      raf = 0;
      const w = cw(), h = ch();
      if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) { canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr); }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = dark ? '#1d2228' : '#ffffff';
      ctx.fillRect(0, 0, w, h);
      ctx.lineWidth = 1;
      ctx.lineJoin = 'round';
      const [vx0, vy1] = toWorld(0, 0), [vx1, vy0] = toWorld(w, h);
      let lastColor = null;
      for (const p of prims) {
        if (hidden.has(p.layer)) continue;
        const color = ink(p.color);
        if (color !== lastColor) { ctx.strokeStyle = color; ctx.fillStyle = color; lastColor = color; }
        if (p.k === 'p') {
          if (!p.bb) { let a = Infinity, b = Infinity, c = -Infinity, d = -Infinity; for (const q of p.pts) { if (q[0] < a) a = q[0]; if (q[1] < b) b = q[1]; if (q[0] > c) c = q[0]; if (q[1] > d) d = q[1]; } p.bb = [a, b, c, d]; }
          if (p.bb[2] < vx0 || p.bb[0] > vx1 || p.bb[3] < vy0 || p.bb[1] > vy1) continue;   // off screen
          ctx.beginPath();
          let [x, y] = toScreen(p.pts[0][0], p.pts[0][1]);
          ctx.moveTo(x, y);
          for (let i = 1; i < p.pts.length; i++) { [x, y] = toScreen(p.pts[i][0], p.pts[i][1]); ctx.lineTo(x, y); }
          if (p.closed) ctx.closePath();
          if (p.fill) ctx.fill(); else ctx.stroke();
        } else if (p.k === 'x') {
          const [x, y] = toScreen(p.x, p.y);
          ctx.beginPath(); ctx.moveTo(x - 3, y); ctx.lineTo(x + 3, y); ctx.moveTo(x, y - 3); ctx.lineTo(x, y + 3); ctx.stroke();
        } else if (p.k === 't') {
          const px = p.h * scale;
          if (px < 2.5 || px > 4000) continue;
          const [x, y] = toScreen(p.x, p.y);
          if (x < -2000 || y < -2000 || x > w + 2000 || y > h + 2000) continue;
          ctx.save();
          ctx.translate(x, y); ctx.rotate(-p.rot);
          ctx.font = px + 'px "Segoe UI", Arial, sans-serif';
          ctx.textAlign = ['left', 'center', 'right'][p.ha] || 'left';
          ctx.textBaseline = p.mtext ? (['alphabetic', 'bottom', 'middle', 'top'][p.va] || 'top') : (['alphabetic', 'bottom', 'middle', 'top'][p.va] || 'alphabetic');
          const lines = p.text.split('\n');
          const off = p.mtext && p.va !== 3 ? -(lines.length - 1) * px * 1.25 * (p.va === 2 ? 0.5 : 1) : 0;
          lines.forEach((line, i) => ctx.fillText(line, 0, off + i * px * 1.25));
          ctx.restore();
        }
      }
    }

    // pan / zoom
    const pointers = new Map();
    let last = null, pinch = null;
    canvas.addEventListener('pointerdown', (e) => { canvas.setPointerCapture(e.pointerId); pointers.set(e.pointerId, [e.offsetX, e.offsetY]); last = [e.offsetX, e.offsetY]; canvas.classList.add('panning'); });
    canvas.addEventListener('pointermove', (e) => {
      const [wx, wy] = toWorld(e.offsetX, e.offsetY);
      const digits = scale > 100 ? 3 : scale > 1 ? 2 : 1;
      coords.textContent = 'X ' + wx.toFixed(digits) + '   Y ' + wy.toFixed(digits);
      if (!pointers.has(e.pointerId)) return;
      pointers.set(e.pointerId, [e.offsetX, e.offsetY]);
      if (pointers.size === 2) {
        const [a, b] = [...pointers.values()], d = Math.hypot(a[0] - b[0], a[1] - b[1]), mid = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
        if (pinch) zoomAt(mid[0], mid[1], d / pinch);
        pinch = d;
        return;
      }
      if (last) { cx -= (e.offsetX - last[0]) / scale; cy += (e.offsetY - last[1]) / scale; last = [e.offsetX, e.offsetY]; draw(); }
    });
    const up = (e) => { pointers.delete(e.pointerId); if (pointers.size < 2) pinch = null; last = pointers.size ? [...pointers.values()][0] : null; if (!pointers.size) canvas.classList.remove('panning'); };
    canvas.addEventListener('pointerup', up); canvas.addEventListener('pointercancel', up);
    canvas.addEventListener('wheel', (e) => { e.preventDefault(); zoomAt(e.offsetX, e.offsetY, Math.exp(-e.deltaY * 0.0015)); }, { passive: false });
    canvas.addEventListener('dblclick', fit);
    canvas.addEventListener('keydown', (e) => {
      const k = e.key;
      if (k === '+' || k === '=') zoomAt(cw() / 2, ch() / 2, 1.25);
      else if (k === '-') zoomAt(cw() / 2, ch() / 2, 0.8);
      else if (k === 'f' || k === 'F' || k === '0') fit();
      else if (k.startsWith('Arrow')) { const s = 60 / scale; if (k === 'ArrowLeft') cx -= s; if (k === 'ArrowRight') cx += s; if (k === 'ArrowUp') cy += s; if (k === 'ArrowDown') cy -= s; draw(); }
      else return;
      e.preventDefault(); e.stopPropagation();
    });
    const ro = new ResizeObserver(() => { dpr = window.devicePixelRatio || 1; const first = !canvas.dataset.fitted; canvas.dataset.fitted = '1'; first ? fit() : draw(); });
    ro.observe(canvas);
    container._dvCleanup = () => ro.disconnect();
  }

  window.ecwDxfView = view;
})();
