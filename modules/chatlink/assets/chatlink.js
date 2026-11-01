/**
 * Chats in cases (modules/chatlink), on every page for people with the feature:
 *
 * IN A CHAT (the Teams tab, WhatsApp, Telegram): each chat page has a
 * .chat-actions-slot[data-for=<message list id>]; its list says which chat it
 * shows (data-source, data-chat, data-title) and announces "ecw:chat-opened"
 * and "ecw:chat-rendered" (after every redraw), with one .teams-msg[data-id]
 * per message. Here: the "Link to case" button, selecting messages (click,
 * Shift+click for a range, or drag over them), Done → choose the case → linked;
 * a 📁 on messages already in a case; the AI's suggestion for new messages.
 *
 * ON A CASE: each chat is a column of the timeline; this draws its rows
 * (window.ecwTimelineRenderers.chat), with "N messages not in this case" and Take out.
 *
 * Message text is set with textContent; only Teams' HTML (sanitized on the
 * server when it was read) is set as HTML, as the Teams tab does.
 */
(function () {
  'use strict';
  const base = window.ecwWebBase || '';
  const csrf = () => document.body.dataset.csrf || '';
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const icon = (cls) => { const i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; };

  async function api(action, params, body) {
    const url = new URL(base + 'api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const res = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { const e = new Error(data.error || 'Request failed (' + res.status + ')'); e.status = res.status; throw e; }
    return data;
  }

  function toast(text, link) {
    let t = document.querySelector('.chl-toast');
    if (!t) { t = el('div', 'chl-toast'); t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = text;
    if (link) { const a = el('a', '', link.label); a.href = link.href; t.append(' ', a); }
    t.hidden = false;
    clearTimeout(t._t); t._t = setTimeout(() => { t.hidden = true; }, 9000);
  }

  // ======================================================== in a chat
  const slots = [...document.querySelectorAll('.chat-actions-slot[data-for]')];
  slots.forEach((slot) => {
    const list = document.getElementById(slot.dataset.for);
    if (list) setupChat(slot, list);
  });

  function setupChat(slot, list) {
    let marks = {};            // message id → [{case_id, label}]
    let selecting = false;
    let selected = new Set();
    let anchor = null;         // the last clicked row (Shift+click range)
    let suggestion = null;
    let aiOff = false;
    let lastSeen = '';
    let suggestTimer = 0;
    const chat = () => ({ source: list.dataset.source || '', chat: list.dataset.chat || '', title: list.dataset.title || '' });

    const btn = el('button', 'button chl-link-btn');
    btn.type = 'button';
    btn.title = 'Choose messages of this chat and link them to a case';
    btn.append(icon('fa fa-folder-plus'), el('span', 'btn-label', ' Link to case'));
    slot.appendChild(btn);

    const bar = el('div', 'chl-bar');
    bar.hidden = true;
    const barText = el('span', 'chl-bar-text');
    const cancel = el('button', 'button', 'Cancel'); cancel.type = 'button';
    const done = el('button', 'button primary', 'Done'); done.type = 'button';
    bar.append(barText, cancel, done);
    list.after(bar);

    const strip = el('div', 'chl-suggest');
    strip.hidden = true;
    list.before(strip);

    const rows = () => [...list.querySelectorAll('.teams-msg[data-id]')];
    function countText() {
      barText.textContent = selected.size ? selected.size + ' message' + (selected.size === 1 ? '' : 's') + ' selected' : 'Click messages, or drag a box around them';
      done.disabled = !selected.size;
    }

    /** After every redraw: selection, 📁 marks, the AI's highlight. */
    function decorate() {
      list.classList.toggle('chl-selecting', selecting);
      const sugg = new Set(suggestion ? suggestion.refs : []);
      rows().forEach((r) => {
        const id = r.dataset.id;
        r.classList.toggle('chl-selected', selecting && selected.has(id));
        r.classList.toggle('chl-suggested', !selecting && sugg.has(id));
        let m = r.querySelector('.chl-mark');
        const cases = marks[id];
        if (cases && cases.length) {
          if (!m) { m = el('a', 'chl-mark'); (r.querySelector('.teams-msg-bubble') || r).appendChild(m); }
          m.textContent = '📁 ' + cases.map((c) => c.label).join(', ');
          m.href = base + 'case.php?id=' + cases[0].case_id;
          m.title = 'In ' + cases.map((c) => c.label).join(', ');
        } else if (m) m.remove();
      });
    }

    async function loadMarks() {
      const c = chat();
      if (!c.source || !c.chat) return;
      try { marks = (await api('chatlink_marks', { source: c.source, chat: c.chat })).marks || {}; } catch (_) { marks = {}; }
      if (chat().chat === c.chat) decorate();
    }

    // -------------------------------------------------------- selecting
    function start(preselect) {
      if (!chat().chat) return;
      selecting = true;
      selected = new Set(preselect || []);
      anchor = null;
      bar.hidden = false; strip.hidden = true;
      btn.classList.add('active');
      countText(); decorate();
    }
    function stop() {
      selecting = false; selected = new Set();
      bar.hidden = true; btn.classList.remove('active');
      decorate(); drawSuggestion();
    }
    btn.addEventListener('click', () => (selecting ? stop() : start()));
    cancel.addEventListener('click', stop);
    done.addEventListener('click', () => { if (selected.size) pickCase([...selected], suggestion && suggestion.refs.every((r) => selected.has(r)) ? suggestion : null); });
    document.addEventListener('keydown', (e) => {
      if (!selecting || document.querySelector('dialog[open]')) return;
      if (e.key === 'Escape') stop();
      // Ctrl+A: every message of the chat (unless typing somewhere).
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement && document.activeElement.tagName)) {
        e.preventDefault();
        rows().forEach((r) => selected.add(r.dataset.id));
        countText(); decorate();
      }
    });

    const between = (a, b) => { const all = rows(); let i = all.indexOf(a), j = all.indexOf(b); if (i > j) [i, j] = [j, i]; return i < 0 ? [b] : all.slice(i, j + 1); };
    const toggle = (r) => { selected.has(r.dataset.id) ? selected.delete(r.dataset.id) : selected.add(r.dataset.id); };

    /*
     * Like selecting files in Windows: press anywhere in the chat and drag a
     * rectangle; every message it touches is selected (started on a selected
     * message, it unselects instead). Near the top or bottom edge the chat
     * scrolls. A click without dragging toggles one message; Shift+click a
     * range. On a phone (touch) a tap toggles and dragging scrolls as usual.
     */
    const band = el('div', 'chl-band');
    band.hidden = true;
    let press = null;          // {x, y (in the list's content), row, base, remove, dragging, cx, cy}
    const point = (cx, cy) => { const b = list.getBoundingClientRect(); return { x: cx - b.left + list.scrollLeft, y: cy - b.top + list.scrollTop }; };
    function drawBand() {
      const p = point(press.cx, press.cy);
      const x1 = Math.min(press.x, p.x), x2 = Math.max(press.x, p.x), y1 = Math.min(press.y, p.y), y2 = Math.max(press.y, p.y);
      Object.assign(band.style, { left: x1 + 'px', top: y1 + 'px', width: (x2 - x1) + 'px', height: (y2 - y1) + 'px' });
      const lb = list.getBoundingClientRect();
      selected = new Set(press.base);
      rows().forEach((r) => {
        const rb = r.getBoundingClientRect();
        const top = rb.top - lb.top + list.scrollTop, bottom = top + rb.height;
        const left = rb.left - lb.left + list.scrollLeft, right = left + rb.width;
        if (bottom >= y1 && top <= y2 && right >= x1 && left <= x2) press.remove ? selected.delete(r.dataset.id) : selected.add(r.dataset.id);
      });
      countText(); decorate();
    }
    // While the pointer is near an edge, keep scrolling and growing the rectangle.
    function autoScroll() {
      if (!press || !press.dragging) return;
      const b = list.getBoundingClientRect();
      const step = press.cy < b.top + 40 ? -14 : press.cy > b.bottom - 40 ? 14 : 0;
      if (step) { list.scrollTop += step; drawBand(); }
      requestAnimationFrame(autoScroll);
    }

    list.addEventListener('pointerdown', (e) => {
      if (!selecting || e.button !== 0 || e.pointerType === 'touch') return;
      if (e.target.closest('.chl-mark')) return;
      const r = e.target.closest('.teams-msg[data-id]');
      e.preventDefault();   // no text selection, no link drag
      if (r && e.shiftKey && anchor) {
        between(anchor, r).forEach((x) => selected.add(x.dataset.id));
        anchor = r; countText(); decorate(); return;
      }
      const p = point(e.clientX, e.clientY);
      press = { x: p.x, y: p.y, cx: e.clientX, cy: e.clientY, row: r, base: new Set(selected), remove: !!(r && selected.has(r.dataset.id)), dragging: false };
    });
    document.addEventListener('pointermove', (e) => {
      if (!press) return;
      press.cx = e.clientX; press.cy = e.clientY;
      if (!press.dragging) {
        const p = point(e.clientX, e.clientY);
        if (Math.abs(p.x - press.x) + Math.abs(p.y - press.y) < 6) return;
        press.dragging = true;
        list.appendChild(band); band.hidden = false;
        list.classList.add('chl-banding');
        requestAnimationFrame(autoScroll);
      }
      drawBand();
    });
    document.addEventListener('pointerup', () => {
      if (!press) return;
      if (!press.dragging && press.row) { toggle(press.row); anchor = press.row; countText(); decorate(); }
      band.hidden = true; band.remove();
      list.classList.remove('chl-banding');
      press = null;
    });
    // A tap (phones) toggles one; links and pictures do not open while choosing.
    let lastPointer = 'mouse';
    list.addEventListener('pointerdown', (e) => { lastPointer = e.pointerType; }, true);
    list.addEventListener('click', (e) => {
      if (!selecting) return;
      const r = e.target.closest('.teams-msg[data-id]');
      if (!r || e.target.closest('.chl-mark')) return;
      e.preventDefault(); e.stopPropagation();
      if (lastPointer === 'touch') { toggle(r); anchor = r; countText(); decorate(); }
    }, true);

    // ---------------------------------------------------- choose the case
    function pickCase(refs, suggested) {
      const dlg = el('dialog', 'teams-new chl-dialog');
      const form = el('form'); form.method = 'dialog';
      const h = el('h2', '', 'Link ' + refs.length + ' message' + (refs.length === 1 ? '' : 's') + ' to a case');
      const q = el('input'); q.type = 'search'; q.placeholder = 'Case number, name or customer email'; q.setAttribute('aria-label', 'Find a case');
      const ul = el('ul', 'nc-suggest chl-cases'); ul.setAttribute('role', 'listbox');
      // Follow: every new message of this chat joins the case too, from now on.
      const followRow = el('label', 'checkbox-label chl-follow');
      const follow = el('input'); follow.type = 'checkbox';
      followRow.append(follow, ' Also add new messages of this chat to the case');
      const err = el('div', 'nc-result error'); err.hidden = true; err.setAttribute('role', 'alert');
      const actions = el('div', 'nc-actions');
      const close = el('button', 'button', 'Cancel'); close.type = 'button';
      actions.appendChild(close);
      form.append(h, q, ul, followRow, err, actions);
      dlg.appendChild(form);
      document.body.appendChild(dlg);
      close.addEventListener('click', () => dlg.close());
      dlg.addEventListener('close', () => dlg.remove());

      // Cases this chat is already in, and the AI's choice, come first.
      const known = new Map();
      if (suggested) known.set(suggested.case_id, { id: suggested.case_id, label: suggested.case_label, why: 'Suggested by the AI' });
      Object.values(marks).flat().forEach((c) => { if (!known.has(c.case_id)) known.set(c.case_id, { id: c.case_id, label: c.label, why: 'This chat is already in it' }); });
      const item = (c, why) => {
        const li = el('li'); li.setAttribute('role', 'option');
        const w = el('span', 'nc-who');
        w.append(el('strong', '', c.label), el('span', 'muted', why || ''));
        li.appendChild(w);
        li.addEventListener('click', () => link(c.id));
        return li;
      };
      async function find() {
        const text = q.value.trim();
        ul.textContent = '';
        if (!text) known.forEach((c) => ul.appendChild(item(c, c.why)));
        try {
          const { cases } = await api('cases', { q: text, status: 'all' });
          if (q.value.trim() !== text) return;
          cases.filter((c) => text || !known.has(c.id)).forEach((c) => ul.appendChild(item({ id: c.id, label: (c.case_number ? '#' + c.case_number + ' ' : '') + c.name }, c.status)));
          if (!ul.children.length) ul.appendChild(el('li', 'muted', 'No case found.'));
        } catch (e) { ul.appendChild(el('li', 'teams-error', e.message)); }
      }
      let t = 0;
      q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(find, 220); });
      async function link(caseId) {
        err.hidden = true;
        try {
          const c = chat();
          const r = await api('chatlink_link', {}, { source: c.source, chat: c.chat, title: c.title, messages: refs, case_id: caseId, follow: follow.checked });
          dlg.close();
          suggestion = null; stop(); loadMarks();
          toast((r.linked ? 'Linked ' + r.linked + ' message' + (r.linked === 1 ? '' : 's') + ' to ' + r.case_label + '.' : 'Those messages were already in ' + r.case_label + '.') + (follow.checked ? ' New messages of this chat will be added too.' : ''),
            { label: 'Open the case', href: base + 'case.php?id=' + r.case_id });
        } catch (e) { err.textContent = e.message; err.hidden = false; }
      }
      dlg.showModal(); q.focus(); find();
    }

    // ------------------------------------------------------ AI suggestion
    function drawSuggestion() {
      strip.textContent = '';
      strip.hidden = !suggestion || selecting;
      if (strip.hidden) { decorate(); return; }
      const s = suggestion;
      const text = el('span', 'chl-suggest-text');
      text.append(el('strong', '', '✨ ' + s.refs.length + ' message' + (s.refs.length === 1 ? '' : 's') + ' look like ' + s.case_label), s.reason ? ' · ' + s.reason : '');
      const review = el('button', 'button', 'Review'); review.type = 'button';
      review.title = 'Select them, change the selection, then choose the case';
      review.addEventListener('click', () => start(s.refs));
      const go = el('button', 'button primary', 'Link'); go.type = 'button';
      go.addEventListener('click', async () => {
        go.disabled = true;
        try {
          const c = chat();
          const r = await api('chatlink_link', {}, { source: c.source, chat: c.chat, title: c.title, messages: s.refs, case_id: s.case_id });
          suggestion = null; drawSuggestion(); loadMarks();
          toast('Linked ' + r.linked + ' message' + (r.linked === 1 ? '' : 's') + ' to ' + r.case_label + '.', { label: 'Open the case', href: base + 'case.php?id=' + r.case_id });
        } catch (e) { toast(e.message); go.disabled = false; }
      });
      const x = el('button', 'chl-suggest-x', '×'); x.type = 'button'; x.title = 'Not this'; x.setAttribute('aria-label', 'Dismiss the suggestion');
      x.addEventListener('click', () => { api('chatlink_dismiss', {}, { id: s.id }).catch(() => {}); suggestion = null; drawSuggestion(); });
      strip.append(text, review, go, x);
      decorate();
    }
    async function askAI() {
      const c = chat();
      if (aiOff || !c.chat) return;
      try {
        const r = await api('chatlink_suggest', {}, { source: c.source, chat: c.chat });
        if (r.ai === false) { aiOff = true; return; }
        if (chat().chat !== c.chat) return;
        suggestion = r.suggestion || null;
        drawSuggestion();
      } catch (e) { if (e.status === 403) aiOff = true; }
    }

    // ------------------------------------------------------------- events
    list.addEventListener('ecw:chat-opened', () => {
      if (selecting) stop();
      marks = {}; suggestion = null; lastSeen = '';
      drawSuggestion(); loadMarks();
    });
    list.addEventListener('ecw:chat-rendered', () => {
      decorate();
      // New messages: marks again, and (a moment later) what the AI makes of them.
      const all = rows();
      const newest = all.length ? all[all.length - 1].dataset.id : '';
      if (newest && newest !== lastSeen) {
        const first = lastSeen === '';
        lastSeen = newest;
        if (!first) loadMarks();
        clearTimeout(suggestTimer);
        suggestTimer = setTimeout(askAI, first ? 600 : 2500);
      }
    });
    if (list.dataset.chat) loadMarks();
  }

  // ===================================================== on a case
  // Each chat with messages in the case is a column of the timeline (lib/timeline.php
  // module columns); a row is a burst of its messages. case.js draws the cell, this its content.
  window.ecwTimelineRenderers = window.ecwTimelineRenderers || {};
  window.ecwTimelineRenderers.chat = function (event, column, ctx) {
    const box = el('div', 'chl-tl');
    let lastSender = null;
    (event.items || []).forEach((m) => {
      if (!m) return;
      // Honest about excerpts: what lies between two linked messages is said, not hidden.
      if (m.skipped_before > 0) {
        const gap = el('div', 'chl-tl-gap', '··· ' + m.skipped_before + ' message' + (m.skipped_before === 1 ? '' : 's') + ' not in this case');
        if (column && column.open_url) { const a = el('a', '', 'open the chat'); a.href = column.open_url; gap.append(' · ', a); }
        box.appendChild(gap);
        lastSender = null;
      }
      const row = el('div', 'chl-tl-msg ' + (m.direction === 'out' ? 'dir-outgoing' : 'dir-incoming'));
      if (m.sender !== lastSender) row.appendChild(el('div', 'chl-tl-from', m.sender));
      lastSender = m.sender;
      const bubble = el('div', 'chl-tl-bubble');
      const body = el('div', 'chl-tl-body');
      if (m.html) body.innerHTML = m.html;   // XSS: sanitized on the server when it was read (Teams)
      else body.textContent = m.text;
      bubble.appendChild(body);
      if (m.filename && !m.html) bubble.appendChild(el('div', 'chl-tl-file', '📎 ' + m.filename));
      // In the case's time zone (the time rail's), when the page gives it.
      const at = (v, full) => (ctx && (full ? ctx.fullOf : ctx.timeOf) ? (full ? ctx.fullOf : ctx.timeOf)(v) : (full ? new Date(v).toLocaleString() : new Date(v).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })));
      const meta = el('div', 'chl-tl-time', at(m.at, false));
      meta.title = 'Linked by ' + (m.linked_by || 'someone') + ', ' + at(m.linked_at, true);
      const out = el('button', 'chl-unlink', '×'); out.type = 'button'; out.title = 'Take this message out of the case'; out.setAttribute('aria-label', 'Take this message out of the case');
      out.addEventListener('click', async (e) => {
        if (window.ecwArm && !window.ecwArm(e.currentTarget, 'Click again to take it out')) return;
        try { await api('chatlink_unlink', {}, { id: m.id }); ctx && ctx.reload ? ctx.reload() : location.reload(); }
        catch (err) { toast(err.message); }
      });
      meta.appendChild(out);
      bubble.appendChild(meta);
      row.appendChild(bubble);
      box.appendChild(row);
    });
    return box;
  };
})();
