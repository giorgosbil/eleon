/**
 * Email Case Workspace — case timeline.
 *
 * Draws one CSS grid: column 1 is the time rail, columns 2..n are threads, and
 * every message is placed at an explicit (row, column). Row numbers are
 * assigned in one pass over the server's event stream, which is already ordered
 * — nothing here sorts, groups or packs. A bubble's position is a fact about
 * when it happened.
 *
 * The server owns the gap algorithm. Hiding a column or switching threads on a
 * narrow screen re-requests the timeline rather than filtering here, so the
 * elapsed-time labels always describe what is actually on screen and there is
 * only ever one implementation of that arithmetic.
 *
 * XSS: message bodies and quoted history are written with innerHTML and are
 * sanitized server-side by HTMLPurifier (lib/sanitize.php). Both sites are
 * marked. Everything else uses textContent.
 */

(function () {
  'use strict';

  const state = {
    csrf: document.body.dataset.csrf,
    caseId: Number(document.body.dataset.case),
    // Times follow this computer's zone, like Outlook when travelling (assets/timezone.js).
    timezone: (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || document.body.dataset.timezone || 'UTC'; } catch (_) { return document.body.dataset.timezone || 'UTC'; } })(),
    columns: [],
    events: [],
    summary: null,
    mobile: window.matchMedia('(max-width: 860px)').matches,
    activeThreadId: null,
    showImages: true,
    mailboxesById: null,
  };

  const $ = (id) => document.getElementById(id);

  async function api(action, params, options) {
    options = options || {};
    const url = new URL('api/index.php', window.location.href);
    url.searchParams.set('action', action);
    Object.keys(params || {}).forEach((k) => {
      if (params[k] !== null && params[k] !== undefined && params[k] !== '') {
        url.searchParams.set(k, String(params[k]));
      }
    });

    const init = { method: options.method || 'GET', headers: {} };
    if (init.method !== 'GET') {
      init.headers['X-ECW-CSRF'] = state.csrf;
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body || {});
    }

    const response = await fetch(url.toString(), init);
    const text = await response.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: 'bad response' }; }
    if (!response.ok) throw new Error(data.error || ('request failed (' + response.status + ')'));
    return data;
  }

  /** action: optional { label, onClick }, shown ~8s instead of the default ~2.6s. */
  function toast(message, isError, action) {
    const el = $('toast');
    const actionBtn = $('toastAction');
    $('toastMsg').textContent = message;
    el.classList.toggle('error', !!isError);
    el.hidden = false;
    if (action) {
      actionBtn.textContent = action.label;
      actionBtn.hidden = false;
      actionBtn.onclick = () => { el.hidden = true; action.onClick(); };
    } else {
      actionBtn.hidden = true;
      actionBtn.onclick = null;
    }
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.hidden = true; }, action ? 8000 : (isError ? 6000 : 2600));
  }

  // Times are stored UTC and shown in the zone of the computer looking at them
  // (state.timezone), as everywhere else in the app.
  function utc(value) {
    if (!value) return null;
    const iso = String(value).replace(' ', 'T');
    const date = new Date(/[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : iso + 'Z');
    return Number.isNaN(date.getTime()) ? null : date;
  }
  function timeOf(value) {
    const d = utc(value);
    return d ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: state.timezone }) : 'Time unavailable';
  }
  function dateOf(value) {
    const d = utc(value);
    return d ? d.toLocaleDateString([], { day: '2-digit', month: 'short', timeZone: state.timezone }) : '';
  }
  function fullOf(value) {
    const d = utc(value);
    return d ? d.toLocaleString([], {
      day: '2-digit', month: 'short', year: 'numeric',
      hour: '2-digit', minute: '2-digit', timeZone: state.timezone,
    }) : '—';
  }
  function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  // ---------------------------------------------------------------- loading

  let loadSequence = 0;
  let timelineFingerprint = '';

  // Where the reader is, kept through a redraw. A redraw empties the grid and the email bodies
  // arrive after it (hydrateCaseBodies), so a saved scrollTop alone was clamped to a shorter page
  // and then shifted again by every body: the reader at the end landed somewhere in the middle.
  // Kept instead: "at the end", or the email at the top of the view and how far down it was.
  function captureScroll() {
    const scroll = $('timelineScroll');
    const box = scroll.getBoundingClientRect();
    const pos = { top: scroll.scrollTop, left: scroll.scrollLeft, atEnd: scroll.scrollTop > 0 && scroll.scrollHeight - scroll.clientHeight - scroll.scrollTop < 40, id: '', offset: 0 };
    let best = null;
    for (const el of $('timeline').querySelectorAll('.bubble[id]')) {
      const r = el.getBoundingClientRect();
      if (r.bottom <= box.top || r.top >= box.bottom || r.right <= box.left || r.left >= box.right) continue;
      if (!best || r.top < best.top) best = { id: el.id, top: r.top };
    }
    if (best) { pos.id = best.id; pos.offset = best.top - box.top; }
    return pos;
  }
  // Long emails fold one frame after they are drawn (installBubbleClamp): placed again after that.
  function restoreScroll(pos) {
    placeScroll(pos);
    requestAnimationFrame(() => placeScroll(pos));
  }
  function placeScroll(pos) {
    const scroll = $('timelineScroll');
    scroll.scrollLeft = pos.left;
    if (pos.atEnd) { scroll.scrollTop = scroll.scrollHeight; return; }
    const el = pos.id ? document.getElementById(pos.id) : null;
    if (!el) { scroll.scrollTop = pos.top; return; }
    scroll.scrollTop += el.getBoundingClientRect().top - scroll.getBoundingClientRect().top - pos.offset;
  }
  // Bodies loaded once stay loaded: a refresh (new mail, read state, a colleague's edit) draws
  // them straight away instead of fetching and swapping every one again.
  // Only the text and its files: read state and the rest come fresh with each refresh.
  const bodyCache = new Map();
  const BODY_FIELDS = ['body_html', 'body_state', 'quoted_html', 'has_quoted', 'signature_html', 'attachments', 'attachments_pending', 'needs_inline_images'];
  async function load() {
    const sequence = ++loadSequence;
    try {
      const data = await api('case_timeline', {
        case_id: state.caseId,
        images: state.showImages ? 1 : '',
        thread_id: state.mobile && state.activeThreadId && state.activeThreadId !== 'all' ? state.activeThreadId : '',
      });
      if (sequence !== loadSequence) return;
      const fingerprint = JSON.stringify([state.mobile, state.activeThreadId, data]);
      if (fingerprint === timelineFingerprint) return;
      timelineFingerprint = fingerprint;
      const pos = captureScroll();
      for (const event of data.events) if (event.bubble && bodyCache.has(event.message_id)) Object.assign(event.bubble, bodyCache.get(event.message_id));
      const expanded = Array.from(document.querySelectorAll('.bubble-expand[aria-expanded="true"]')).map(el => el.closest('.bubble')?.id).filter(Boolean);
      state.summary = data.summary;
      state.columns = data.columns;
      state.events = data.events;

      renderSummary(data.summary);
      renderColumnToggles(data.columns);
      renderThreadPicker(data.columns);
      renderTimeline(data);
      scheduleMarkRead(data.columns);

      // Opened on one email (#msg-123, from the case list's preview): go to it, once.
      if (!state.hashDone && /^#msg-\d+$/.test(location.hash)) { state.hashDone = true; requestAnimationFrame(() => scrollToMessage(Number(location.hash.slice(5)))); }
      for (const id of expanded) {
        const body = document.getElementById(id)?.querySelector('.bubble-body');
        if (body) { body.classList.remove('is-collapsed'); const button = body.parentElement.querySelector('.bubble-expand'); if (button) { button.textContent = 'Show less'; button.setAttribute('aria-expanded','true'); } }
      }
      restoreScroll(pos);
      void hydrateCaseBodies(data);

      $('timelineTruncated').hidden = !data.truncated;
      if (data.truncated) {
        $('timelineTruncated').textContent =
          'Showing the first ' + data.events.length + ' messages of this case. Later activity is not displayed.';
      }
    } catch (e) {
      toast(e.message, true);
    }
  }

  // ------------------------------------------------------------ read state
  // Viewing the case reads its conversations — the same read state as the
  // inbox and Outlook, so they clear there too. Messages that were new when
  // the page opened stay highlighted until it is reloaded. A conversation the
  // reader marked unread here is left alone.
  const seenUnread = new Set();
  const keptUnread = new Set();
  let markTimer = null;
  function scheduleMarkRead(columns) {
    clearTimeout(markTimer);
    const onScreen = (c) => !state.mobile || state.activeThreadId === 'all' || !state.activeThreadId || Number(c.thread_id) === Number(state.activeThreadId);
    const pending = columns.filter((c) => c.unread_count > 0 && !c.is_hidden && onScreen(c) && !keptUnread.has(c.thread_id));
    if (!pending.length) return;
    markTimer = setTimeout(async () => {
      if (document.hidden) { document.addEventListener('visibilitychange', () => scheduleMarkRead(state.columns), { once: true }); return; }
      for (const c of pending) {
        try { await api('case_mark_read', {}, { method: 'POST', body: { case_id: state.caseId, thread_id: c.thread_id, is_read: true } }); c.unread_count = 0; } catch (_) {}
      }
      renderColumnHeadersUnread();
      notifyUnreadChanged();
    }, 1500);
  }
  async function setColumnRead(column, isRead) {
    try {
      await api('case_mark_read', {}, { method: 'POST', body: { case_id: state.caseId, thread_id: column.thread_id, is_read: isRead } });
      isRead ? keptUnread.delete(column.thread_id) : keptUnread.add(column.thread_id);
      toast(isRead ? 'Marked as read' : 'Marked as unread — in the inbox and Outlook too');
      notifyUnreadChanged();
      await load();
    } catch (e) { toast(e.message, true); }
  }
  function notifyUnreadChanged() { document.dispatchEvent(new CustomEvent('ecw:unread-changed')); }
  /** Drop the unread pill from headers once read, without a full re-render. */
  function renderColumnHeadersUnread() {
    document.querySelectorAll('.tl-head[data-thread-id]').forEach((head) => {
      const c = state.columns.find((col) => String(col.thread_id) === head.dataset.threadId);
      if (c && !c.unread_count) head.querySelector('.unread-pill')?.remove();
    });
  }

  async function hydrateCaseBodies(data) {
    for (const event of data.events) {
      if (state.events !== data.events) return;
      if (!event.bubble) continue;   // notes have no email body to load
      if (bodyCache.has(event.message_id)) continue;
      if (event.bubble.removed || (event.bubble.body_state === 'fetched' && !event.bubble.needs_inline_images && !event.bubble.attachments_pending)) continue;
      const column = data.columns.find(c => c.thread_id === event.thread_id);
      if (!column) continue;
      try {
        const detail = await api('body', {mailbox_id:column.mailbox_id, message_id:event.message_id, images:1});
        if (state.events !== data.events) return;
        const message = detail.messages.find(m => m.id === event.message_id);
        const article = document.getElementById('msg-' + event.message_id);
        if (!message || !article) continue;
        const body = {};
        for (const key of BODY_FIELDS) if (key in message) body[key] = message[key];
        bodyCache.set(event.message_id, body);
        Object.assign(event.bubble, message);
        const cell = article.parentElement;
        const pos = captureScroll();   // a longer body above the view must not push the reader away
        cell.replaceWith(bubbleCell(event, Number(cell.style.gridRow), Number(cell.style.gridColumn)));
        restoreScroll(pos);
      } catch (e) { /* Original email remains accessible from its bubble. */ }
    }
  }

  /** The status menu in the status's colour (the company's statuses have their own). */
  function paintStatus() {
    const opt = $('caseStatus').selectedOptions[0];
    $('caseStatus').style.color = opt && opt.value !== opt.dataset.base ? (opt.dataset.color || '') : '';
    $('caseStatus').dataset.base = opt ? opt.dataset.base : '';
  }

  function renderSummary(summary) {
    $('caseName').textContent = summary.name;
    $('caseNumber').textContent = summary.number;
    $('caseStatus').value = summary.sub_status || summary.status;
    // A status taken away in Settings: its group.
    if (!$('caseStatus').value) $('caseStatus').value = summary.status;
    paintStatus();
    if ($('caseOwner').options.length > 1) $('caseOwner').value = summary.owner_user_id || '';
    if ($('caseHandler')?.options.length > 1) $('caseHandler').value = summary.handler_user_id || '';
    $('caseCreated').textContent = fullOf(summary.created_at);
    $('caseActivity').textContent = summary.last_activity ? fullOf(summary.last_activity) : '—';
    if ($('caseThreads')) $('caseThreads').textContent = String(summary.thread_count);
    document.title = summary.number + ' — ' + summary.name;
    // case-sharing.js draws the group, sharing and avatar pickers from this.
    document.dispatchEvent(new CustomEvent('ecw:case-summary', { detail: summary }));
  }

  // ----------------------------------------------------------- the timeline

  function renderTimeline(data) {
    const grid = $('timeline');
    grid.textContent = '';
    grid.classList.toggle('mobile-compare', state.mobile && state.activeThreadId === 'all');

    const visible = data.columns.filter((c) => !c.is_hidden)
      .filter((c) => !state.mobile || state.activeThreadId === 'all' || !state.activeThreadId || String(c.thread_id) === String(state.activeThreadId));

    $('timelineEmpty').hidden = visible.length > 0;
    if (!visible.length) {
      grid.style.setProperty('--columns', 1);
      return;
    }

    grid.style.setProperty('--columns', visible.length);

    const columnPosition = new Map();
    visible.forEach((column, index) => columnPosition.set(column.thread_id, index));

    // Row 1 is the sticky header band.
    grid.appendChild(railHeader());
    visible.forEach((column, index) => grid.appendChild(columnHeader(column, index + 2)));

    grid.style.gridTemplateColumns = '';
    let row = 2;
    data.events.forEach((event) => {
      // Notes are not timeline rows: they open from each bubble's 💬 (case-panel.js).
      if (event.type === 'note') return;
      const position = columnPosition.get(event.thread_id);
      if (position === undefined) return;   // column hidden since the fetch

      if (event.gap && event.gap.label) {
        grid.appendChild(gapRow(event.gap, event.occurred_at, row));
        row++;
      }

      // Rows of module columns (a chat linked to the case…) are drawn by their module.
      if (event.type !== 'email') {
        const draw = (window.ecwTimelineRenderers || {})[event.type];
        if (!draw) return;
        const cell = document.createElement('div');
        cell.className = 'tl-cell tl-cell-' + event.type;
        cell.dataset.threadId = String(event.thread_id);
        cell.style.gridRow = String(row);
        cell.style.gridColumn = String(position + 2);
        // timeOf / fullOf: in this computer's time zone, like the time rail.
        cell.appendChild(draw(event, visible[position], { reload: load, toast, timeOf, fullOf }));
        grid.appendChild(railCell(event, row));
        grid.appendChild(cell);
        row++;
        return;
      }
      grid.appendChild(railCell(event, row));
      grid.appendChild(bubbleCell(event, row, position + 2));
      row++;
    });
    // Draft replies and scheduled emails of each conversation: a last row, under their column (as Mail shows them).
    if (visible.some((c) => (c.drafts || []).length)) {
      visible.forEach((column, index) => {
        if (!(column.drafts || []).length) return;
        const cell = document.createElement('div');
        cell.className = 'tl-cell tl-drafts';
        cell.dataset.threadId = String(column.thread_id);
        cell.style.gridRow = String(row);
        cell.style.gridColumn = String(index + 2);
        column.drafts.forEach((d) => cell.appendChild(draftCard(d)));
        grid.appendChild(cell);
      });
      row++;
    }
    if (!state.mobile && visible.length > 1 && window.ecwCaseDividers) window.ecwCaseDividers(grid, visible, row, state.caseId);
    window.ecwPaintNoteBubbles?.(grid, window.ecwCaseNoteGroups?.() || []);
  }
  // The notes panel loaded or changed: repaint every 💬; "Show email" scrolls to it.
  document.addEventListener('ecw:note-groups', (e) => { const g = $('timeline'); if (g) window.ecwPaintNoteBubbles?.(g, e.detail || []); });
  document.addEventListener('ecw:jump-to-message', (e) => scrollToMessage(e.detail.message_id));
  // Export: one more click to be sure ("Export the case?"), then the .zip downloads.
  const exportBtn = document.getElementById('caseExportBtn');
  exportBtn?.addEventListener('click', () => {
    const go = () => { location.href = exportBtn.dataset.href; (window.ecwPageToast || (() => {}))('Preparing the export… the .zip downloads in a moment'); };
    if (!window.ecwChoice) { go(); return; }
    window.ecwChoice(exportBtn, [{ label: 'Export the case?', icon: 'fa-file-export', primary: true, title: 'Emails, files, notes, checklist and history as a .zip', onPick: go }, { label: 'Cancel' }]);
  });

  function railHeader() {
    const cell = document.createElement('div');
    cell.className = 'tl-head rail';
    cell.style.gridRow = '1';
    cell.style.gridColumn = '1';
    // A clock instead of the word: the time column stays narrow.
    cell.innerHTML = '<i class="fa fa-clock" aria-hidden="true"></i>';
    cell.title = 'Time';
    cell.setAttribute('aria-label', 'Time');
    return cell;
  }

  function columnHeader(column, position) {
    if (column.kind && column.kind !== 'email') return moduleColumnHeader(column, position);
    const cell = document.createElement('div');
    cell.className = 'tl-head';
    cell.dataset.threadId = String(column.thread_id);
    cell.style.gridRow = '1';
    cell.style.gridColumn = String(position);



    const title = document.createElement('div');
    title.className = 'tl-head-title';
    const name = document.createElement('span');
    name.className = 'tl-head-name';
    name.textContent = column.label;
    name.title = column.subject || column.label;
    const count = document.createElement('span');
    count.className = 'tl-head-meta';
    count.textContent = column.message_count + (column.message_count === 1 ? ' message' : ' messages');
    title.append(name, count);
    if (column.unread_count > 0) {
      const pill = document.createElement('span');
      pill.className = 'unread-pill';
      pill.textContent = column.unread_count + ' new';
      pill.title = column.unread_count + ' unread email' + (column.unread_count === 1 ? '' : 's');
      title.appendChild(pill);
    }

    const participants = document.createElement('div');
    participants.className = 'tl-head-meta';
    participants.textContent = (column.participants || []).map((p) => p.name || p.email).join(' ↔ ');
    participants.classList.add('tl-head-participants');
    participants.title = participants.textContent;

    const latest = document.createElement('div');
    latest.className = 'tl-head-meta';
    latest.textContent = column.latest_at ? 'last ' + fullOf(column.latest_at) : 'no activity';

    const actions = document.createElement('div');
    actions.className = 'tl-head-actions';
    actions.append(
      smallButton('Rename', () => renameColumn(column)),
      openThreadLink(column),
      smallButton('Hide', () => setColumnHidden(column, true)),
      smallButton('Unlink', (btn) => unlinkColumn(column, btn))
    );
    if (column.can_write !== false) {
      const unread = column.unread_count > 0 || keptUnread.has(column.thread_id);
      const b = smallButton(unread ? 'Mark read' : 'Mark unread', () => setColumnRead(column, unread));
      b.title = unread ? 'Mark this conversation as read' : 'Mark the latest email unread (inbox and Outlook too)';
      actions.insertBefore(b, actions.children[2] || null);
    }

    // Everything under the title folds away in compact mode (case-compact.js) and drops down on hover.
    const more = document.createElement('div');
    more.className = 'tl-head-more';
    more.append(participants, latest, actions);
    cell.append(title, more);
    // Whose conversation (lib/next_actions.php): the rule "we wrote to the supplier: waiting on the supplier" reads it.
    if (window.ecwFeatures?.next_actions) more.insertBefore(roleSelect(column), participants);
    return cell;
  }

  /**
   * A module's column (lib/modules.php ecw_timeline_module_columns), e.g. a chat
   * whose messages are linked to the case: its icon and name, then Rename,
   * Open, Hide and Unlink through the module's own API actions.
   */
  function moduleColumnHeader(column, position) {
    const cell = document.createElement('div');
    cell.className = 'tl-head tl-head-' + column.kind;
    cell.dataset.threadId = String(column.thread_id);
    cell.style.gridRow = '1';
    cell.style.gridColumn = String(position);
    const title = document.createElement('div');
    title.className = 'tl-head-title';
    const name = document.createElement('span');
    name.className = 'tl-head-name';
    if (column.icon) { const i = document.createElement('i'); i.className = column.icon; i.setAttribute('aria-hidden', 'true'); name.append(i, ' '); }
    name.append(column.label);
    name.title = column.subject || column.label;
    const count = document.createElement('span');
    count.className = 'tl-head-meta';
    count.textContent = column.message_count + (column.message_count === 1 ? ' message' : ' messages');
    title.append(name, count);
    const sub = document.createElement('div');
    sub.className = 'tl-head-meta tl-head-participants';
    sub.textContent = column.subtitle || '';
    const latest = document.createElement('div');
    latest.className = 'tl-head-meta';
    latest.textContent = column.latest_at ? 'last ' + fullOf(column.latest_at) : '';
    const actions = document.createElement('div');
    actions.className = 'tl-head-actions';
    // Reply from the case, when the module says this person may (column.reply_action).
    if (column.reply_action) {
      const r = smallButton('Reply', () => replyToColumn(column));
      r.className = 'tl-head-reply';
      if (column.reply_note) r.title = column.reply_note;
      actions.appendChild(r);
    }
    actions.appendChild(smallButton('Rename', () => renameColumn(column)));
    if (column.open_url) { const a = document.createElement('a'); a.textContent = column.open_label || 'Open'; a.href = column.open_url; actions.appendChild(a); }
    // The module's own switches (e.g. "Keep adding new messages"): each is a change of the column.
    (column.extra_actions || []).forEach((x) => {
      const b = smallButton(x.label, async () => { try { await updateColumn(column, x.changes || {}); await load(); } catch (e) { toast(e.message, true); } });
      if (x.title) b.title = x.title;
      if (x.active) b.classList.add('is-on');
      actions.appendChild(b);
    });
    actions.append(smallButton('Hide', () => setColumnHidden(column, true)), smallButton('Unlink', (btn) => unlinkColumn(column, btn)));
    const more = document.createElement('div');
    more.className = 'tl-head-more';
    more.append(sub, latest, actions);
    if (window.ecwFeatures?.next_actions && column.update_action) more.insertBefore(roleSelect(column), sub);
    cell.append(title, more);
    return cell;
  }

  /** Write into a module column's conversation (a chat) from the case: a small box, sent through the module. */
  function replyToColumn(column) {
    const dlg = document.createElement('dialog');
    dlg.className = 'teams-new tl-reply-dialog';
    const form = document.createElement('form');
    form.method = 'dialog';
    const h = document.createElement('h2');
    h.textContent = 'Reply in ' + column.label;
    const text = document.createElement('textarea');
    text.rows = 4; text.maxLength = 4096; text.setAttribute('aria-label', 'Message');
    const note = document.createElement('p');
    note.className = 'muted small';
    note.textContent = column.reply_note || '';
    const err = document.createElement('p');
    err.className = 'teams-error'; err.hidden = true; err.setAttribute('role', 'alert');
    const row = document.createElement('div');
    row.className = 'nc-actions';
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'button'; cancel.textContent = 'Cancel';
    const send = document.createElement('button'); send.type = 'submit'; send.className = 'button primary'; send.textContent = 'Send';
    row.append(cancel, send);
    form.append(h, text, note, err, row);
    dlg.appendChild(form);
    document.body.appendChild(dlg);
    cancel.addEventListener('click', () => dlg.close());
    dlg.addEventListener('close', () => dlg.remove());
    text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) form.requestSubmit(); });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!text.value.trim()) return;
      send.disabled = true; err.hidden = true;
      try {
        await api(column.reply_action, { case_id: state.caseId }, { method: 'POST', body: { column: column.thread_id, text: text.value } });
        dlg.close();
        toast('Sent');
        await load();
      } catch (ex) { err.textContent = ex.message; err.hidden = false; send.disabled = false; }
    });
    dlg.showModal();
    text.focus();
  }

  /** Change a column: an email thread's link (case_thread_update), or a module column through its own action. */
  function updateColumn(column, changes) {
    return column.update_action
      ? api(column.update_action, { case_id: state.caseId }, { method: 'POST', body: Object.assign({ column: column.thread_id }, changes) })
      : api('case_thread_update', { case_id: state.caseId, thread_id: column.thread_id }, { method: 'POST', body: changes });
  }

  // Customer, supplier, internal and the company's own roles (Settings → Case settings).
  const ROLE_LIST = (window.ecwCaseSettings && window.ecwCaseSettings.roles) || { customer: { label: 'Customer' }, supplier: { label: 'Supplier' }, internal: { label: 'Internal' } };
  const BUILTIN_ROLES = ['customer', 'supplier', 'internal'];
  function paintRole(select, role) {
    select.className = 'tl-role' + (role ? ' is-' + (BUILTIN_ROLES.includes(role) ? role : 'custom') : '');
    const custom = role && !BUILTIN_ROLES.includes(role) ? ROLE_LIST[role] : null;
    select.style.borderColor = custom ? custom.color : '';
    select.style.color = custom ? custom.color : '';
  }
  function roleSelect(column) {
    const select = document.createElement('select');
    select.setAttribute('aria-label', 'Whose conversation this is');
    select.title = 'Whose conversation this is: emailing the customer or the supplier sets who the case waits on';
    const first = document.createElement('option'); first.value = ''; first.textContent = 'Whose conversation?'; select.appendChild(first);
    Object.entries(ROLE_LIST).forEach(([value, r]) => {
      const o = document.createElement('option'); o.value = value; o.textContent = r.label; select.appendChild(o);
    });
    // A role taken away in Settings still shows on the conversations that have it.
    if (column.party_role && !ROLE_LIST[column.party_role]) { const o = document.createElement('option'); o.value = column.party_role; o.textContent = column.party_role; select.appendChild(o); }
    select.value = column.party_role || '';
    paintRole(select, column.party_role);
    select.addEventListener('change', async () => {
      try {
        await updateColumn(column, { party_role: select.value });
        column.party_role = select.value || null;
        paintRole(select, column.party_role);
      } catch (e) { toast(e.message, true); select.value = column.party_role || ''; }
    });
    return select;
  }

  /**
   * A draft reply or a scheduled email of a column: Edit opens it in the case's composer,
   * Delete (one's own); one scheduled in Outlook itself opens there. Mail's look (app.css .thread-draft).
   */
  function draftCard(d) {
    const card = document.createElement('div');
    card.className = 'thread-draft' + (d.remote ? ' scheduled-remote' : d.send_state === 'failed' ? ' failed' : d.send_state === 'scheduled' ? ' scheduled' : '');
    const head = document.createElement('div');
    head.className = 'thread-draft-head';
    const tag = document.createElement('span');
    tag.className = 'thread-draft-tag';
    tag.textContent = d.remote ? '⏱ Scheduled in ' + (d.provider_label || 'Outlook') + ' · ' + fullOf(d.send_at)
      : d.send_state === 'scheduled' ? '⏱ Scheduled ' + fullOf(d.send_at) : d.send_state === 'failed' ? 'Not sent' : 'Draft';
    const who = document.createElement('span');
    who.className = 'thread-draft-who';
    who.textContent = (d.mine ? '' : d.author + ' · ') + (d.to.length ? 'To: ' + d.to.join(', ') : '(no recipients)');
    head.append(tag, who);
    if (!d.remote) {
      const when = document.createElement('span');
      when.className = 'thread-draft-when';
      when.textContent = (d.editing ? d.editing + ' is editing · ' : '') + 'saved ' + fullOf(d.updated_at);
      head.appendChild(when);
    }
    const text = document.createElement('div');
    text.className = 'thread-draft-text';
    text.textContent = d.preview || '(empty)';
    if (d.send_error) text.title = d.send_error;
    const actions = document.createElement('div');
    actions.className = 'thread-draft-actions';
    if (d.remote) {
      const open = smallButton('Open in ' + (d.provider_label || 'Outlook'), () => {
        if (d.web_link) window.open(d.web_link, '_blank', 'noopener');
        else toast('Open it in ' + (d.provider_label || 'your mailbox') + ' to change it: it is scheduled there.');
      });
      open.classList.add('primary');
      actions.appendChild(open);
      card.title = 'Sent by ' + (d.provider_label || 'Outlook') + ' at that time, even with this app closed.';
    } else {
      const edit = smallButton(d.editing ? 'Read along' : 'Edit', () => openCaseComposer('app.php?composer_only=1&draft=' + d.id));
      edit.classList.add('primary');
      actions.appendChild(edit);
      if (d.mine) {
        const del = smallButton('Delete', () => {
          const go = async () => {
            try {
              await api('draft_discard', {}, { method: 'POST', body: { draft_id: d.id } });
              card.remove();
              toast('Draft deleted');
            } catch (e) { toast(e.message, true); }
          };
          if (window.ecwChoice) window.ecwChoice(del, [{ label: d.send_state === 'scheduled' ? 'Delete the scheduled email' : 'Delete draft', danger: true, onPick: go }, { label: 'Cancel', onPick: () => {} }]);
          else go();
        });
        actions.appendChild(del);
      }
      card.addEventListener('dblclick', () => openCaseComposer('app.php?composer_only=1&draft=' + d.id));
    }
    card.append(head, text);
    if ((d.files || []).length) card.appendChild(draftFileLinks(d));
    card.appendChild(actions);
    return card;
  }

  /** A draft's files as attachment links: the preview opens them (filePreview.js), like an email's. */
  function draftFileLinks(d) {
    const wrap = document.createElement('div');
    wrap.className = 'message-attachments thread-draft-files';
    d.files.forEach((f) => {
      const link = document.createElement('a');
      link.className = 'attachment';
      link.href = 'api/index.php?action=draft_file&draft_id=' + d.id + '&file=' + encodeURIComponent(f.id);
      const icon = window.ecwFileIcon ? window.ecwFileIcon(f.filename, f.mime_type)
        : Object.assign(document.createElement('i'), { className: 'fa fa-paperclip attachment-marker' });
      icon.setAttribute('aria-hidden', 'true');
      const name = document.createElement('span'); name.textContent = f.filename || 'attachment';
      link.append(icon, name);
      link.dataset.name = f.filename || 'attachment';
      link.dataset.mime = f.mime_type || '';
      link.dataset.size = String(f.size || 0);
      wrap.appendChild(link);
    });
    return wrap;
  }

  function smallButton(label, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', () => onClick(button));
    return button;
  }

  function openThreadLink(column) {
    const link = document.createElement(column.can_write === false ? 'span' : 'a');
    if (column.can_write === false) { link.textContent = 'Shared thread'; return link; }
    link.textContent = 'Open thread';
    link.href = 'app.php?thread=' + column.thread_id + '&mailbox=' + column.mailbox_id;
    return link;
  }

  function railCell(event, row) {
    const cell = document.createElement('div');
    cell.className = 'tl-rail';
    cell.style.gridRow = String(row);
    cell.style.gridColumn = '1';

    const time = document.createElement('div');
    time.className = 'tl-rail-time';
    time.textContent = timeOf(event.occurred_at);

    const date = document.createElement('div');
    date.className = 'tl-rail-date';
    date.textContent = dateOf(event.occurred_at);

    cell.append(time, date);
    return cell;
  }

  /** The exact elapsed time, always printed; only the HEIGHT is compressed. */
  function gapRow(gap, occurredAt, row) {
    const cell = document.createElement('div');
    cell.className = 'tl-gap size-' + gap.size + (gap.new_day ? ' new-day' : '');
    cell.style.gridRow = String(row);

    const label = document.createElement('span');
    label.className = 'tl-gap-label';
    label.textContent = gap.new_day ? gap.label + ' · ' + dateOf(occurredAt) : gap.label;
    cell.appendChild(label);
    return cell;
  }

  function bubbleCell(event, row, column) {
    const bubble = event.bubble;

    const cell = document.createElement('div');
    // Direction decides which side of the column the bubble hugs — the flex
    // alignment that makes a column read as a conversation, not a card list.
    cell.className = 'tl-cell dir-' + bubble.direction;
    cell.dataset.threadId = String(event.thread_id);
    cell.style.gridRow = String(row);
    cell.style.gridColumn = String(column);


    const article = document.createElement('article');
    if (bubble.unread) seenUnread.add(event.message_id);
    const isNew = seenUnread.has(event.message_id);
    article.className = 'bubble ' + bubble.direction + (bubble.removed ? ' removed' : '') + (isNew ? ' unread' : '');
    article.id = 'msg-' + event.message_id;


    const head = document.createElement('div');
    head.className = 'bubble-head';

    const from = document.createElement('div');
    from.className = 'bubble-from';
    from.textContent = bubble.from.name || bubble.from.email || '(unknown)';

    const email = document.createElement('div');
    email.className = 'bubble-email';
    email.textContent = bubble.from.email || '';

    const when = document.createElement('div');
    when.className = 'bubble-time';
    when.textContent = timeOf(event.occurred_at) + ' · ' + dateOf(event.occurred_at);

    const avatar = document.createElement('span');
    avatar.className = 'sender-avatar';
    avatar.textContent = window.ecwInitials ? window.ecwInitials(bubble.from.name, bubble.from.email) : '?';
    head.append(avatar, from, email, when);

    // To / Cc / Bcc: names; a click on one shows its address (ui-helpers.js ecwAddressList).
    const groups = [['To', bubble.to], ['Cc', bubble.cc], ['Bcc', bubble.bcc]].filter(([, list]) => list && list.length);
    if (groups.length) {
      const line = document.createElement('div');
      line.className = 'message-meta';
      groups.forEach(([label, list], i) => {
        if (i > 0) line.appendChild(document.createTextNode('  ·  '));
        line.appendChild(document.createTextNode(label + ': '));
        line.appendChild(window.ecwAddressList ? window.ecwAddressList(list) : document.createTextNode(list.map((r) => r.name || r.email).join(', ')));
      });
      head.appendChild(line);
    }

    article.appendChild(head);

    // Structured reply reference — a different thing from quoted history, and
    // shown separately on purpose.
    if (bubble.reply_to) {
      article.appendChild(replyReference(bubble.reply_to));
    }

    const body = document.createElement('div');
    body.className = 'bubble-body message-body';
    // SANITIZED SERVER-SIDE (HTMLPurifier).
    body.innerHTML = bubble.body_html
      || (bubble.body_state === 'not_fetched'
        ? '<span class="muted">Message body not downloaded yet.</span>'
        : '<span class="muted">(no content)</span>');
    article.appendChild(body);
    window.ecwTranslate?.attach(body, event.message_id);
    installBubbleClamp(body, article);

    if (bubble.signature_html) {
      const signature = document.createElement('details');
      signature.className = 'message-signature';
      const summary = document.createElement('summary');
      summary.textContent = 'Signature';
      const content = document.createElement('div');
      content.className = 'signature-content';
      // SANITIZED SERVER-SIDE (HTMLPurifier).
      content.innerHTML = bubble.signature_html;
      signature.append(summary, content);
      article.appendChild(signature);
    }

    if (bubble.has_quoted) {
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'bubble-quote-toggle';
      toggle.textContent = '...';
      toggle.title = 'Show previous email history';
      toggle.setAttribute('aria-label', 'Show previous email history');
      toggle.setAttribute('aria-expanded', 'false');

      const quoted = document.createElement('div');
      quoted.className = 'bubble-quoted message-quoted';
      quoted.hidden = true;

      toggle.addEventListener('click', async () => {
        if (quoted.hidden && !quoted.dataset.filled) {
          // The timeline comes without each email's history: fetch it when asked for.
          if (bubble.quoted_html == null) {
            const column = state.columns.find((c) => c.thread_id === event.thread_id);
            toggle.disabled = true; toggle.classList.add('is-loading');
            try {
              const detail = await api('body', { mailbox_id: column?.mailbox_id, message_id: event.message_id, images: 1 });
              bubble.quoted_html = ((detail.messages || []).find((m) => m.id === event.message_id) || {}).quoted_html || '';
            } catch (e) { toast(e.message, true); return; }
            finally { toggle.disabled = false; toggle.classList.remove('is-loading'); }
          }
          // SANITIZED SERVER-SIDE (HTMLPurifier).
          quoted.innerHTML = bubble.quoted_html || '';
          quoted.dataset.filled = '1';
        }
        quoted.hidden = !quoted.hidden;
        toggle.setAttribute('aria-expanded', String(!quoted.hidden));
        toggle.title = quoted.hidden ? 'Show previous email history' : 'Hide previous email history';
        toggle.setAttribute('aria-label', toggle.title);
      });

      article.append(toggle, quoted);
    }

    // The files go above the text (under the header, before the Translate bar), like Outlook.
    const aboveText = (node) => { const tr = body.previousElementSibling; (tr && tr.classList.contains('tr-bar') ? tr : body).before(node); };
    // Files still being fetched (the text is already here): say so where they will appear.
    if (bubble.attachments_pending && !(bubble.attachments || []).some((a) => !a.is_inline)) {
      const row = document.createElement('div');
      row.className = 'bubble-attachments message-attachments attachments-loading';
      row.setAttribute('role', 'status');
      const spin = document.createElement('span'); spin.className = 'ai-spin'; spin.setAttribute('aria-hidden', 'true');
      const icon = document.createElement('i'); icon.className = 'fa fa-paperclip'; icon.setAttribute('aria-hidden', 'true');
      row.append(spin, icon, document.createTextNode(' Loading attachments…'));
      aboveText(row);
    }

    if (bubble.attachments && bubble.attachments.length) {
      const wrap = document.createElement('div');
      wrap.className = 'bubble-attachments message-attachments';
      bubble.attachments.forEach((attachment) => {
        const link = document.createElement('a');
        link.className = 'attachment';
        link.href = 'api/index.php?action=attachment&id=' + attachment.id;
        const icon = window.ecwFileIcon ? window.ecwFileIcon(attachment.filename, attachment.mime_type)
          : Object.assign(document.createElement('i'), { className: 'fa fa-paperclip attachment-marker' });
        icon.setAttribute('aria-hidden', 'true');
        const filename = document.createElement('span'); filename.textContent = attachment.filename || 'attachment';
        link.append(icon, filename);
        link.dataset.name = attachment.filename || 'attachment';
        link.dataset.mime = attachment.mime_type || '';
        link.dataset.size = String(attachment.size || 0);
        const size = document.createElement('span');
        size.className = 'size';
        size.textContent = formatSize(attachment.size);
        link.appendChild(size);
        wrap.appendChild(link);
      });
      aboveText(wrap);
    }

    const actions = document.createElement('div');
    actions.className = 'bubble-actions';
    actions.append(
      replyButton('Reply', 'reply', event),
      replyButton('Reply all', 'reply_all', event),
      replyButton('Forward', 'forward', event)
    );
    if (bubble.web_link) {
      const original = document.createElement('a');
      original.href = bubble.web_link;
      original.target = '_blank';
      original.rel = 'noopener noreferrer';
      original.textContent = 'Original';
      actions.appendChild(original);
    }
    // 💬 at the bottom right: this email's internal notes (case-panel.js opens them).
    if (window.ecwNoteBubble) {
      actions.appendChild(window.ecwNoteBubble(event.message_id, (id) => document.dispatchEvent(new CustomEvent('ecw:notes-open', { detail: {
        message_id: id, from: event.bubble.from?.name || event.bubble.from?.email || '', at: event.occurred_at,
      } }))));
    }
    article.appendChild(actions);

    cell.appendChild(article);
    return cell;
  }

  // Only really long emails are folded, and then to a height that still shows a
  // good part, with a fade so it is clear there is more. Measured again whenever
  // the body changes size (body loaded later, pictures, fonts, a narrower
  // column), so text is never cut off without the button to show it.
  const CLAMP_FROM = 420;   // px of content before an email is folded at all
  function installBubbleClamp(body, article) {
    const toggle = smallButton('Show the whole email', () => {
      const expanded = toggle.getAttribute('aria-expanded') !== 'true';
      setExpanded(expanded);
      if (!expanded) body.closest('.bubble')?.scrollIntoView({ block: 'nearest' });
    });
    toggle.className = 'bubble-expand';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.hidden = true;
    article.appendChild(toggle);
    const setExpanded = (expanded) => {
      toggle.setAttribute('aria-expanded', String(expanded));
      toggle.textContent = expanded ? 'Show less' : 'Show the whole email';
      body.classList.toggle('is-collapsed', !expanded && body.scrollHeight > CLAMP_FROM);
    };
    const measure = () => {
      const long = body.scrollHeight > CLAMP_FROM;
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      body.classList.toggle('is-collapsed', long && !expanded);
      toggle.hidden = !long;
    };
    requestAnimationFrame(measure);
    // Images can acquire their height after the initial paint.
    body.addEventListener('load', measure, true);
    if (window.ResizeObserver) {
      // Watch the content, not the (clamped) box: its height changes while folded.
      const ro = new ResizeObserver(() => measure());
      ro.observe(body);
      for (const child of body.children) ro.observe(child);
      new MutationObserver(() => { for (const child of body.children) ro.observe(child); measure(); }).observe(body, { childList: true });
    }
  }

  function replyReference(reference) {
    const box = document.createElement(reference.in_timeline ? 'button' : 'div');
    box.className = 'reply-ref' + (reference.in_timeline ? ' clickable' : '');
    if (reference.in_timeline) {
      box.type = 'button';
      box.addEventListener('click', () => scrollToMessage(reference.id));
    }

    const label = document.createElement('span');
    label.textContent = 'Replying to: ';
    const who = document.createElement('span');
    who.className = 'who';
    who.textContent = (reference.from || 'unknown') + ' · ' + timeOf(reference.occurred_at);
    const snippet = document.createElement('span');
    snippet.className = 'snippet';
    snippet.textContent = reference.snippet ? '“' + reference.snippet + '”' : '';

    box.append(label, who, snippet);
    return box;
  }

  function scrollToMessage(messageId) {
    const target = document.getElementById('msg-' + messageId);
    if (!target) return;
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    target.classList.add('target');
    setTimeout(() => target.classList.remove('target'), 2000);
  }

  // ------------------------------------------------------------ case composer
  //
  function openCaseComposer(url) {
    let panel = document.getElementById('caseComposerWindow');
    if (panel) { panel.querySelector('iframe').focus(); return; }
    panel = document.createElement('section'); panel.id = 'caseComposerWindow';
    const header = document.createElement('header'); header.textContent = 'Message';
    const close = smallButton('×', () => {
      if (!panel.dataset.ready) { panel.remove(); return; }
      // Ask the shared composer to save before closing.
      panel.querySelector('iframe').contentWindow.postMessage({type:'ecw-request-close'}, window.location.origin);
    });
    close.setAttribute('aria-label', 'Close composer'); header.appendChild(close);
    const frame = document.createElement('iframe'); frame.src = url; frame.title = 'Compose reply or forward';
    // Until the composer inside is filled (To, Cc, text): "Opening…", not a white box.
    const loading = document.createElement('div'); loading.className = 'case-composer-loading'; loading.setAttribute('role', 'status');
    loading.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> Opening the reply…';
    panel.append(header, frame, loading); document.body.appendChild(panel);
    setTimeout(() => loading.remove(), 20000);   // never stuck over the composer
    window.ecwFloatingWindow(panel, header);
  }
  // The case review's "Update the customer" / "Chase the supplier" (case-review.js).
  window.ecwCaseReply = (messageId, threadId, mailboxId) => openCaseComposer('app.php?composer_only=1&reply=' + Number(messageId) + '&mode=reply'
    + '&thread=' + Number(threadId) + '&mailbox=' + Number(mailboxId));
  window.addEventListener('message', event => {
    const panel = document.getElementById('caseComposerWindow');
    if (!panel || event.origin !== window.location.origin || event.source !== panel.querySelector('iframe').contentWindow) return;
    if (event.data?.type === 'ecw-composer-ready') panel.dataset.ready = '1';
    if (event.data?.type === 'ecw-composer-shown') panel.querySelector('.case-composer-loading')?.remove();
    if (['ecw-composer-close','ecw-composer-sent'].includes(event.data?.type)) {
      panel.remove();
      setTimeout(load, 800);   // the draft saved, scheduled or sent: its column shows it as it is now
      if (event.data.type === 'ecw-composer-sent') {
        const draftId = Number(event.data.draft_id) || 0;
        const wait = Number(event.data.undo_seconds) || 0;
        // The email leaves after the Undo window; refresh once it has.
        setTimeout(load, wait * 1000 + 5000);
        if (draftId) {
          toast(wait ? 'Sending in ' + wait + ' s' : 'Scheduled for ' + fullOf(event.data.send_at) + (event.data.held_in_outlook ? ' · Outlook sends it, even if this PC is off' : ''), false, {
            label: wait ? 'Undo' : 'Cancel',
            onClick: async () => {
              try {
                const r = await api('send_cancel', {}, { method: 'POST', body: { draft_id: draftId } });
                if (!r.cancelled) { toast('Too late — it was already sent', true); return; }
                if (wait) openCaseComposer('app.php?composer_only=1&draft=' + draftId);
                else toast('Schedule cancelled — it is in Drafts');
              } catch (e) { toast(e.message, true); }
            },
          });
        }
      }
    }
  });

  function replyButton(label, mode, event) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    const sourceColumn = state.columns.find(c => c.thread_id === event.thread_id);
    if (sourceColumn?.can_write === false) {
      button.disabled = true;
      button.title = 'Only the mailbox owner can send from this mailbox.';
    }
    // Replying happens in the mail UI, which owns the composer. One composer,
    // one set of reply rules, rather than a second copy living here.
    button.addEventListener('click', () => {
      const column = state.columns.find(c => c.thread_id === event.thread_id);
      if (!column) return;
      openCaseComposer('app.php?composer_only=1&reply=' + event.message_id + '&mode=' + mode
        + '&thread=' + event.thread_id + '&mailbox=' + column.mailbox_id);
    });
    return button;
  }

  // ---------------------------------------------------------- column actions

  function renderColumnToggles(columns) {
    const box = $('columnToggles');
    box.textContent = '';
    $('columnsSummary').textContent = 'Columns (' + columns.filter((c) => !c.is_hidden).length + '/' + columns.length + ')';

    columns.forEach((column) => {
      const label = document.createElement('label');
      label.className = 'column-toggle';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = !column.is_hidden;
      input.addEventListener('change', () => setColumnHidden(column, !input.checked));
      const text = document.createElement('span');
      text.textContent = column.label;
      label.append(input, text);
      box.appendChild(label);
    });

    if (!columns.length) {
      box.textContent = 'No threads linked yet.';
    }
  }

  function renderThreadPicker(columns) {
    const select = $('threadPickerSelect');
    select.textContent = '';
    const visible = columns.filter((c) => !c.is_hidden);
    $('threadPicker').hidden = !state.mobile;
    const all = document.createElement('option'); all.value = 'all'; all.textContent = 'All threads · swipe to compare'; select.appendChild(all);

    visible.forEach((column) => {
      const option = document.createElement('option');
      option.value = column.thread_id;
      option.textContent = column.label + ' (' + column.message_count + ')';
      select.appendChild(option);
    });

    // Narrow screens: with several conversations, show them all side by side and
    // let the reader swipe between them; the picker can still focus on one.
    if (state.mobile && visible.length && state.activeThreadId === null) {
      state.activeThreadId = visible.length > 1 ? 'all' : visible[0].thread_id;
    } else if (state.mobile && visible.length && state.activeThreadId !== 'all' && !visible.some(c => String(c.thread_id) === String(state.activeThreadId))) {
      state.activeThreadId = visible[0].thread_id;
    }
    if (state.activeThreadId) select.value = String(state.activeThreadId);
  }

  async function setColumnHidden(column, hidden) {
    try {
      await updateColumn(column, { is_hidden: hidden });
      await load();
    } catch (e) { toast(e.message, true); }
  }

  async function renameColumn(column) {
    const name = window.prompt('Column label', column.label);
    if (name === null) return;
    try {
      await updateColumn(column, { display_name: name });
      await load();
    } catch (e) { toast(e.message, true); }
  }

  async function unlinkColumn(column, button) {
    // Two-step, no popup: the button turns red first (the email thread or the chat itself is never deleted).
    if (button && window.ecwArm && !window.ecwArm(button, 'Click again to unlink')) return;
    try {
      if (column.unlink_action) await api(column.unlink_action, { case_id: state.caseId }, { method: 'POST', body: { column: column.thread_id } });
      else await api('case_unlink', { mailbox_id: column.mailbox_id }, { method: 'POST', body: { thread_id: column.thread_id } });
      toast(column.unlink_action ? 'Taken out of the case' : 'Thread unlinked');
      await load();
    } catch (e) { toast(e.message, true); }
  }

  // ------------------------------------------------------------- add thread

  async function searchThreads(term) {
    const results = $('threadResults');
    results.textContent = '';
    try {
      const data = await api('case_linkable_threads', { q: term || '' });
      data.threads.forEach((thread) => {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';

        const title = document.createElement('div');
        title.textContent = thread.normalized_subject || '(no subject)';
        const meta = document.createElement('div');
        meta.className = 'muted';
        meta.textContent = (thread.latest_from_name || thread.latest_from_email || '')
          + ' · ' + thread.message_count + ' messages · ' + fullOf(thread.latest_message_at)
          + ' · ' + thread.mailbox;

        button.append(title, meta);
        button.addEventListener('click', () => linkThread(thread));
        li.appendChild(button);
        results.appendChild(li);
      });

      if (!data.threads.length) {
        const li = document.createElement('li');
        li.className = 'muted';
        li.textContent = 'No unlinked thread matches. A thread already in another case cannot be added.';
        results.appendChild(li);
      }
    } catch (e) { toast(e.message, true); }
  }

  async function linkThread(thread) {
    try {
      await api('case_link', { mailbox_id: thread.mailbox_id },
        { method: 'POST', body: { thread_id: thread.id, case_id: state.caseId } });
      $('addOverlay').hidden = true;
      toast('Thread added to case');
      await load();
    } catch (e) { toast(e.message, true); }
  }

  async function loadMailboxesForNewThread() {
    try {
      const { mailboxes } = await api('mailboxes', {});
      const select = $('newThreadMailbox');
      select.textContent = '';
      mailboxes.forEach((mailbox) => {
        const option = document.createElement('option');
        option.value = mailbox.id;
        option.textContent = mailbox.email_address;
        select.appendChild(option);
      });
    } catch (e) { toast(e.message, true); }
  }

  // ------------------------------------------------------------------ wiring

  async function loadOwners() {
    try {
      const { users } = await api('org_users', {});
      for (const [id, field] of [['caseOwner', 'owner_user_id'], ['caseHandler', 'handler_user_id']]) {
        const select = $(id);
        if (!select) continue;
        users.filter(u => !u.disabled_at).forEach(u => {
          const option = document.createElement('option');
          option.value = u.id; option.textContent = u.name || u.email;
          select.appendChild(option);
        });
        select.value = state.summary?.[field] || '';
      }
      document.dispatchEvent(new CustomEvent('ecw:org-users', { detail: users }));
    } catch (e) { /* the owner dropdown is not worth a toast */ }
  }

  async function updateCase(changes) {
    try {
      const data = await api('case_update', { case_id: state.caseId }, { method: 'POST', body: changes });
      state.summary = data.summary;
      renderSummary(data.summary);
      toast('Case updated');
    } catch (e) { toast(e.message, true); }
  }

  // ---------------------------------------------------- rename (task 07)

  function startRename() {
    const nameEl = $('caseName');
    nameEl.contentEditable = 'true';
    nameEl.focus();
    const range = document.createRange();
    range.selectNodeContents(nameEl);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  async function commitRename(nameEl) {
    const previous = state.summary.name;
    const name = nameEl.textContent.trim();
    if (name === previous) {
      nameEl.textContent = previous;
      return;
    }
    if (name.length < 1 || name.length > 120) {
      toast(name.length < 1 ? 'Case name cannot be empty' : 'Case name must be 120 characters or fewer', true);
      nameEl.textContent = previous;
      return;
    }

    nameEl.textContent = name; // optimistic
    try {
      const data = await api('case_rename', { case_id: state.caseId }, { method: 'POST', body: { name: name } });
      state.summary.name = data.name;
      nameEl.textContent = data.name;
      document.title = state.summary.number + ' — ' + data.name;
      toast('Case renamed');
    } catch (e) {
      nameEl.textContent = previous; // rollback
      toast(e.message, true);
    }
  }

  // ---------------------------------------------------- delete (task 07)

  function openDeleteDialog() {
    const summary = state.summary;
    if (!summary) return;
    $('deleteCaseBody').textContent = 'Delete "' + summary.name + '"? It contains '
      + summary.thread_count + ' thread' + (summary.thread_count === 1 ? '' : 's') + '.';
    $('deleteCaseOverlay').hidden = false;
  }

  async function confirmDelete() {
    const summary = state.summary;
    try {
      await api('case_delete', { case_id: state.caseId }, { method: 'POST' });
    } catch (e) {
      $('deleteCaseOverlay').hidden = true;
      toast(e.message, true);
      return;
    }
    $('deleteCaseOverlay').hidden = true;
    // The case is closing (this page IS the deleted case), so the toast with
    // Undo has to survive the navigation — cases.php picks this flag up on load.
    try {
      window.sessionStorage.setItem('ecw.caseDeleted', JSON.stringify({
        caseId: state.caseId, name: summary.name, expiresAt: Date.now() + 8000,
      }));
    } catch (e) { /* sessionStorage unavailable (private mode etc.) — undo just won't be offered */ }
    window.location.href = 'cases.php';
  }

  // ------------------------------- rename (pencil by the name) and delete icon

  function wire() {
    $('caseStatus').addEventListener('change', (e) => { paintStatus(); updateCase({ status: e.target.value }); });
    $('caseHandler')?.addEventListener('change', (e) => updateCase({ handler_user_id: e.target.value || null }));
    $('caseOwner').addEventListener('change', (e) => updateCase({ owner_user_id: e.target.value || null }));

    const nameEl = $('caseName');
    nameEl.addEventListener('dblclick', startRename);
    nameEl.addEventListener('blur', () => {
      if (nameEl.contentEditable !== 'true') return;
      nameEl.contentEditable = 'false';
      void commitRename(nameEl);
    });
    nameEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); }
      if (e.key === 'Escape') { nameEl.textContent = state.summary.name; nameEl.blur(); }
    });

    $('caseRenameBtn').addEventListener('click', startRename);
    // Mousedown would blur the name first; keep the edit open while clicking the pencil.
    $('caseRenameBtn').addEventListener('mousedown', (e) => { if (nameEl.contentEditable === 'true') e.preventDefault(); });
    $('caseDeleteBtn').addEventListener('click', openDeleteDialog);
    $('deleteCaseCancel').addEventListener('click', () => { $('deleteCaseOverlay').hidden = true; });
    $('deleteCaseConfirm').addEventListener('click', () => { void confirmDelete(); });

    setInterval(() => {
      if (!document.hidden && document.activeElement?.contentEditable !== 'true' && !document.querySelector('.tl-divider.dragging')) void load();
    }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void load(); });

    $('addThreadBtn').addEventListener('click', () => {
      $('addOverlay').hidden = false;
      $('threadSearch').value = '';
      searchThreads('');
      loadMailboxesForNewThread();
    });
    $('addCancel').addEventListener('click', () => { $('addOverlay').hidden = true; });
    $('threadSearch').addEventListener('input', (e) => searchThreads(e.target.value));

    document.querySelectorAll('.tab').forEach((tab) => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t === tab));
        document.querySelectorAll('.tab-body').forEach((body) => {
          body.hidden = body.dataset.body !== tab.dataset.tab;
        });
      });
    });

    $('startConversationBtn').addEventListener('click', () => {
      const mailboxId = $('newThreadMailbox').value;
      if (!mailboxId) { toast('Connect a mailbox first', true); return; }
      window.location.href = 'app.php?compose_case=' + state.caseId + '&mailbox=' + mailboxId;
    });

    $('caseAssistantBtn')?.addEventListener('click', () => {
      const column = state.columns.find(c => Number(c.thread_id) === Number(state.activeThreadId)) || state.columns[0];
      if (!column) { toast('Add a thread to this case first.', true); return; }
      window.ecwOpenAssistant({mailboxId:Number(column.mailbox_id), mode:'ask', query:'case:' + state.summary.number});
    });
    $('threadPickerSelect').addEventListener('change', async (e) => {
      // Swiping view already open: jump to that conversation's column instead of reloading.
      if (state.activeThreadId === 'all' && e.target.value !== 'all') {
        const head = document.querySelector('.tl-head[data-thread-id="' + e.target.value + '"]');
        if (head) { head.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' }); e.target.value = 'all'; return; }
      }
      // A thread id, or a module column's key ("chat:12").
      state.activeThreadId = e.target.value === 'all' ? 'all' : (/^\d+$/.test(e.target.value) ? Number(e.target.value) : e.target.value);
      $('timelineScroll').scrollTop = 0; $('timelineScroll').scrollLeft = 0;
      await load();
    });

    // A width change flips between the column grid and the single-thread view,
    // and the single-thread view needs a different timeline from the server.
    window.matchMedia('(max-width: 860px)').addEventListener('change', async (e) => {
      state.mobile = e.matches;
      if (!state.mobile) state.activeThreadId = null;
      await load();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        $('addOverlay').hidden = true;
        $('deleteCaseOverlay').hidden = true;
      }
    });
  }

  wire();
  load().then(loadOwners);
  // case-panel.js: a mention added someone to the case, so "Shared with" changed.
  document.addEventListener('ecw:case-reload', () => { void load(); });
})();
