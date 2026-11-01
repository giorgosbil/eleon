/**
 * Pictures in the composer (Quill): click one to resize it (drag a corner, or
 * Small / Medium / Full), align it, crop it, move it to another place in the
 * text, or remove it.
 *
 *   window.ecwImageTools(quill, { onChange })
 *
 * The size is the <img width> attribute and the alignment the paragraph's
 * text-align: both survive the compose sanitizer and every mail client. A crop
 * replaces the picture with the cropped copy (a data: image), which is embedded
 * as an inline attachment when sending (lib/compose.php).
 */
(function () {
  'use strict';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const icon = (fa) => { const i = el('i', 'fa ' + fa); i.setAttribute('aria-hidden', 'true'); return i; };

  window.ecwImageTools = function (quill, opts) {
    const changed = () => opts?.onChange?.();
    const root = quill.root;
    const host = quill.container;   // .ql-container: position:relative
    let img = null;

    // ------------------------------------------------------------ the frame
    const frame = el('div', 'imgt-frame'); frame.hidden = true;
    const handles = ['nw', 'ne', 'sw', 'se'].map((c) => { const h = el('span', 'imgt-handle imgt-' + c); h.dataset.corner = c; frame.appendChild(h); return h; });
    const size = el('span', 'imgt-size'); frame.appendChild(size);
    const bar = el('div', 'imgt-bar'); bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', 'Picture');
    const btn = (label, content, fn, cls) => {
      const b = el('button', 'imgt-btn' + (cls ? ' ' + cls : '')); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label);
      typeof content === 'string' ? (b.textContent = content) : b.appendChild(content);
      b.addEventListener('mousedown', (e) => e.preventDefault());   // keep the editor's selection
      b.addEventListener('click', (e) => { e.preventDefault(); fn(e); });
      bar.appendChild(b); return b;
    };
    const sep = () => bar.appendChild(el('span', 'imgt-sep'));
    btn('Small', 'S', () => setWidth(160));
    btn('Medium', 'M', () => setWidth(340));
    btn('Full width', 'L', () => setWidth(maxWidth()));
    btn('Original size', icon('fa-expand'), () => setWidth(Math.min(img.naturalWidth || maxWidth(), maxWidth())));
    sep();
    btn('Align left', icon('fa-align-left'), () => align(false));
    btn('Align centre', icon('fa-align-center'), () => align('center'));
    btn('Align right', icon('fa-align-right'), () => align('right'));
    sep();
    btn('Crop', icon('fa-crop-simple'), () => openCrop());
    const mover = btn('Move: drag me to where the picture should go', icon('fa-up-down-left-right'), () => {}, 'imgt-move');
    sep();
    btn('Remove the picture', icon('fa-trash-can'), () => remove(), 'imgt-danger');
    host.appendChild(frame);
    // The toolbar floats on the page (never cut off by the editor box), kept inside the editor's visible part.
    bar.hidden = true;
    document.body.appendChild(bar);

    const maxWidth = () => Math.max(60, root.clientWidth - 40);
    const indexOf = () => quill.getIndex(window.Quill.find(img));

    function place() {
      if (!img || !img.isConnected) return hide();
      const r = img.getBoundingClientRect(), h = host.getBoundingClientRect();
      Object.assign(frame.style, { left: (r.left - h.left) + 'px', top: (r.top - h.top) + 'px', width: r.width + 'px', height: r.height + 'px' });
      size.textContent = Math.round(r.width) + ' × ' + Math.round(r.height);
      frame.hidden = false;
      // Above the picture when it fits, otherwise at the top of the visible part of the picture.
      const v = root.getBoundingClientRect();
      bar.hidden = false;
      const bw = bar.offsetWidth, bh = bar.offsetHeight;
      const top = r.top - bh - 8 >= v.top ? r.top - bh - 8 : Math.max(v.top, r.top) + 8;
      const left = Math.max(v.left + 4, Math.min(r.left, v.right - bw - 4));
      const visible = r.bottom > v.top && r.top < v.bottom;
      Object.assign(bar.style, { top: Math.round(top) + 'px', left: Math.round(left) + 'px' });
      bar.hidden = !visible;
    }
    function show(target) { img = target; root.querySelectorAll('img.imgt-selected').forEach((i) => i.classList.remove('imgt-selected')); img.classList.add('imgt-selected'); place(); }
    function hide() { frame.hidden = true; bar.hidden = true; img?.classList.remove('imgt-selected'); img = null; }

    function setWidth(w) {
      if (!img) return;
      w = Math.round(Math.max(40, Math.min(w, maxWidth())));
      img.setAttribute('width', String(w));
      img.removeAttribute('height');
      img.style.removeProperty('width'); img.style.removeProperty('height');
      quill.update('user'); changed(); requestAnimationFrame(place);
    }
    function align(value) {
      if (!img) return;
      quill.formatLine(indexOf(), 1, 'align', value, 'user'); changed(); requestAnimationFrame(place);
    }
    function remove() {
      if (!img) return;
      const i = indexOf(); hide(); quill.deleteText(i, 1, 'user'); changed();
    }
    /** Put a picture (src, width) at index, as one undoable change. */
    function insertAt(index, src, width) {
      quill.insertEmbed(index, 'image', src, 'user');
      if (width) quill.formatText(index, 1, { width: String(width) }, 'user');
      quill.setSelection(index + 1, 0, 'silent');
      requestAnimationFrame(() => { const n = quill.getLeaf(index + 1)[0]?.domNode; if (n && n.tagName === 'IMG') show(n); });
    }

    // ------------------------------------------------------- select / deselect
    root.addEventListener('click', (e) => { if (e.target.tagName === 'IMG') { e.preventDefault(); show(e.target); } else if (!frame.contains(e.target) && !bar.contains(e.target)) hide(); });
    document.addEventListener('mousedown', (e) => { if (img && !frame.contains(e.target) && !bar.contains(e.target) && e.target !== img) hide(); }, true);
    // The composer closing or being dragged: the floating toolbar goes too.
    new MutationObserver(() => { if (img && !host.offsetParent) hide(); }).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['hidden', 'class', 'style'] });
    window.addEventListener('scroll', () => { if (img) place(); }, true);
    root.addEventListener('keydown', (e) => { if (!img) return; if (e.key === 'Escape') { hide(); } else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(); } });
    root.addEventListener('scroll', place, { passive: true });
    window.addEventListener('resize', place);
    quill.on('text-change', () => { if (img) requestAnimationFrame(place); });
    // Pictures loading later (pasted ones) move the frame.
    root.addEventListener('load', (e) => { if (e.target === img) place(); }, true);

    // ------------------------------------------------------------ resize
    handles.forEach((h) => h.addEventListener('pointerdown', (e) => {
      if (!img) return;
      e.preventDefault(); h.setPointerCapture(e.pointerId);
      const startX = e.clientX, startW = img.getBoundingClientRect().width, left = h.dataset.corner.includes('w');
      const move = (ev) => {
        const w = Math.max(40, Math.min(maxWidth(), startW + (left ? startX - ev.clientX : ev.clientX - startX)));
        img.setAttribute('width', String(Math.round(w))); img.removeAttribute('height'); place();
      };
      const up = () => { h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); quill.update('user'); changed(); };
      h.addEventListener('pointermove', move); h.addEventListener('pointerup', up);
    }));

    // ------------------------------------------------------------- move
    const caret = el('div', 'imgt-caret'); caret.hidden = true; host.appendChild(caret);
    const rangeAt = (x, y) => document.caretRangeFromPoint ? document.caretRangeFromPoint(x, y)
      : (() => { const p = document.caretPositionFromPoint?.(x, y); if (!p) return null; const r = document.createRange(); r.setStart(p.offsetNode, p.offset); return r; })();
    mover.addEventListener('pointerdown', (e) => {
      if (!img) return;
      e.preventDefault(); mover.setPointerCapture(e.pointerId);
      const moving = img; frame.classList.add('is-moving');
      let target = null;
      const move = (ev) => {
        const r = rangeAt(ev.clientX, ev.clientY);
        if (!r || !root.contains(r.startContainer)) { caret.hidden = true; target = null; return; }
        target = r;
        const rect = (r.getClientRects()[0] || r.startContainer.parentElement?.getBoundingClientRect?.()), h = host.getBoundingClientRect();
        if (rect) Object.assign(caret.style, { left: (rect.left - h.left) + 'px', top: (rect.top - h.top) + 'px', height: Math.max(16, rect.height) + 'px' });
        caret.hidden = !rect;
      };
      const up = () => {
        mover.removeEventListener('pointermove', move); mover.removeEventListener('pointerup', up);
        frame.classList.remove('is-moving'); caret.hidden = true;
        if (!target || moving.contains(target.startContainer)) return place();
        // Where the drop is, as a Quill position: put the browser selection there and ask Quill.
        const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(target);
        quill.update('silent');
        const to = quill.getSelection(true)?.index;
        if (to == null) return place();
        const from = quill.getIndex(window.Quill.find(moving));
        const src = moving.getAttribute('src'), width = moving.getAttribute('width');
        hide();
        quill.deleteText(from, 1, 'user');
        insertAt(to > from ? to - 1 : to, src, width);
        changed();
      };
      mover.addEventListener('pointermove', move); mover.addEventListener('pointerup', up);
    });

    // -------------------------------------------------------------- crop
    function openCrop() {
      if (!img) return;
      const target = img, src = target.currentSrc || target.src;
      bar.hidden = true; frame.hidden = true;   // the crop window has the stage to itself
      const overlay = el('div', 'imgt-crop'); overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-label', 'Crop the picture');
      const box = el('div', 'imgt-crop-box');
      const pic = el('img'); pic.src = src; pic.alt = ''; pic.draggable = false;
      const sel = el('div', 'imgt-crop-sel');
      ['nw', 'ne', 'sw', 'se'].forEach((c) => { const h = el('span', 'imgt-handle imgt-' + c); h.dataset.corner = c; sel.appendChild(h); });
      box.append(pic, sel);
      const actions = el('div', 'imgt-crop-actions');
      const hint = el('span', 'imgt-crop-hint', 'Drag the corners, or drag inside the frame to move it.');
      const cancel = el('button', 'button', 'Cancel'); cancel.type = 'button';
      const apply = el('button', 'button primary', 'Crop'); apply.type = 'button';
      actions.append(hint, cancel, apply);
      overlay.append(box, actions);
      document.body.appendChild(overlay);
      const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true); if (img === target && target.isConnected) place(); };
      const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } else if (e.key === 'Enter') { e.preventDefault(); apply.click(); } };
      document.addEventListener('keydown', onKey, true);
      cancel.addEventListener('click', close);
      overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });

      // The selection, in pixels of the shown picture.
      let s = null;
      const draw = () => Object.assign(sel.style, { left: s.x + 'px', top: s.y + 'px', width: s.w + 'px', height: s.h + 'px' });
      pic.addEventListener('load', () => {
        const W = pic.clientWidth, H = pic.clientHeight;
        s = { x: Math.round(W * 0.1), y: Math.round(H * 0.1), w: Math.round(W * 0.8), h: Math.round(H * 0.8) };
        draw();
      }, { once: true });
      const drag = (e, mode) => {
        e.preventDefault(); e.stopPropagation();
        const W = pic.clientWidth, H = pic.clientHeight, sx = e.clientX, sy = e.clientY, o = { ...s };
        const t = e.currentTarget; t.setPointerCapture(e.pointerId);
        const move = (ev) => {
          const dx = ev.clientX - sx, dy = ev.clientY - sy;
          if (mode === 'move') { s.x = Math.max(0, Math.min(W - o.w, o.x + dx)); s.y = Math.max(0, Math.min(H - o.h, o.y + dy)); }
          else {
            let { x, y, w, h } = o;
            if (mode.includes('w')) { x = Math.max(0, Math.min(o.x + o.w - 20, o.x + dx)); w = o.x + o.w - x; } else { w = Math.max(20, Math.min(W - o.x, o.w + dx)); }
            if (mode.includes('n')) { y = Math.max(0, Math.min(o.y + o.h - 20, o.y + dy)); h = o.y + o.h - y; } else { h = Math.max(20, Math.min(H - o.y, o.h + dy)); }
            s = { x, y, w, h };
          }
          draw();
        };
        const up = () => { t.removeEventListener('pointermove', move); t.removeEventListener('pointerup', up); };
        t.addEventListener('pointermove', move); t.addEventListener('pointerup', up);
      };
      sel.addEventListener('pointerdown', (e) => drag(e, e.target.dataset.corner || 'move'));
      apply.addEventListener('click', () => {
        if (!s) return;
        const k = (pic.naturalWidth || pic.clientWidth) / pic.clientWidth;
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(s.w * k)); c.height = Math.max(1, Math.round(s.h * k));
        let out;
        try {
          c.getContext('2d').drawImage(pic, s.x * k, s.y * k, s.w * k, s.h * k, 0, 0, c.width, c.height);
          out = /^data:image\/jpe?g/i.test(src) || /\.jpe?g($|\?)/i.test(src) ? c.toDataURL('image/jpeg', 0.92) : c.toDataURL('image/png');
        } catch (_) {
          hint.textContent = 'This picture comes from another website, so it cannot be cropped here.';
          hint.classList.add('is-error');
          return;
        }
        // Keep the shown size in proportion to the part that is left.
        const shown = Number(target.getAttribute('width')) || target.getBoundingClientRect().width;
        const width = Math.max(40, Math.round(shown * s.w / pic.clientWidth));
        const at = quill.getIndex(window.Quill.find(target));
        close(); hide();
        quill.deleteText(at, 1, 'user');
        insertAt(at, out, width);
        changed();
      });
    }

    return { hide };
  };
})();
