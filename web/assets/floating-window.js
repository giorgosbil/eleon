/* Shared non-modal window controls for mail and case composers. */
window.ecwFloatingWindow = function (panel, handle) {
  handle.addEventListener('pointerdown', event => {
    if (event.button !== 0 || panel.dataset?.expanded === 'true' || panel.classList.contains('inline') || event.target.closest('button, a, input, summary, details') || (panel.classList.contains('file-preview-overlay') && window.innerWidth <= 860)) return;
    const rect = panel.getBoundingClientRect();
    const dx = event.clientX - rect.left, dy = event.clientY - rect.top;
    panel.style.left = rect.left + 'px'; panel.style.top = rect.top + 'px';
    panel.style.right = 'auto'; panel.style.bottom = 'auto';
    handle.setPointerCapture(event.pointerId);
    const move = e => {
      panel.style.left = Math.max(0, Math.min(window.innerWidth - Math.min(panel.offsetWidth, 120), e.clientX - dx)) + 'px';
      panel.style.top = Math.max(0, Math.min(window.innerHeight - 48, e.clientY - dy)) + 'px';
    };
    const stop = () => { handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', stop); handle.removeEventListener('pointercancel', stop); };
    handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', stop); handle.addEventListener('pointercancel', stop);
  });
};
window.ecwInitials = function (name, email) {
  const words = (name || (email || '').split('@')[0].replace(/[._-]/g, ' ')).trim().split(/\s+/).filter(Boolean);
  return words.length ? (Array.from(words[0])[0] + (words.length > 1 ? Array.from(words.at(-1))[0] : '')).toUpperCase() : '?';
};

// Adjacent columns share their available width; resizing never changes time rows.
window.ecwCaseDividers = function(grid, columns, lastRow, caseId) {
  const key = 'ecw:case-widths:' + (document.body.dataset.user || '') + ':' + caseId;
  let stored = {};
  try { stored = JSON.parse(localStorage.getItem(key) || '{}'); } catch (_) {}
  let widths = columns.map(c => Number(stored[c.thread_id]) || 0);
  // Widths are applied as proportions (fr), not pixels: the columns always fill
  // the space, so opening or closing the notes panel never leaves a gap.
  const apply = () => { grid.style.gridTemplateColumns = 'var(--rail-width,74px) ' + widths.map(w => 'minmax(240px,' + Math.max(1, Math.round(w)) + 'fr)').join(' '); };
  if (widths.every(w => w >= 240)) apply();
  function resize(index, delta, start) {
    widths = [...start];
    const total = start[index] + start[index+1];
    widths[index] = Math.max(240, Math.min(total-240, start[index]+delta));
    widths[index+1] = total-widths[index]; apply();
  }
  const measure = () => columns.map(c => grid.querySelector('.tl-head[data-thread-id="'+c.thread_id+'"]').getBoundingClientRect().width);
  const save = () => {
    columns.forEach((c,i) => { stored[c.thread_id] = widths[i]; });
    try { localStorage.setItem(key,JSON.stringify(stored)); } catch (_) {}
  };
  columns.slice(0,-1).forEach((column,index) => {
    const divider = document.createElement('div'); divider.className = 'tl-divider'; divider.tabIndex = 0;
    divider.setAttribute('role','separator'); divider.setAttribute('aria-orientation','vertical');
    divider.setAttribute('aria-label','Resize columns ' + (index+1) + ' and ' + (index+2));
    divider.style.gridColumn = String(index+2); divider.style.gridRow = '1 / '+lastRow;
    divider.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      event.preventDefault(); const start = measure(), x = event.clientX;
      divider.setPointerCapture(event.pointerId); divider.classList.add('dragging');
      const move = e => resize(index,e.clientX-x,start);
      const stop = () => { divider.classList.remove('dragging'); divider.removeEventListener('pointermove',move); divider.removeEventListener('pointerup',stop); divider.removeEventListener('pointercancel',stop); save(); };
      divider.addEventListener('pointermove',move); divider.addEventListener('pointerup',stop); divider.addEventListener('pointercancel',stop);
    });
    divider.addEventListener('keydown', event => {
      if (!['ArrowLeft','ArrowRight'].includes(event.key)) return;
      event.preventDefault(); resize(index,event.key === 'ArrowLeft' ? -20 : 20,measure()); save();
    });
    grid.appendChild(divider);
  });
};
window.ecwComposerDivider = function(handle, history) {
  let percent = 42;
  const set = value => { percent = Math.max(25,Math.min(65,value)); history.style.width = percent+'%'; handle.setAttribute('aria-valuenow',String(Math.round(percent))); };
  handle.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;
    event.preventDefault(); handle.setPointerCapture(event.pointerId);
    const area = handle.parentElement.getBoundingClientRect();
    const move = e => set((area.right-e.clientX)/area.width*100);
    const stop = () => { handle.removeEventListener('pointermove',move); handle.removeEventListener('pointerup',stop); handle.removeEventListener('pointercancel',stop); };
    handle.addEventListener('pointermove',move); handle.addEventListener('pointerup',stop); handle.addEventListener('pointercancel',stop);
  });
  handle.addEventListener('keydown', event => {
    if (!['ArrowLeft','ArrowRight'].includes(event.key)) return;
    event.preventDefault(); set(percent+(event.key === 'ArrowLeft' ? 3 : -3));
  });
  set(percent);
};
