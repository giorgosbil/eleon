/* other_things/cad_viewer/ot-cad-viewer.js
 *
 * View-only CAD viewer for the email case tool (task 05). This is a COPY of
 * the Zetisis RFQ tool's own CAD viewer, not a link to it — the original is
 * untouched and this file must never reference its source location or
 * anything else outside other_things/. Everything RFQ-specific was stripped: no pinned
 * annotations, no dimension autofill into an RFQ item form, no job/item ids,
 * no calls to any Zetisis endpoint. What's left is pure viewing: orbit, pan,
 * zoom (all built into the vendored library's own camera controls), fit to
 * view, a mesh wireframe toggle, a bounding-box overlay, feature edges, a
 * model color picker and a cross-section slicer.
 *
 * Rendering is done by Online3DViewer (O3DV), vendored unmodified at
 * cad_viewer/third_party/o3dv.min.js (MIT). O3DV lazy-loads format-specific
 * importers itself (e.g. occt-import-js for STEP/IGES) from a CDN only when
 * a file of that type is actually opened — see SUMMARY.md for the license
 * list and why that CDN fetch is inherited unchanged rather than vendored.
 *
 * Exposes window.openCadViewer({ url, name, size, container, signal }),
 * wired from filePreview.js for the CAD attachment extensions it lists in
 * OT_CAD_EXTENSIONS below. All globals and CSS class/id names use an
 * ot-cad- prefix so this copy can never collide with the original if both
 * were ever loaded in the same page — the one exception is the vendored
 * library's own `window.OV` global, which is baked into the minified file
 * and can't be renamed without hand-patching vendor code.
 */
(function () {
  'use strict';

  // Matches the original RFQ tool's own viewer gating exactly (its two
  // independent isCadFile() checks both use this same 5-item list) —
  // .obj/.3mf are never routed to the original viewer even though the
  // underlying library could technically parse them, so this copy doesn't
  // claim them either.
  var OT_CAD_EXTENSIONS = ['step', 'stp', 'iges', 'igs', 'stl'];
  var SIZE_GUARD_BYTES = 50 * 1024 * 1024;
  var OV_SCRIPT_SRC = '/other_things/cad_viewer/third_party/o3dv.min.js?v=20260920-lifecycle';

  function otCadExtensionOf(name) {
    var m = /\.([a-z0-9]+)$/i.exec(String(name || '').trim());
    var ext = m ? m[1].toLowerCase() : '';
    return OT_CAD_EXTENSIONS.indexOf(ext) !== -1 ? ext : null;
  }

  function formatMB(bytes) {
    return (Number(bytes) / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // ------------------------------------------------------------ the viewer

  class OtCadViewer {
    constructor(containerEl) {
      this.container = containerEl;
      this.viewer = null;
      this._destroyed = false;

      this._lastBBox = null;
      this._bboxWire = null;
      this._edgeOverlays = [];
      this._edgesOn = true;
      this._bboxOn = false;
      this._meshWireframeOn = false;

      // Cross-section state
      this._clippingEnabled = false;
      this._clippingPlane = null;
      this._clippingAxis = 'x';
      this._clippingPosition = 0.5;

      this._panelExpanded = false;
      this._sourceUrl = '';
      this._sourceName = '';

      this._resizeFrame = 0;
      this._resizeObserver = new ResizeObserver(() => {
        cancelAnimationFrame(this._resizeFrame);
        this._resizeFrame = requestAnimationFrame(() => {
          if (this.container.clientWidth && this.container.clientHeight) this.resize();
        });
      });
      this._resizeObserver.observe(this.container);
      this.initPromise = this.init();
    }

    setSourceInfo(url, name) {
      this._sourceUrl = url || '';
      this._sourceName = name || '';
    }

    async init() {
      if (!window.OV) await this.loadOV();
      await new Promise((r) => setTimeout(r, 50));
      if (this._destroyed) return;

      this.viewer = new window.OV.EmbeddedViewer(this.container, {
        backgroundColor: new window.OV.RGBAColor(248, 250, 252, 255),
        defaultColor: new window.OV.RGBColor(180, 185, 190),
        edgeSettings: new window.OV.EdgeSettings(false, new window.OV.RGBColor(0, 0, 0), 1),
        onImportStart: () => {
          let status = this.container.querySelector('.ot-cad-spinner-box');
          if (status) status.querySelector('.ot-cad-status-text').textContent = 'Importing model…';
        },
        onModelLoaded: () => {
          this._hideStatus();
          if (!this.viewer) return;
          this.onModelLoaded();
        },
        onLoadError: (err) => {
          console.error('OV load error:', err);
          this._showLoadError();
        },
      });

      this.container.style.position = this.container.style.position || 'relative';
      this._addControlPanel();
    }

    // ------------------------------------------------------------- loading

    async loadFile(file) {
      await this.initPromise;
      if (!this.viewer) throw new Error('Viewer not initialized');

      this.clearOverlays();
      this._disableClipping();
      this._loadSuccess = false;
      this._showStatus('Importing model…');
      this.viewer.LoadModelFromFileList([file]);

      // Defensive backstop: only fires if onLoadError somehow didn't.
      const observer = new MutationObserver(() => {
        if (this._loadSuccess) { observer.disconnect(); return; }
        const text = this.container.innerText || '';
        if (text.indexOf('Failed to import') !== -1 || text.indexOf('Failed to load file') !== -1 || text.indexOf('No importable file') !== -1) {
          observer.disconnect();
          this._showLoadError();
        }
      });
      observer.observe(this.container, { childList: true, subtree: true, characterData: true });

      setTimeout(() => {
        observer.disconnect();
        if (this._loadSuccess) return;
        const text = this.container.innerText || '';
        if (text.indexOf('Failed to import') !== -1 || text.indexOf('Failed to load file') !== -1 || text.indexOf('No importable file') !== -1) {
          this._showLoadError();
        }
      }, 8000);
    }

    _showStatus(message) {
      this._hideStatus();
      const box = document.createElement('div');
      box.className = 'ot-cad-spinner-box';
      const spinner = document.createElement('div');
      spinner.className = 'ot-cad-spinner';
      const text = document.createElement('div');
      text.className = 'ot-cad-status-text';
      text.textContent = message;
      box.append(spinner, text);
      const wrap = document.createElement('div');
      wrap.className = 'ot-cad-status';
      wrap.appendChild(box);
      this.container.appendChild(wrap);
    }

    _hideStatus() {
      const el = this.container.querySelector('.ot-cad-status');
      if (el) el.remove();
    }

    _showLoadError() {
      if (this._destroyed) return;
      this._hideStatus();
      const wrap = document.createElement('div');
      wrap.className = 'ot-cad-error';

      const icon = document.createElement('div');
      icon.className = 'ot-cad-error-icon';
      icon.textContent = '⚠️';

      const msg = document.createElement('p');
      msg.textContent = 'Could not load 3D preview. The file may be unsupported or corrupted.';

      wrap.append(icon, msg);

      if (this._sourceUrl) {
        const actions = document.createElement('div');
        actions.className = 'ot-cad-error-actions';
        const dl = document.createElement('a');
        dl.className = 'ot-cad-btn primary';
        dl.href = this._sourceUrl;
        dl.rel = 'noopener';
        dl.textContent = 'Download';
        actions.appendChild(dl);
        wrap.appendChild(actions);
      }

      this.container.innerHTML = '';
      this.container.appendChild(wrap);
    }

    onModelLoaded() {
      this._loadSuccess = true;
      this._refreshViewerAfterLayoutChange();
      setTimeout(() => this._refreshViewerAfterLayoutChange(), 400);

      try {
        if (!this.viewer) return;
        const model = this.viewer.GetModel?.();
        if (!model) return;

        let bbox = null;
        if (typeof model.GetBoundingBox === 'function') {
          const mb = model.GetBoundingBox();
          if (mb?.min && mb?.max && typeof mb.GetSize === 'function') {
            const s = mb.GetSize();
            bbox = { min: mb.min, max: mb.max, size: { x: s.x, y: s.y, z: s.z } };
          }
        }
        if (!bbox) bbox = this._calcManualBBox(model);
        this._lastBBox = bbox;

        this._fitView();
        this._applyShadingMode('smooth');

        if (this._bboxOn && bbox) this._addBBoxWire(bbox.min, bbox.max);
        if (this._edgesOn) this._buildFeatureEdgesOverlay(25);
        if (this._meshWireframeOn) this._setMeshWireframe(true);

        this._updateControlPanel();
      } catch (e) {
        console.error('onModelLoaded failed:', e);
      }
    }

    _applyShadingMode(mode) {
      try {
        const ovViewer = this.viewer?.GetViewer?.();
        const ov = window.OV;
        if (ovViewer?.SetShadingMode && ov?.ShadingType) {
          ovViewer.SetShadingMode(mode === 'unlit' ? ov.ShadingType.Unlit : ov.ShadingType.Smooth);
        }
        if (ovViewer?.SetEdgeSettings && ov?.EdgeSettings && ov?.RGBColor) {
          ovViewer.SetEdgeSettings(new ov.EdgeSettings(false, new ov.RGBColor(0, 0, 0), 1));
        }
        ovViewer?.SetGridVisible?.(false);
        ovViewer?.Render?.();
      } catch (e) {
        console.warn('Failed to apply shading mode:', e);
      }
    }

    async _ensureThreeLoaded() {
      // This vendored O3DV build (0.16.x) doesn't expose window.OV.THREE, so
      // this CDN fallback is not a fallback in practice — it is the only path
      // that ever runs. Matches the version+source the original viewer
      // already loads for the exact same reason (verified empirically,
      // not from any O3DV doc — the original's own "use the bundled THREE
      // instead" comment doesn't hold for this build).
      if (window.OV?.THREE) {
        window.THREE = window.OV.THREE;
        return;
      }
      if (window.THREE) return;
      const src = 'https://unpkg.com/three@0.149.0/build/three.min.js';
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        s.onload = () => resolve();
        s.onerror = (e) => reject(e);
        document.head.appendChild(s);
      });
      if (!window.THREE) throw new Error('THREE did not initialize');
    }

    // ------------------------------------------------------ view overlays

    async _buildFeatureEdgesOverlay(thresholdAngleDeg) {
      try {
        await this._ensureThreeLoaded();
        const scene = this._getThreeScene();
        if (!scene) return;

        this._clearEdgeOverlays();
        const overlays = [];

        scene.traverse((obj) => {
          if (!obj || !obj.isMesh || !obj.geometry || obj.userData?.isOtCadHelper) return;
          try {
            const edgesGeom = new THREE.EdgesGeometry(obj.geometry, thresholdAngleDeg);
            if (!edgesGeom || !edgesGeom.attributes?.position?.count) return;

            const mat = new THREE.LineBasicMaterial({ color: 0x111111, depthTest: true, depthWrite: false });
            const lines = new THREE.LineSegments(edgesGeom, mat);
            lines.name = 'OT_CAD_FEATURE_EDGES';
            lines.userData.isOtCadHelper = true;
            lines.renderOrder = 20;
            lines.matrixAutoUpdate = false;

            obj.updateWorldMatrix(true, false);
            lines.applyMatrix4(obj.matrixWorld);

            scene.add(lines);
            overlays.push(lines);
          } catch (_) { /* skip geometry that can't build edges */ }
        });

        this._edgeOverlays = overlays;
        try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
      } catch (e) {
        console.warn('Feature edges overlay failed:', e);
      }
    }

    _clearEdgeOverlays() {
      const scene = this._getThreeScene();
      if (!scene || !this._edgeOverlays?.length) { this._edgeOverlays = []; return; }
      for (const ln of this._edgeOverlays) {
        try {
          scene.remove(ln);
          ln.geometry?.dispose();
          ln.material?.dispose();
        } catch (_) {}
      }
      this._edgeOverlays = [];
      try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
    }

    clearOverlays() {
      this._removeBBoxWire();
      this._clearEdgeOverlays();
    }

    async _addBBoxWire(min, max) {
      await this._ensureThreeLoaded();
      const scene = this._getThreeScene();
      if (!scene || !window.THREE) return;

      this._removeBBoxWire();

      const w = max.x - min.x, h = max.y - min.y, d = max.z - min.z;
      const cx = (min.x + max.x) / 2, cy = (min.y + max.y) / 2, cz = (min.z + max.z) / 2;

      const geometry = new THREE.BoxGeometry(w, h, d);
      const edges = new THREE.EdgesGeometry(geometry);
      const material = new THREE.LineBasicMaterial({
        color: 0x10b981, depthTest: false, depthWrite: false, transparent: true, opacity: 1,
      });
      const wire = new THREE.LineSegments(edges, material);
      wire.position.set(cx, cy, cz);
      wire.name = 'OT_CAD_BBOX_WIRE';
      wire.userData.isOtCadHelper = true;
      wire.renderOrder = 999;

      scene.add(wire);
      this._bboxWire = wire;
      try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
    }

    _removeBBoxWire() {
      const scene = this._getThreeScene();
      if (scene && this._bboxWire) {
        try {
          scene.remove(this._bboxWire);
          this._bboxWire.geometry?.dispose();
          this._bboxWire.material?.dispose();
        } catch (_) {}
        this._bboxWire = null;
        try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
      }
    }

    _setMeshWireframe(enabled) {
      this._meshWireframeOn = enabled;
      const scene = this._getThreeScene();
      if (!scene || !window.THREE) return;
      scene.traverse((obj) => {
        if (!obj.isMesh || obj.userData?.isOtCadHelper) return;
        const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
        mats.forEach((mat) => { if (mat) { mat.wireframe = enabled; mat.needsUpdate = true; } });
      });
      try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
    }

    _changeModelColor(hexColor) {
      const scene = this._getThreeScene();
      if (!scene || !window.THREE) return;
      const color = new THREE.Color(hexColor);
      scene.traverse((obj) => {
        if (obj.isMesh && !obj.userData?.isOtCadHelper) {
          const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
          mats.forEach((mat) => { if (mat?.color) { mat.color.set(color); mat.needsUpdate = true; } });
        }
      });
      try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
    }

    _getThreeScene() {
      const ovViewer = this.viewer?.GetViewer?.();
      if (!ovViewer) return null;
      if (ovViewer.scene) return ovViewer.scene;
      if (ovViewer.viewer?.scene) return ovViewer.viewer.scene;
      if (ovViewer.renderer?.threeScene) return ovViewer.renderer.threeScene;
      return null;
    }

    _refreshViewerAfterLayoutChange() {
      // Must call the EmbeddedViewer wrapper's own Resize() — it re-measures
      // parentElement.clientWidth/clientHeight before resizing the canvas.
      // GetViewer().Resize() (the inner Viewer, no args) does not re-measure
      // anything and leaves the canvas's actual pixel buffer at 0x0 the
      // moment this container starts out hidden (as it does here, unlike the
      // original's always-visible item-card container) — confirmed by
      // instrumenting the vendored build directly.
      const force = () => {
        try {
          this.viewer?.Resize?.();
          this.viewer?.GetViewer?.()?.Render?.();
        } catch (_) {}
      };
      setTimeout(force, 50);
      setTimeout(force, 200);
      setTimeout(force, 500);
    }

    _fitView() {
      try { this.viewer.GetViewer?.().FitModelToWindow?.(); } catch (_) {}
    }

    resize() {
      try {
        if (!this.viewer) return;
        this.viewer.Resize?.();
        this.viewer.GetViewer?.()?.Render?.();
      } catch (e) {
        console.warn('Resize failed:', e);
      }
    }

    _calcManualBBox(model) {
      const meshes = model.meshes || [];
      if (!meshes.length && typeof model.EnumerateVertices !== 'function') return null;

      let minX = Infinity, minY = Infinity, minZ = Infinity;
      let maxX = -Infinity, maxY = -Infinity, maxZ = -Infinity;
      let count = 0;

      const bump = (x, y, z) => {
        if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(z)) return;
        if (x < minX) minX = x; if (y < minY) minY = y; if (z < minZ) minZ = z;
        if (x > maxX) maxX = x; if (y > maxY) maxY = y; if (z > maxZ) maxZ = z;
        count++;
      };

      if (typeof model.EnumerateVertices === 'function') {
        model.EnumerateVertices(v => bump(v.x, v.y, v.z));
      } else for (const mesh of meshes) {
        const verts = mesh?.vertices;
        if (!verts) continue;
        if (Array.isArray(verts) && typeof verts[0] === 'object') {
          for (const v of verts) bump(v.x, v.y, v.z);
        } else if (typeof verts.length === 'number' && verts.length >= 3) {
          for (let i = 0; i + 2 < verts.length; i += 3) bump(verts[i], verts[i + 1], verts[i + 2]);
        }
      }

      if (!count || !Number.isFinite(minX) || !Number.isFinite(maxX)) return null;
      return { min: { x: minX, y: minY, z: minZ }, max: { x: maxX, y: maxY, z: maxZ }, size: { x: maxX - minX, y: maxY - minY, z: maxZ - minZ } };
    }

    async loadOV() {
      if (window.__OT_CAD_OV_LOADING__) return window.__OT_CAD_OV_LOADING__;
      window.__OT_CAD_OV_LOADING__ = new Promise((resolve, reject) => {
        if (!document.querySelector('script[data-ot-cad-lib="1"]')) {
          const s = document.createElement('script');
          s.src = OV_SCRIPT_SRC;
          s.dataset.otCadLib = '1';
          s.onload = () => { window.__OT_CAD_OV_LOADING__ = null; resolve(); };
          s.onerror = (e) => { window.__OT_CAD_OV_LOADING__ = null; reject(e); };
          document.head.appendChild(s);
        } else {
          window.__OT_CAD_OV_LOADING__ = null;
          resolve();
        }
      });
      return window.__OT_CAD_OV_LOADING__;
    }

    clear() {
      this.clearOverlays();
      this._disableClipping();
      try { this.viewer?.LoadModelFromFileList?.([]); } catch (_) {}
    }

    destroy() {
      this._destroyed = true;
      if (this.viewer) this.viewer._ecwDisposed = true;
      this._resizeObserver.disconnect();
      cancelAnimationFrame(this._resizeFrame);
      this.clearOverlays();
      this._disableClipping();
      const panel = this.container.querySelector('.ot-cad-control-wrapper');
      if (panel) panel.remove();
      this._hideStatus();
      // O3DV's own Destroy() disposes geometries/materials but never calls
      // the underlying THREE.WebGLRenderer's forceContextLoss() — without
      // it the GL context isn't handed back to the browser immediately, and
      // opening/closing several models in one page eventually starves the
      // context pool (observed as steadily slower loads, then a silent
      // software-rendering fallback). Grab the renderer before Destroy()
      // runs, in case it gets nulled out afterward.
      let renderer = null;
      try {
        const ovViewer = this.viewer?.GetViewer?.();
        renderer = ovViewer?.renderer?.threeRenderer || ovViewer?.renderer || null;
      } catch (_) {}
      try { this.viewer?.Destroy?.(); } catch (_) {}
      try { if (renderer && typeof renderer.forceContextLoss === 'function') renderer.forceContextLoss(); } catch (_) {}
      this.viewer = null;
    }

    // ==================== CONTROL PANEL ====================

    _addControlPanel() {
      const wrapper = document.createElement('div');
      wrapper.className = 'ot-cad-control-wrapper';

      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'ot-cad-panel-toggle';
      toggleBtn.innerHTML = '⚙️';
      toggleBtn.setAttribute('aria-label', 'View controls');

      const panel = document.createElement('div');
      panel.className = 'ot-cad-control-panel';
      panel.innerHTML = `
        <div class="ot-cad-panel-head">
          <div class="ot-cad-panel-title">View Controls</div>
          <button type="button" class="ot-cad-panel-close" aria-label="Close">✕</button>
        </div>

        

        <label class="ot-cad-control-item">
          <input type="checkbox" id="otCadWireframeToggle">
          <span>Wireframe</span>
        </label>

        <label class="ot-cad-control-item">
          <input type="checkbox" id="otCadBboxToggle">
          <span>Bounding Box</span>
        </label>

        <label class="ot-cad-control-item">
          <input type="checkbox" id="otCadEdgesToggle" checked>
          <span>Feature Edges</span>
        </label>

        <label class="ot-cad-control-item">
          <span>Model Color</span>
          <input type="color" id="otCadModelColor" value="#b4b9be" class="ot-cad-color-input">
        </label>

        <div class="ot-cad-divider"></div>

        <label class="ot-cad-control-item">
          <input type="checkbox" id="otCadCrossSectionToggle">
          <span>Cross Section</span>
        </label>

        <div id="otCadCrossSectionPanel" class="ot-cad-cross-panel" hidden>
          <div class="ot-cad-cross-label">Slice Axis</div>
          <div class="ot-cad-axis-row">
            <button type="button" class="ot-cad-axis-btn active" data-axis="x">X</button>
            <button type="button" class="ot-cad-axis-btn" data-axis="y">Y</button>
            <button type="button" class="ot-cad-axis-btn" data-axis="z">Z</button>
          </div>
          <input type="range" id="otCadSliceSlider" min="0" max="100" value="50">
          <div class="ot-cad-cross-hint">Drag to slice through model</div>
        </div>
      `;

      wrapper.appendChild(toggleBtn);
      wrapper.appendChild(panel);
      this.container.appendChild(wrapper);
      const axes = document.createElement('div'); axes.className = 'ot-cad-view-axes'; axes.setAttribute('aria-label','View direction');
      for (const axis of ['X','Y','Z']) {
        const button = document.createElement('button'); button.type='button'; button.textContent=axis; button.title='View along '+axis+' axis';
        button.addEventListener('click', () => {
          const view=this.viewer?.GetViewer?.(); if(!view)return;
          const camera=view.GetCamera().Clone(); const c=camera.center;
          const distance=Math.max(1,Math.hypot(camera.eye.x-c.x,camera.eye.y-c.y,camera.eye.z-c.z));
          camera.eye=new window.OV.Coord3D(c.x+(axis==='X'?distance:0),c.y+(axis==='Y'?distance:0),c.z+(axis==='Z'?distance:0));
          camera.up=new window.OV.Coord3D(0,axis==='Z'?1:0,axis==='Z'?0:1);
          view.SetCamera(camera); view.Render();
        }); axes.appendChild(button);
      }
      this.container.appendChild(axes);
      const dimensions = document.createElement('div');
      dimensions.className = 'ot-cad-dimensions'; dimensions.setAttribute('aria-live', 'polite');
      dimensions.textContent = 'Dimensions: loading…';
      this.container.appendChild(dimensions);

      const closeBtn = panel.querySelector('.ot-cad-panel-close');
      const showPanel = () => {
        panel.classList.add('open');
        toggleBtn.hidden = true;
        this._panelExpanded = true;
        this._refreshViewerAfterLayoutChange();
      };
      const hidePanel = () => {
        panel.classList.remove('open');
        toggleBtn.hidden = false;
        this._panelExpanded = false;
        this._refreshViewerAfterLayoutChange();
      };
      toggleBtn.addEventListener('click', showPanel);
      closeBtn.addEventListener('click', hidePanel);


      panel.querySelector('#otCadWireframeToggle').addEventListener('change', (e) => this._setMeshWireframe(e.target.checked));
      panel.querySelector('#otCadBboxToggle').addEventListener('change', (e) => this._toggleBBox(e.target.checked));
      panel.querySelector('#otCadEdgesToggle').addEventListener('change', (e) => this._toggleEdges(e.target.checked));
      panel.querySelector('#otCadModelColor').addEventListener('input', (e) => this._changeModelColor(e.target.value));

      const crossToggle = panel.querySelector('#otCadCrossSectionToggle');
      const crossPanel = panel.querySelector('#otCadCrossSectionPanel');
      crossToggle.addEventListener('change', () => {
        crossPanel.hidden = !crossToggle.checked;
        if (crossToggle.checked) this._enableClipping(); else this._disableClipping();
      });

      const axisBtns = panel.querySelectorAll('.ot-cad-axis-btn');
      axisBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
          axisBtns.forEach((b) => b.classList.remove('active'));
          btn.classList.add('active');
          this._clippingAxis = btn.dataset.axis;
          this._updateClippingPlane();
        });
      });

      panel.querySelector('#otCadSliceSlider').addEventListener('input', (e) => {
        this._clippingPosition = parseFloat(e.target.value) / 100;
        this._updateClippingPlane();
      });
    }

    _updateControlPanel() {
      const dimensions = this.container.querySelector('.ot-cad-dimensions');
      if (dimensions) {
        const size = this._lastBBox?.size;
        const unit = ({1:'mm',2:'cm',3:'m',4:'in',5:'ft'})[this.viewer?.GetModel?.()?.GetUnit?.()] || 'model units';
        const format = value => value.toLocaleString(undefined, {maximumFractionDigits:3});
        dimensions.textContent = size && [size.x,size.y,size.z].every(Number.isFinite)
          ? `Width (X): ${format(size.x)} · Length (Y): ${format(size.y)} · Height (Z): ${format(size.z)} ${unit}`
          : 'Dimensions unavailable';
      }
      const panel = this.container.querySelector('.ot-cad-control-panel');
      if (!panel) return;
      const wireframeToggle = panel.querySelector('#otCadWireframeToggle');
      const bboxToggle = panel.querySelector('#otCadBboxToggle');
      const edgesToggle = panel.querySelector('#otCadEdgesToggle');
      if (wireframeToggle) wireframeToggle.checked = this._meshWireframeOn;
      if (bboxToggle) bboxToggle.checked = this._bboxOn;
      if (edgesToggle) edgesToggle.checked = this._edgesOn;
    }

    async _toggleBBox(enabled) {
      this._bboxOn = enabled;
      if (enabled && this._lastBBox) await this._addBBoxWire(this._lastBBox.min, this._lastBBox.max);
      else this._removeBBoxWire();
    }

    async _toggleEdges(enabled) {
      this._edgesOn = enabled;
      if (enabled) await this._buildFeatureEdgesOverlay(25);
      else this._clearEdgeOverlays();
    }

    // ==================== CROSS-SECTION / CLIPPING ====================

    async _enableClipping() {
      await this._ensureThreeLoaded();
      const scene = this._getThreeScene();
      if (!scene || !window.THREE) return;

      this._clippingEnabled = true;
      this._clippingPlane = new THREE.Plane(new THREE.Vector3(1, 0, 0), 0);

      scene.traverse((obj) => {
        if (obj.isMesh && obj.material && !obj.userData?.isOtCadHelper) {
          const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
          mats.forEach((mat) => { mat.clippingPlanes = [this._clippingPlane]; mat.clipShadows = true; mat.needsUpdate = true; });
        }
      });

      const ovViewer = this.viewer?.GetViewer?.();
      const renderer = ovViewer?.renderer?.threeRenderer || ovViewer?.renderer;
      if (renderer) renderer.localClippingEnabled = true;

      this._updateClippingPlane();
    }

    _disableClipping() {
      const scene = this._getThreeScene();
      if (!scene) return;
      this._clippingEnabled = false;
      scene.traverse((obj) => {
        if (obj.isMesh && obj.material) {
          const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
          mats.forEach((mat) => { mat.clippingPlanes = []; mat.needsUpdate = true; });
        }
      });
      this._clippingPlane = null;
      try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
    }

    _updateClippingPlane() {
      if (!this._clippingEnabled || !this._clippingPlane || !this._lastBBox) return;
      const { min, max } = this._lastBBox;
      let normal, constant;
      switch (this._clippingAxis) {
        case 'x': normal = new THREE.Vector3(1, 0, 0); constant = -(min.x + (max.x - min.x) * this._clippingPosition); break;
        case 'y': normal = new THREE.Vector3(0, 1, 0); constant = -(min.y + (max.y - min.y) * this._clippingPosition); break;
        case 'z': normal = new THREE.Vector3(0, 0, 1); constant = -(min.z + (max.z - min.z) * this._clippingPosition); break;
      }
      this._clippingPlane.normal.copy(normal);
      this._clippingPlane.constant = constant;
      try { this.viewer.GetViewer?.().Render?.(); } catch (_) {}
    }
  }

  window.OtCadViewer = OtCadViewer;

  // -------------------------------------------------------- open/fetch UI

  function clearContainer(container) {
    container.innerHTML = '';
    container.classList.add('ot-cad-container');
  }

  function showSizeConfirm(container, sizeBytes) {
    return new Promise((resolve) => {
      const wrap = document.createElement('div');
      wrap.className = 'ot-cad-confirm';

      const p = document.createElement('p');
      p.textContent = 'This CAD file is ' + formatMB(sizeBytes) + '. Large files can be slow to load.';

      const actions = document.createElement('div');
      actions.className = 'ot-cad-confirm-actions';

      const loadBtn = document.createElement('button');
      loadBtn.type = 'button';
      loadBtn.className = 'ot-cad-btn primary';
      loadBtn.textContent = 'Load Anyway';
      loadBtn.addEventListener('click', () => resolve(true));

      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'ot-cad-btn';
      cancelBtn.textContent = 'Cancel';
      cancelBtn.addEventListener('click', () => resolve(false));

      actions.append(loadBtn, cancelBtn);
      wrap.append(p, actions);
      container.appendChild(wrap);
    });
  }

  function showDownloadOnly(container, url, name) {
    container.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'ot-cad-error';
    const p = document.createElement('p');
    p.textContent = 'Preview skipped.';
    wrap.appendChild(p);
    if (url) {
      const actions = document.createElement('div');
      actions.className = 'ot-cad-error-actions';
      const dl = document.createElement('a');
      dl.className = 'ot-cad-btn primary';
      dl.href = url;
      dl.rel = 'noopener';
      dl.textContent = 'Download';
      actions.appendChild(dl);
      wrap.appendChild(actions);
    }
    container.appendChild(wrap);
  }

  function showFetchError(container, url) {
    container.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'ot-cad-error';
    const icon = document.createElement('div');
    icon.className = 'ot-cad-error-icon';
    icon.textContent = '⚠️';
    const p = document.createElement('p');
    p.textContent = 'Could not download the file.';
    wrap.append(icon, p);
    if (url) {
      const actions = document.createElement('div');
      actions.className = 'ot-cad-error-actions';
      const dl = document.createElement('a');
      dl.className = 'ot-cad-btn primary';
      dl.href = url;
      dl.rel = 'noopener';
      dl.textContent = 'Download';
      actions.appendChild(dl);
      wrap.appendChild(actions);
    }
    container.appendChild(wrap);
  }

  function showProgress(container) {
    const wrap = document.createElement('div');
    wrap.className = 'ot-cad-status';
    const box = document.createElement('div');
    box.className = 'ot-cad-spinner-box';
    const spinner = document.createElement('div');
    spinner.className = 'ot-cad-spinner';
    const text = document.createElement('div');
    text.className = 'ot-cad-status-text';
    text.textContent = 'Downloading…';
    const track = document.createElement('div');
    track.className = 'ot-cad-progress-track';
    const fill = document.createElement('div');
    fill.className = 'ot-cad-progress-fill';
    track.appendChild(fill);
    box.append(spinner, text, track);
    wrap.appendChild(box);
    container.appendChild(wrap);
    return { el: wrap, fill: fill, text: text };
  }

  async function fetchWithProgress(url, progress, signal) {
    const response = await fetch(url, { credentials: 'same-origin', signal: signal });
    if (!response.ok) throw new Error('HTTP ' + response.status);

    const total = Number(response.headers.get('Content-Length')) || 0;
    if (!response.body || !total) return response.blob();

    const reader = response.body.getReader();
    const chunks = [];
    let received = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(value);
      received += value.length;
      const pct = Math.min(100, Math.round((received / total) * 100));
      progress.fill.style.width = pct + '%';
      progress.text.textContent = 'Downloading… ' + pct + '%';
    }
    return new Blob(chunks);
  }

  /**
   * openCadViewer({ url, name, size, container, signal }) -> Promise<OtCadViewer|null>
   * size and signal are optional; size (bytes) enables the >50MB confirm,
   * signal (an AbortController.signal) lets the caller cancel an in-flight
   * fetch when the preview is closed or navigated away from.
   */
  async function openCadViewer(opts) {
    opts = opts || {};
    const container = opts.container;
    const url = opts.url;
    const name = opts.name || 'model';
    const size = Number(opts.size) || 0;

    clearContainer(container);

    if (size > SIZE_GUARD_BYTES) {
      const proceed = await showSizeConfirm(container, size);
      if (!proceed) { showDownloadOnly(container, url, name); return null; }
      clearContainer(container);
    }

    const progress = showProgress(container);
    let blob;
    try {
      blob = await fetchWithProgress(url, progress, opts.signal);
    } catch (e) {
      if (e && e.name === 'AbortError') return null;
      showFetchError(container, url);
      return null;
    }

    clearContainer(container);
    const file = new File([blob], name, { type: blob.type || 'application/octet-stream' });

    const viewer = new OtCadViewer(container);
    viewer.setSourceInfo(url, name);
    container._otCadViewer = viewer;
    await viewer.loadFile(file);
    return viewer;
  }

  window.openCadViewer = openCadViewer;
  window.otCadExtensionOf = otCadExtensionOf;
  window.OT_CAD_EXTENSIONS = OT_CAD_EXTENSIONS;
})();
