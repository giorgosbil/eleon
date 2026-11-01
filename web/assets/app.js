/**
 * Email Case Workspace — mail UI.
 *
 * Vanilla JS, no build step, matching the rest of this repo. The only third
 * party is Quill, vendored locally, and only for the composer body.
 *
 * XSS POSTURE
 * -----------
 * Everything derived from mail is written with textContent, which cannot
 * execute. Message bodies, signatures and quoted blocks use HTML that the
 * server already ran through HTMLPurifier (lib/sanitize.php). Each HTML
 * assignment is marked below and must preserve that guarantee.
 *
 * SCROLLING
 * ---------
 * The thread list pages on a cursor, not an offset, and appends. An IntersectionObserver
 * on the footer loads the next page, so the list never holds more than what has
 * actually been scrolled past — the step's "do not render 20,000 emails at once".
 */

(function () {
  'use strict';

  /** This page is a composer popped out into its own window (popOutComposer). */
  const isPopout = new URLSearchParams(location.search).has('popout');
  const state = {
    csrf: document.body.dataset.csrf,
    mailboxId: null,
    folderId: null,
    folderType: 'inbox',
    listTitle: 'Inbox',
    query: '',
    filters: new Set(),
    cursor: null,
    loading: false,
    exhausted: false,
    threadId: null,
    thread: null,
    prefs: { thread_order: 'asc' },
    draftId: null,
    composeMode: 'new',
    replyToMessageId: null,
    // Set when the composer was opened from a case: the draft carries it, and
    // the send path links the resulting thread to that case.
    composeCaseId: null,
    showImages: true,
    // Live-update baselines: opaque strings compared against the server, never
    // interpreted here.
    liveSignature: null,
    liveThread: null,
  };

  let quill = null;
  let sigQuill = null;
  let draftTimer = null;
  let draftSaveChain = Promise.resolve();
  let sending = false;
  let uploading = false;
  let composerMailboxId = null;
  let composerThreadId = null;   // the conversation a reply / forward belongs to (draft tabs reopen it beside the draft)
  let composerOpening = false;
  let draftUnsaved = false;   // changed since the last autosave (scheduleDraftSave / saveDraft)
  let composeQuote = "";
  let quoteQuill = null, quoteChanged = false, composeSignature = "", signatureProfiles = [];
  // Writing together: the newest email the writer has seen (a newer one at Send
  // asks first), and the shared-draft state of the open draft.
  let composeSeenLatest = 0;
  const draftShare = { role: 'owner', shared: false, canSend: true, version: null, readOnly: false, timer: null };

  const $ = (id) => document.getElementById(id);

  // ------------------------------------------------------------------ api

  async function api(action, params, options) {
    options = options || {};
    const url = new URL('api/index.php', window.location.href);
    url.searchParams.set('action', action);
    if (state.mailboxId && !options.noMailbox) {
      url.searchParams.set('mailbox_id', String(state.mailboxId));
    }
    Object.keys(params || {}).forEach((k) => {
      if (params[k] !== null && params[k] !== undefined) url.searchParams.set(k, String(params[k]));
    });

    const init = { method: options.method || 'GET', headers: {} };
    if (init.method !== 'GET') {
      init.headers['X-ECW-CSRF'] = state.csrf;
      if (options.form) {
        init.body = options.form;
      } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body || {});
      }
    }

    const response = await fetch(url.toString(), init);
    const text = await response.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: 'bad response' }; }

    if (!response.ok) {
      const err = new Error(data.error || ('request failed (' + response.status + ')'));
      err.status = response.status;
      err.data = data;   // e.g. a 409 with { collision } or { locked } (shared drafts)
      throw err;
    }
    return data;
  }

  function toast(message, isError) {
    const el = $('toast');
    el.textContent = message;
    el.classList.toggle('error', !!isError);
    el.hidden = false;
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.hidden = true; }, isError ? 6000 : 2800);
  }

  // -------------------------------------------------------------- formatting

  /** Today shows a time, this year a date, older a date with year. */
  function formatWhen(value) {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T') + 'Z');
    if (isNaN(date)) return value;
    const now = new Date();
    const sameDay = date.toDateString() === now.toDateString();
    if (sameDay) {
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    const opts = { day: '2-digit', month: 'short' };
    if (date.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
    return date.toLocaleDateString([], opts);
  }

  /**
   * Outlook-style groups in the email list: Today, Yesterday, the days of this
   * week, Last week, Earlier this month, Last month, then by month.
   */
  function dateGroup(value) {
    const d = new Date(String(value || '').replace(' ', 'T') + 'Z');
    if (isNaN(d)) return '';
    const now = new Date();
    const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate());
    const today = day(now), dd = day(d);
    const diff = Math.round((today - dd) / 86400000);
    if (diff <= 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    const weekStart = new Date(today); weekStart.setDate(today.getDate() - ((today.getDay() + 6) % 7));   // Monday
    if (dd >= weekStart) return d.toLocaleDateString([], { weekday: 'long' });
    const lastWeek = new Date(weekStart); lastWeek.setDate(weekStart.getDate() - 7);
    if (dd >= lastWeek) return 'Last week';
    if (dd.getFullYear() === today.getFullYear() && dd.getMonth() === today.getMonth()) return 'Earlier this month';
    if (dd >= new Date(today.getFullYear(), today.getMonth() - 1, 1)) return 'Last month';
    return d.toLocaleDateString([], dd.getFullYear() === today.getFullYear() ? { month: 'long' } : { month: 'long', year: 'numeric' });
  }

  // Date groups the person folded (click the header), remembered in this browser for every folder.
  const foldedGroups = new Set((() => { try { return JSON.parse(localStorage.getItem('ecw.foldedGroups') || '[]'); } catch (_) { return []; } })());

  /** The date group header before a row, when the row starts a new group. */
  // ------------------------------------------------------- search: what to highlight
  /**
   * The words and "phrases" of the search box, for highlighting: bare words, quoted phrases and
   * the values of from: to: cc: subject: (not other operators like has:, is:, after:).
   */
  function searchTerms() {
    const q = currentQuery();
    if (!q) return [];
    const out = [];
    const re = /(\w+):"([^"]*)"|(\w+):(\S+)|"([^"]*)"|(\S+)/gu;
    let m;
    while ((m = re.exec(q))) {
      const field = (m[1] || m[3] || '').toLowerCase();
      const value = m[2] ?? m[4] ?? m[5] ?? m[6] ?? '';
      if (field && !['from', 'to', 'cc', 'subject', 'participant', 'with'].includes(field)) continue;
      const v = value.replace(/^[-+]/, '').replace(/\*/g, '').trim();
      if (v.length >= 2) out.push(v);
    }
    return out;
  }
  /** Case and accents do not matter (Εκπαίδευση = εκπαιδευση, ς = σ), as in the search itself. */
  const foldChar = (c) => c.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/ς/g, 'σ');
  /** Put text into an element with the searched words in <mark> (text nodes only: no HTML). */
  function markInto(node, text, terms) {
    text = String(text || '');
    node.textContent = '';
    if (!terms || !terms.length || !text) { node.textContent = text; return; }
    // The folded text, with where each folded character came from.
    let folded = ''; const from = [];
    for (let i = 0; i < text.length; i++) { const f = foldChar(text[i]); for (let k = 0; k < f.length; k++) { folded += f[k]; from.push(i); } }
    const hits = [];
    terms.map((t) => [...t].map(foldChar).join('')).filter(Boolean).forEach((t) => {
      for (let at = folded.indexOf(t); at >= 0; at = folded.indexOf(t, at + t.length)) hits.push([from[at], from[at + t.length - 1] + 1]);
    });
    if (!hits.length) { node.textContent = text; return; }
    hits.sort((a, b) => a[0] - b[0]);
    let pos = 0;
    hits.forEach(([s, e]) => {
      if (e <= pos) return;
      s = Math.max(s, pos);
      if (s > pos) node.appendChild(document.createTextNode(text.slice(pos, s)));
      const mk = document.createElement('mark'); mk.className = 'search-mark'; mk.textContent = text.slice(s, e);
      node.appendChild(mk);
      pos = e;
    });
    if (pos < text.length) node.appendChild(document.createTextNode(text.slice(pos)));
  }
  /** "Top results" (lib/mail.php): conversations named with every searched word, above the dates. */
  function drawTopResults(into, top) {
    state.topIds = new Set((top || []).map((t) => Number(t.id)));
    if (!top || !top.length) return;
    const li = document.createElement('li');
    li.className = 'list-group list-group-top';
    li.setAttribute('role', 'presentation');
    const head = document.createElement('div');
    head.className = 'list-group-toggle';
    head.innerHTML = '<i class="fa fa-star" aria-hidden="true"></i><span>Top results</span>';
    li.appendChild(head);
    into.appendChild(li);
    top.forEach((t) => appendThreadRow(t, into, { top: true }));
    into._lastGroup = null; into._lastHeader = null;
  }

  function addGroupHeader(list, thread) {
    const g = dateGroup(thread.latest_at);
    if (!g || list._lastGroup === g) return;
    list._lastGroup = g;
    const li = document.createElement('li');
    li.className = 'list-group' + (foldedGroups.has(g) ? ' is-folded' : '');
    li.setAttribute('role', 'presentation');
    li.dataset.group = g;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'list-group-toggle';
    btn.setAttribute('aria-expanded', String(!foldedGroups.has(g)));
    btn.innerHTML = '<i class="fa fa-chevron-down" aria-hidden="true"></i><span></span><small class="list-group-count"></small>';
    btn.querySelector('span').textContent = g;
    li.appendChild(btn);
    list.appendChild(li);
    list._lastHeader = li;
  }

  /** Fold or unfold a date group: its rows hide (not `hidden`, which means "moved away" to the live refresh). */
  function toggleGroup(header) {
    const g = header.dataset.group, fold = !foldedGroups.has(g);
    if (fold) foldedGroups.add(g); else foldedGroups.delete(g);
    try { localStorage.setItem('ecw.foldedGroups', JSON.stringify([...foldedGroups])); } catch (_) {}
    $('threadList').querySelectorAll('li.list-group').forEach((h) => { if (h.dataset.group === g) paintGroup(h, fold); });
  }

  function paintGroup(header, fold) {
    header.classList.toggle('is-folded', fold);
    header.querySelector('.list-group-toggle')?.setAttribute('aria-expanded', String(!fold));
    let n = 0;
    for (let el = header.nextElementSibling; el && !el.classList.contains('list-group'); el = el.nextElementSibling) { el.classList.toggle('group-folded', fold); n++; }
    const count = header.querySelector('.list-group-count');
    if (count) count.textContent = fold && n ? String(n) : '';
  }

  /** How many conversations the list shows (not the date headers). */
  const rowCount = () => $('threadList').querySelectorAll('.thread-row').length;

  function formatFull(value) {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T') + 'Z');
    return isNaN(date) ? value : date.toLocaleString([], {
      day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  }

  /** The collapsed message-header line: just a time today, day+time otherwise. */
  function formatCompact(value) {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T') + 'Z');
    if (isNaN(date)) return value;
    const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const now = new Date();
    if (date.toDateString() === now.toDateString()) return time;
    return date.toLocaleDateString([], { day: '2-digit', month: 'short' }) + ' · ' + time;
  }

  function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  function addressLine(list) {
    return (list || []).map((r) => r.name || r.email).join(', ');
  }

  /**
   * A designed email (a newsletter, an invoice from a shop): laid out in tables of its own width
   * and colours. It keeps them (appearance.css .is-designed) instead of taking the conversation's
   * colours, which suit typed emails. A typed email with a signature table or two does not count.
   */
  function markDesigned(body) {
    // Tables inside tables are one layout (a signature in nested boxes is one table, not four).
    const tables = [...body.querySelectorAll('table')].filter((t) => !t.parentElement.closest('table'));
    const wide = [...tables].some((t) => Number(t.getAttribute('width')) >= 480 || /(^|;)\s*(max-)?width\s*:\s*(4[89]\d|[5-9]\d\d)px/i.test(t.getAttribute('style') || ''));
    const coloured = body.querySelector('[bgcolor], table[style*="background-color"], td[style*="background-color"]');
    if (tables.length >= 4 || (tables.length >= 2 && (wide || coloured))) body.classList.add('is-designed');
  }

  /** One labeled line inside the expanded message-head (From/To/Cc/Date/Note). */
  function metaLine(label, value) {
    const line = document.createElement('div');
    line.className = 'meta-line';
    const tag = document.createElement('span');
    tag.className = 'meta-label';
    tag.textContent = label;
    const val = document.createElement('span');
    val.className = 'meta-value';
    if (value instanceof Node) val.appendChild(value); else val.textContent = value || '—';
    line.append(tag, val);
    return line;
  }

  function parseAddresses(value) {
    return String(value || '')
      .split(/[,;]/)
      .map((s) => s.trim())
      .filter(Boolean)
      .map((s) => {
        const match = s.match(/<([^>]+)>/);
        return match ? { email: match[1].trim(), name: s.replace(/<[^>]*>/, '').trim() || null }
                     : { email: s, name: null };
      });
  }

  // ------------------------------------------------------------- boot

  async function boot() {
    try {
      const session = await api('session', {}, { noMailbox: true });
      state.csrf = session.csrf || state.csrf;
      state.prefs = session.preferences || state.prefs;
      $('threadOrder').value = state.prefs.thread_order || 'asc';

      const { mailboxes, active_id: activeId } = await api('mailboxes', {}, { noMailbox: true });
      const select = $('mailboxSelect');
      select.textContent = '';

      if (!mailboxes.length) {
        $('readingEmpty').textContent = 'No mailbox connected yet. Connect one from oauth/start.php.';
        return;
      }

      state.mailboxes = mailboxes;
      mailboxes.forEach((mb) => {
        const option = document.createElement('option');
        option.value = mb.id;
        // A shared mailbox (info@, sales@) the team works from together.
        option.textContent = (mb.is_shared ? '👥 ' : '') + mb.email_address;
        select.appendChild(option);
      });

      // The account chosen at the top of the page (lib/account.php); a link to an
      // email of another account (?mailbox=) already switched it on the server.
      const params = new URLSearchParams(window.location.search);
      const wantedMailbox = Number(params.get('mailbox') || activeId || 0);
      const known = mailboxes.some((mb) => Number(mb.id) === wantedMailbox);

      state.mailboxId = known ? wantedMailbox : Number(mailboxes[0].id);
      select.value = String(state.mailboxId);
      renderMailboxStatus(mailboxes.find((mb) => Number(mb.id) === state.mailboxId) || mailboxes[0]);
      mailboxChanged();
      await loadFolders();
      // Only the composer is shown in composer_only (a reply from a case): the list is never
      // seen there, and loading it first kept the composer white for that long.
      if (!params.has('composer_only')) await resetList();

      const asks = ['thread', 'reply', 'compose_case', 'draft', 'q'].some((k) => params.has(k));
      if (!asks) { try { await restoreListPos(); } catch (_) { /* the list is there anyway */ } }
      await handleEntryParams(params);
    } catch (e) {
      toast(e.message, true);
    } finally {
      // The case page shows "Opening…" over this frame until the composer is filled (case.js).
      if (window.parent && window.parent !== window) window.parent.postMessage({ type: 'ecw-composer-shown' }, window.location.origin);
    }
  }

  /**
   * The case view links back into the mail UI rather than carrying its own
   * composer and reading pane. These parameters are that hand-off.
   */
  async function handleEntryParams(params) {
    const threadId = Number(params.get('thread') || 0);
    if (threadId) {
      await openThread(threadId);
    }

    const replyTo = Number(params.get('reply') || 0);
    if (replyTo) {
      const mode = params.get('mode') || 'reply';
      await openComposer(['reply', 'reply_all', 'forward'].includes(mode) ? mode : 'reply', replyTo);
      // A text prepared elsewhere for this reply (the case review's update or chaser, case-review.js).
      let prefill = null;
      try { prefill = JSON.parse(sessionStorage.getItem('ecw.composePrefill') || 'null'); sessionStorage.removeItem('ecw.composePrefill'); } catch (e) { /* no storage */ }
      if (prefill && Number(prefill.reply) === replyTo && Date.now() - Number(prefill.at || 0) < 120000 && quill) {
        quill.insertText(0, String(prefill.text || '').slice(0, 5000) + '\n', 'user');
        quill.setSelection(0, 0);
      }
    }

    const caseId = Number(params.get('compose_case') || 0);
    if (caseId) {
      await openComposer('new', null, { caseId: caseId });
    }

    // A draft tab clicked on another page lands here.
    const draftId = Number(params.get('draft') || 0);
    if (draftId) {
      await openDraft(draftId);
      history.replaceState(null, '', location.pathname);
    }
  }

  /** The chosen mailbox changed: shared-inbox.js shows "Mine / Unassigned" for a shared one. */
  function mailboxChanged() {
    const mb = (state.mailboxes || []).find((m) => Number(m.id) === Number(state.mailboxId)) || null;
    state.mailboxShared = !!(mb && mb.is_shared);
    state.claim = '';
    document.dispatchEvent(new CustomEvent('ecw:mailbox-changed', { detail: mb }));
  }

  function renderMailboxStatus(mailbox) {
    const el = $('mailboxStatus');
    const needsAction = mailbox.sync_status === 'needs_reauth' || mailbox.sync_status === 'disconnected';
    el.textContent = mailbox.sync_status + (mailbox.status_message ? ' — ' + mailbox.status_message : '');
    el.classList.toggle('warn', needsAction);
  }

  // ----------------------------------------------------------- folders/nav

  async function loadFolders() {
    const { folders } = await api('folders', {});
    state.folderList = folders;   // the search box's "Search in" menu
    paintSearchScope();
    const system = $('systemFolders');
    const user = $('userFolders');
    system.textContent = '';
    user.textContent = '';

    const allItem = navItem('All Mail', null, () => selectFolder(null, null, 'All Mail'));
    system.appendChild(allItem);
    const snoozedItem = navItem('Snoozed', null, async () => { await selectFolder(null, null, 'Snoozed', true); });
    snoozedItem.querySelector('button').dataset.snoozed = '1';
    system.appendChild(snoozedItem);
    // The workspace's own drafts (saved here, reopened here). The provider's
    // Drafts folder is not synchronized, so it is left out to avoid two "Drafts".
    const draftsItem = navItem('Drafts', null, async () => { await selectDrafts(); });
    draftsItem.querySelector('button').dataset.drafts = '1';
    system.appendChild(draftsItem);

    folders.forEach((folder) => {
      // Drafts and Outlook's snooze folder have their own entries above ("Drafts", "Snoozed").
      if (folder.folder_type === 'drafts' || folder.folder_type === 'snoozed') return;
      const item = navItem(
        folder.name,
        Number(folder.unread_count) || 0,
        () => selectFolder(Number(folder.id), null, folder.name)
      );
      item.querySelector('button').dataset.folderId = folder.id;
      item.querySelector('button').dataset.folderType = folder.folder_type || '';   // mail-actions.js: Delete in Deleted Items is for good
      item.querySelector('button').dataset.system = String(Number(folder.is_system) === 1 ? 1 : 0);   // folder-manage.js
      item.querySelector('button').dataset.folderName = folder.name;
      // The icon follows the kind of folder, whatever Outlook / Gmail calls it ("Deleted Items", "Junk Email", "Spam").
      const typeIcon = { inbox: 'inbox', sent: 'paper-plane', archive: 'box-archive', deleted: 'trash-can', junk: 'ban' }[folder.folder_type];
      if (typeIcon) item.querySelector('.ui-icon').className = 'ui-icon fa fa-' + typeIcon;
      (Number(folder.is_system) === 1 ? system : user).appendChild(item);
    });

    highlightNav();
    if (state.sync) renderSyncActivity(state.sync);
  }

  function navItem(label, count, onClick) {
    const li = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-item';
    button.title = label;   // the label is hidden when the folders pane is collapsed to icons

    const name = document.createElement('span');
    name.textContent = label;
    const icon = document.createElement('span'); icon.className = 'ui-icon'; icon.setAttribute('aria-hidden','true');
    icon.className = 'ui-icon fa fa-' + (({Snoozed:'clock',Inbox:'inbox',Sent:'paper-plane','Sent Items':'paper-plane',Drafts:'file-pen',Archive:'box-archive',Deleted:'trash-can',Trash:'trash-can',Junk:'ban'})[label] || 'folder');
    name.prepend(icon); button.appendChild(name);

    if (count) {
      const badge = document.createElement('span');
      badge.className = 'count';
      badge.textContent = String(count);
      button.appendChild(badge);
    }

    button.addEventListener('click', onClick);
    li.appendChild(button);
    return li;
  }

  function highlightNav() {
    // Mail opens on the Inbox by its type (no folder picked yet): it is the Inbox that is lit.
    const inboxByType = state.folderId === null && state.folderType === 'inbox' && !state.snoozed && !state.draftsView;
    document.querySelectorAll('.nav-item').forEach((el) => {
      const id = el.dataset.folderId;
      el.classList.toggle('active', el.dataset.drafts === '1' ? !!state.draftsView
        : el.dataset.snoozed === '1' ? !!state.snoozed
        : inboxByType ? el.dataset.folderType === 'inbox' && !!el.closest('#systemFolders')
        : (!!id && Number(id) === state.folderId && !state.snoozed && !state.draftsView));
    });
  }

  /** The Drafts list: unsent drafts; clicking one reopens it in the composer. */
  async function selectDrafts() {
    document.body.classList.remove('reading-open');
    state.draftsView = true;
    state.snoozed = false;
    state.folderId = null;
    state.listTitle = 'Drafts';
    highlightNav();
    closeNavDrawer();
    $('threadList').textContent = '';
    $('listTitle').textContent = 'Drafts';
    $('listFooter').textContent = 'Loading';
    try {
      const { drafts } = await api('drafts', {}, { noMailbox: true });
      renderDraftRows(drafts);
    } catch (e) { $('listFooter').textContent = ''; toast(e.message, true); }
  }

  function renderDraftRows(drafts) {
    const list = $('threadList');
    list.textContent = '';
    drafts.forEach((d) => {
      const li = document.createElement('li');
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'thread-row draft-row';
      row._draft = d;   // mail-context.js: right-click menu for drafts
      const top = document.createElement('div');
      top.className = 'row-top';
      const to = document.createElement('span');
      to.className = 'row-from';
      to.textContent = d.to.length ? 'To: ' + d.to.join(', ') : '(no recipients)';
      const time = document.createElement('span');
      time.className = 'row-time';
      time.textContent = formatWhen(d.updated_at);
      top.append(to, time);
      const subject = document.createElement('div');
      subject.className = 'row-subject';
      const tag = document.createElement('span');
      tag.className = 'draft-tag' + (d.send_state === 'scheduled' ? ' scheduled' : d.send_state === 'failed' ? ' failed' : '');
      tag.textContent = d.send_state === 'scheduled' ? '⏱ ' + formatFull(d.send_at) : d.send_state === 'failed' ? 'Not sent' : 'Draft';
      if (d.held_in_outlook) tag.title = 'Scheduled in Outlook: it goes at its time even if this PC is off';
      // Not with Outlook yet (no connection when it was scheduled): only a running app sends it.
      else if (d.send_state === 'scheduled' && d.id) { tag.textContent += ' · not with Outlook yet'; tag.title = 'Handed to Outlook as soon as Outlook can be reached. Until then this PC must be on at its time.'; }
      if (d.from_mailbox_only) tag.textContent = d.scheduled_in_mailbox ? '⏱ ' + (d.provider_label || 'Mailbox') + ' · ' + formatFull(d.send_at) : 'In ' + (d.provider_label || 'mailbox');
      if (d.scheduled_in_mailbox) row.title = 'Scheduled in ' + (d.provider_label || 'your mailbox') + ': it is sent from there. Click to open it there.';
      subject.append(tag, document.createTextNode(' ' + (d.subject || '(no subject)')));
      const preview = document.createElement('div');
      preview.className = 'row-preview';
      preview.textContent = d.send_error ? d.send_error : (d.attachments ? '📎 ' : '') + (d.preview || '');
      if (d.in_mailbox) row.title = 'Also in your mailbox’s Drafts folder';
      row.append(top, subject, preview);
      row.addEventListener('click', async () => {
        if (!d.from_mailbox_only) { openDraft(d.id); return; }
        // Scheduled in Outlook: it is sent from there, so it is changed there too (never a second copy here).
        if (d.scheduled_in_mailbox) { openInMailbox(d); return; }
        // Started in Outlook / Gmail: bring it in (linked), then open it.
        try {
          const r = await api('draft_import', { mailbox_id: d.mailbox_id }, { method: 'POST', body: { provider_id: d.provider_id }, noMailbox: true });
          openDraft(r.draft_id);
        } catch (e) { toast(e.message, true); if (state.draftsView) selectDrafts(); }   // gone: the list drops it
      });
      li.appendChild(row);
      list.appendChild(li);
    });
    $('listCount').textContent = drafts.length ? String(drafts.length) : '';
    $('listFooter').textContent = drafts.length ? '' : 'No drafts. Emails you start and close are kept here.';
  }

  async function selectFolder(folderId, folderType, title, snoozed) {
    document.body.classList.remove('reading-open');
    state.draftsView = false;
    state.snoozed = !!snoozed;
    state.folderId = folderId;
    state.folderType = folderType;
    state.listTitle = title;
    state.query = '';
    state.filters.clear();
    $('searchInput').value = '';
    syncFilterChips();
    highlightNav();
    closeNavDrawer();
    await resetList();
  }

  // ------------------------------------------------------------ thread list

  // Where a search looks: every folder of the account (like Outlook), or the one folder picked
  // with the folder button inside the search box (searchScopeBtn). null = all folders.
  state.searchIn = null;
  const searchingAll = () => !!currentQuery() && state.searchIn === null && !state.snoozed;
  const listScope = () => {
    if (!currentQuery() || state.snoozed) return { folder_id: state.folderId, folder_type: state.folderType };
    return state.searchIn === null ? { folder_id: '', folder_type: '' } : { folder_id: state.searchIn, folder_type: '' };
  };
  const SCOPE_ICON = { inbox: 'fa-inbox', sent: 'fa-paper-plane', archive: 'fa-box-archive', deleted: 'fa-trash-can', junk: 'fa-ban' };
  function searchFolder() { return (state.folderList || []).find((f) => Number(f.id) === Number(state.searchIn)) || null; }
  function paintSearchScope() {
    const b = $('searchScopeBtn');
    if (!b) return;
    const f = state.searchIn === null ? null : searchFolder();
    if (state.searchIn !== null && !f) state.searchIn = null;   // that folder is gone (another account, deleted)
    const label = f ? f.name : 'All folders';
    b.querySelector('.fa').className = 'fa ' + (f ? (SCOPE_ICON[f.folder_type] || 'fa-folder') : 'fa-folder-tree');
    b.title = 'Search in: ' + label;
    b.setAttribute('aria-label', 'Search in: ' + label);
    b.classList.toggle('narrowed', !!f);
    $('searchInput').placeholder = f ? 'Search in ' + f.name : 'Search emails, people, or filenames';
  }
  function openSearchScopeMenu() {
    const menu = $('searchScopeMenu'), b = $('searchScopeBtn');
    menu.textContent = '';
    const head = document.createElement('div'); head.className = 'search-scope-head'; head.textContent = 'Search in';
    menu.appendChild(head);
    const item = (id, name, icon) => {
      const it = document.createElement('button');
      it.type = 'button'; it.setAttribute('role', 'menuitemradio');
      const on = id === null ? state.searchIn === null : Number(state.searchIn) === Number(id);
      it.setAttribute('aria-checked', String(on));
      it.className = 'search-scope-item' + (on ? ' on' : '');
      const i = document.createElement('i'); i.className = 'fa ' + icon; i.setAttribute('aria-hidden', 'true');
      it.append(i, document.createTextNode(' ' + name));
      it.addEventListener('click', async () => {
        state.searchIn = id === null ? null : Number(id);
        closeSearchScopeMenu();
        paintSearchScope();
        $('searchInput').focus();
        if (currentQuery()) await resetList();
      });
      menu.appendChild(it);
    };
    item(null, 'All folders', 'fa-folder-tree');
    (state.folderList || []).filter((f) => !['drafts', 'snoozed'].includes(f.folder_type))
      .forEach((f) => item(f.id, f.name, SCOPE_ICON[f.folder_type] || 'fa-folder'));
    menu.hidden = false;
    b.setAttribute('aria-expanded', 'true');
    menu.querySelector('.on')?.focus();
  }
  function closeSearchScopeMenu() {
    if ($('searchScopeMenu')) $('searchScopeMenu').hidden = true;
    $('searchScopeBtn')?.setAttribute('aria-expanded', 'false');
  }

  function currentQuery() {
    const parts = [];
    if (state.query) parts.push(state.query);
    state.filters.forEach((f) => parts.push(f));
    return parts.join(' ').trim();
  }

  async function resetList() {
    if (state.draftsView) {
      if (!state.query) return selectDrafts();   // live refreshes keep showing Drafts
      state.draftsView = false;                  // a search leaves the Drafts list
      highlightNav();
    }
    state.cursor = null;
    state.exhausted = false;
    $('threadList').textContent = '';
    $('threadList')._lastGroup = null;
    $('threadList')._lastHeader = null;
    $('listTitle').textContent = state.query ? 'Search' : state.listTitle;
    paintSearchScope();
    await loadMore();
    prefetchTop(3);   // the first conversations ready before the first click
  }

  async function loadMore() {
    if (state.draftsView) return;   // the Drafts list is one page, drawn by selectDrafts()
    if (state.loading || state.exhausted || !state.mailboxId) return;
    state.loading = true;
    $('listFooter').textContent = 'Loading'; $('listFooter').classList.add('is-loading');

    try {
      const data = await api('threads', {
        ...listScope(),
        q: currentQuery(),
        cursor: state.cursor,
        snoozed: state.snoozed ? 1 : '',
        claim: state.claim || '',
      });

      if (!state.cursor) drawTopResults($('threadList'), currentQuery() ? data.top : []);
      data.threads.forEach((t) => appendThreadRow(t));   // not forEach(appendThreadRow): its index would arrive as the target list
      state.cursor = data.next_cursor;
      state.exhausted = !data.next_cursor;

      const count = rowCount();
      $('listCount').textContent = count ? count + (state.exhausted ? '' : '+') : '';
      $('listFooter').textContent = count === 0 ? (state.exhausted ? 'Nothing here' : 'Searching older mail') : (state.exhausted ? '' : 'Scroll for more');
    } catch (e) {
      $('listFooter').textContent = '';
      toast(e.message, true);
      state.exhausted = true;   // no retry loop on an error; the next search or refresh starts again
    } finally {
      state.loading = false; $('listFooter').classList.remove('is-loading');
    }
    // A search over a common word reads older mail in batches (lib/mail.php), so
    // a page can come back short. The footer then stays in view and the
    // IntersectionObserver will not fire again by itself: keep going. Not into a
    // folded group, whose rows never fill the view (it would read the whole folder).
    if (!state.exhausted && listFooterInView() && !foldedGroups.has($('threadList')._lastGroup)) loadMore();
  }

  function listFooterInView() {
    const pane = $('listPane'), footer = $('listFooter');
    if (!pane || !footer) return false;
    return footer.getBoundingClientRect().top < pane.getBoundingClientRect().bottom + 200;
  }

  function appendThreadRow(thread, target, opts) {
    opts = opts || {};
    // In a search: Top results show once, above; elsewhere in the list they are left out.
    if (!opts.top && state.topIds && state.topIds.has(Number(thread.id)) && currentQuery()) return;
    const terms = searchTerms();
    const li = document.createElement('li');
    const row = document.createElement('button');
    row.type = 'button';
    row.className = 'thread-row' + (thread.unread ? ' unread' : '') + (thread.color ? ' colored' : '') + (thread.flagged ? ' flagged' : '');
    row.dataset.threadId = thread.id;
    row._thread = thread;   // mail-context.js (right-click menu) reads it
    if (thread.color) row.style.setProperty('--rule-color', thread.color);   // a personal colour rule matched

    const top = document.createElement('div');
    top.className = 'row-top';
    const from = document.createElement('span');
    from.className = 'row-from';
    markInto(from, thread.from_name || thread.from_email || '(unknown)', terms);
    const time = document.createElement('span');
    time.className = 'row-time';
    time.textContent = formatWhen(thread.latest_at);
    const flag = document.createElement('i');
    flag.className = 'fa fa-flag row-flag';
    flag.setAttribute('aria-label', 'Flagged');
    flag.title = 'Flagged';
    top.append(from, flag, time);

    const subject = document.createElement('div');
    subject.className = 'row-subject';
    markInto(subject, thread.subject, terms);

    const preview = document.createElement('div');
    preview.className = 'row-preview';
    markInto(preview, thread.preview || '', terms);

    row.append(top, subject, preview);

    const markers = document.createElement('div');
    markers.className = 'row-markers';
    if (thread.message_count > 1) {
      const n = document.createElement('span');
      n.className = 'count-badge';
      n.textContent = thread.message_count + ' messages';
      markers.appendChild(n);
    }
    if (thread.snoozed_until && state.snoozed) {
      const z = document.createElement('span');
      z.className = 'marker snoozed';
      z.textContent = '⏰ until ' + formatWhen(thread.snoozed_until);
      markers.appendChild(z);
    }
    if (thread.has_attachments) {
      const clip = document.createElement('span');
      clip.className = 'marker';
      clip.classList.add('fa', 'fa-paperclip', 'attachment-marker');
      clip.setAttribute('aria-label', 'Has attachments');
      clip.setAttribute('role', 'img');
      clip.title = 'Has attachments';
      markers.appendChild(clip);
    }
    // Searching every folder: which folder the conversation is in.
    if (thread.folder && searchingAll() && !['inbox'].includes(thread.folder.type)) {
      const f = document.createElement('span');
      f.className = 'folder-badge';
      const fi = document.createElement('i'); fi.className = 'fa fa-folder'; fi.setAttribute('aria-hidden', 'true');
      f.append(fi, ' ' + thread.folder.name);
      f.title = 'In ' + thread.folder.name;
      markers.appendChild(f);
    }
    if (thread.case) {
      const badge = document.createElement('span');
      badge.className = 'case-badge';
      badge.textContent = thread.case.number + ' · ' + thread.case.name;
      markers.appendChild(badge);
    }
    if (thread.claimed) {
      // Shared mailbox: who handles this conversation.
      const who = document.createElement('span');
      who.className = 'claim-badge' + (Number(thread.claimed.id) === Number(document.body.dataset.user || 0) ? ' mine' : '');
      who.title = 'Handled by ' + thread.claimed.name;
      const i = document.createElement('i'); i.className = 'fa fa-user-check'; i.setAttribute('aria-hidden', 'true');
      who.append(i, ' ' + (Number(thread.claimed.id) === Number(document.body.dataset.user || 0) ? 'You' : thread.claimed.name.split(' ')[0]));
      markers.appendChild(who);
    }
    if (markers.children.length) row.appendChild(markers);

    row.addEventListener('click', () => openThread(thread.id));
    li.appendChild(row);
    const into = target || $('threadList');
    if (opts.top) { li.classList.add('top-result'); into.appendChild(li); return; }
    addGroupHeader(into, thread);
    if (into._lastGroup && foldedGroups.has(into._lastGroup)) {
      li.classList.add('group-folded');
      const count = into._lastHeader?.querySelector('.list-group-count');
      if (count) count.textContent = String(Number(count.textContent || 0) + 1);
    }
    into.appendChild(li);
  }

  // --------------------------------------------------------- reading pane

  /**
   * Conversations kept ready in this page, like Outlook's local copy: the one
   * under the mouse and the first ones of the list are fetched before the
   * click, so opening one is instant; a fresh copy is fetched right after and
   * replaces it only when something changed. Kept a few minutes, at most 40.
   */
  const threadCache = new Map();   // id → { at, data, promise }
  const THREAD_CACHE_MS = 3 * 60 * 1000;
  const threadParams = (id) => ({ id, order: state.prefs.thread_order || 'asc', images: state.showImages ? 1 : '', q: currentQuery() });
  function cachedThread(id) {
    const c = threadCache.get(Number(id));
    return c && c.data && Date.now() - c.at < THREAD_CACHE_MS && c.mailbox === state.mailboxId && c.q === currentQuery() ? c.data : null;
  }
  function forgetThread(id) { if (id === undefined) threadCache.clear(); else threadCache.delete(Number(id)); }
  let prefetching = 0;
  function prefetchThread(id) {
    id = Number(id);
    if (!id || cachedThread(id) || threadCache.get(id)?.promise || prefetching >= 2 || document.hidden) return;
    const forMailbox = state.mailboxId, q = currentQuery();
    prefetching++;
    const entry = { at: 0, data: null, mailbox: forMailbox, q };
    entry.promise = api('thread', threadParams(id))
      .then((data) => { if (state.mailboxId === forMailbox) Object.assign(entry, { at: Date.now(), data }); })
      .catch(() => threadCache.delete(id))
      .finally(() => { prefetching--; delete entry.promise; });
    threadCache.set(id, entry);
    if (threadCache.size > 40) threadCache.delete(threadCache.keys().next().value);
  }
  /** The conversations above and below the one opened: the next click is instant too. */
  function prefetchNeighbours(threadId) {
    const row = document.querySelector('.thread-row[data-thread-id="' + threadId + '"]');
    const rows = [...$('threadList').querySelectorAll('.thread-row')];
    const i = rows.indexOf(row);
    if (i < 0) return;
    [rows[i + 1], rows[i - 1]].filter(Boolean).forEach((r, k) => setTimeout(() => prefetchThread(r.dataset.threadId), 300 + k * 300));
  }
  /** The first conversations of the list, ready before anyone clicks. */
  function prefetchTop(n) {
    [...$('threadList').querySelectorAll('.thread-row')].slice(0, n || 3).forEach((r, i) => setTimeout(() => prefetchThread(r.dataset.threadId), 400 + i * 350));
  }

  async function openThread(threadId, options) {
    options = options || {};
    // A reply written in the reading pane belongs to its conversation: opening another one
    // parks it as a tab at the bottom (as a new email written there), like Outlook.
    const overlay = $('composerOverlay');
    if (overlay.classList.contains('inline') && !overlay.hidden && Number(threadId) !== Number(composerThreadId)) {
      await minimizeComposer();
      if (!overlay.hidden) return;   // the draft could not be saved: it stays open
    }
    state.threadId = threadId;
    saveListPos();
    if (!options.preserveScroll) document.body.classList.add('reading-open');
    document.querySelectorAll('.thread-row').forEach((el) => {
      el.classList.toggle('active', Number(el.dataset.threadId) === threadId);
    });
    // Another conversation: the previous one leaves at once, so it is never
    // read (or replied to) as if it were the one just clicked.
    // Ready in memory (pre-loaded under the mouse): shown at once, then checked.
    const ready = !options.preserveScroll && !options.fresh ? cachedThread(threadId) : null;
    if (ready) {
      state.thread = ready;
      renderThread(ready, null);
      setTimeout(() => { if (state.threadId === threadId) openThread(threadId, { preserveScroll: true, fresh: true }); }, 50);
      const row = document.querySelector('.thread-row[data-thread-id="' + threadId + '"]');
      if ((row && row.classList.contains('unread')) || (ready.messages || []).some((m) => m.direction === 'incoming' && m.is_read === false)) void setThreadRead(threadId, true);
      prefetchNeighbours(threadId);
      return;
    }
    if (!options.preserveScroll && state.thread?.id !== threadId) showThreadLoading(threadId);

    try {
      // Opened from a search: the server says which emails and files match (q).
      const thread = await api('thread', threadParams(threadId));
      threadCache.set(Number(threadId), { at: Date.now(), data: thread, mailbox: state.mailboxId, q: currentQuery() });
      if (state.threadId !== threadId) return;
      // A live refresh of the conversation being read (a new email, an older one
      // imported) must not move the reader: the emails they opened stay open,
      // texts already loaded stay, and the email at the top of the view stays there.
      const keep = options.preserveScroll && state.thread?.id === threadId ? captureReadingView(state.thread, thread) : null;
      state.thread = thread;
      renderThread(thread, keep);
      if (keep) restoreReadingView(keep);
      if (!options.preserveScroll && /^#msg-\d+$/.test(window.location.hash || '')) {
        const sourceId = window.location.hash.slice(5);
        requestAnimationFrame(() => document.querySelector('#messageList [data-message-id="' + sourceId + '"]')?.scrollIntoView({block:'center'}));
      }

      // Opening a conversation marks it read, the way every mail client does.
      // A live refresh does not: the reader may just have marked it unread.
      const row = document.querySelector('.thread-row[data-thread-id="' + threadId + '"]');
      const unreadHere = (row && row.classList.contains('unread'))
        || (thread.messages || []).some((m) => m.direction === 'incoming' && m.is_read === false);
      if (!options.preserveScroll && unreadHere) {
        void setThreadRead(threadId, true);
      }
      if (!options.preserveScroll) prefetchNeighbours(threadId);
    } catch (e) {
      if (state.threadId === threadId && !state.thread) {
        const list = $('messageList');
        list.textContent = '';
        const failed = document.createElement('div');
        failed.className = 'thread-loading failed';
        failed.textContent = 'This conversation could not be opened. ';
        const retry = document.createElement('button');
        retry.type = 'button'; retry.className = 'link-btn'; retry.textContent = 'Try again';
        retry.addEventListener('click', () => openThread(threadId));
        failed.appendChild(retry);
        list.appendChild(failed);
      }
      toast(e.message, true);
    }
  }

  /**
   * What the reader sees of the conversation on screen, to put back after a
   * live refresh: which emails are open, which email is at the top of the view
   * (and how far into it), and the texts already loaded — carried into the
   * fresh copy so nothing shows "Loading" again or changes height.
   */
  function captureReadingView(old, fresh) {
    const pane = $('readingPane');
    const top = pane.getBoundingClientRect().top;
    const open = new Map();
    let anchor = null;
    $('messageList').querySelectorAll('[data-message-id]').forEach((article) => {
      const head = article.querySelector('.message-head');
      if (head) open.set(Number(article.dataset.messageId), !!head.open);
      const r = article.getBoundingClientRect();
      if (!anchor && r.bottom > top + 1) anchor = { id: article.dataset.messageId, offset: r.top - top };
    });
    const loaded = new Map((old.messages || []).filter((m) => m.body_state === 'fetched').map((m) => [m.id, m]));
    fresh.messages = fresh.messages.map((m) => (m.body_state !== 'fetched' && loaded.has(m.id) ? loaded.get(m.id) : m));
    const atEnd = pane.scrollTop > 0 && pane.scrollHeight - pane.clientHeight - pane.scrollTop < 40;
    return { open, anchor, scrollTop: pane.scrollTop, atEnd };
  }
  /** The view of the email list before a change: which email is at the top, or "at the end". */
  function readingPlace() {
    return captureReadingView({ messages: [] }, { messages: [] });
  }
  function restoreReadingView(keep) {
    placeReadingView(keep);
    requestAnimationFrame(() => placeReadingView(keep));   // pictures and folding settle a frame later
  }
  function placeReadingView(keep) {
    const pane = $('readingPane');
    if (keep.atEnd) { pane.scrollTop = pane.scrollHeight; return; }
    const article = keep.anchor && $('messageList').querySelector('[data-message-id="' + keep.anchor.id + '"]');
    if (!article) { pane.scrollTop = keep.scrollTop; return; }
    pane.scrollTop += (article.getBoundingClientRect().top - pane.getBoundingClientRect().top) - keep.anchor.offset;
  }

  /** An email scheduled in Outlook: open it there (its web link). */
  function openInMailbox(d) {
    if (d.web_link) window.open(d.web_link, '_blank', 'noopener');
    else toast('Open it in ' + (d.provider_label || 'your mailbox') + ' to change it: it is scheduled there.');
  }

  /** An email scheduled in Outlook for this conversation: when it goes, to whom, its start, Open in Outlook. */
  function remoteScheduledCard(d) {
    const card = document.createElement('div');
    card.className = 'thread-draft scheduled-remote';
    const head = document.createElement('div');
    head.className = 'thread-draft-head';
    const tag = document.createElement('span');
    tag.className = 'thread-draft-tag';
    tag.textContent = '⏱ Scheduled in ' + (d.provider_label || 'Outlook') + ' · ' + formatFull(d.send_at);
    const who = document.createElement('span');
    who.className = 'thread-draft-who';
    who.textContent = d.to.length ? 'To: ' + d.to.join(', ') : '(no recipients)';
    head.append(tag, who);
    const text = document.createElement('div');
    text.className = 'thread-draft-text';
    text.textContent = d.preview || '(empty)';
    const actions = document.createElement('div');
    actions.className = 'thread-draft-actions';
    const open = actionButton('Open in ' + (d.provider_label || 'Outlook'), () => openInMailbox(d));
    open.classList.add('primary');
    actions.appendChild(open);
    card.append(head, text, actions);
    card.title = 'Sent by ' + (d.provider_label || 'Outlook') + ' at that time, even with this app closed.';
    return card;
  }

  /** A saved draft reply in the conversation: who it is to, its start, Edit and Delete. */
  function threadDraftCard(d) {
    if (d.remote) return remoteScheduledCard(d);
    const card = document.createElement('div');
    card.className = 'thread-draft' + (d.send_state === 'failed' ? ' failed' : d.send_state === 'scheduled' ? ' scheduled' : '');
    card.dataset.draftId = String(d.id);
    const head = document.createElement('div');
    head.className = 'thread-draft-head';
    const tag = document.createElement('span');
    tag.className = 'thread-draft-tag';
    tag.textContent = d.send_state === 'scheduled' ? 'Scheduled ' + formatFull(d.send_at) : d.send_state === 'failed' ? 'Not sent' : 'Draft';
    const who = document.createElement('span');
    who.className = 'thread-draft-who';
    who.textContent = (d.mine ? '' : d.author + ' · ') + (d.to.length ? 'To: ' + d.to.join(', ') : '(no recipients)');
    const when = document.createElement('span');
    when.className = 'thread-draft-when';
    when.textContent = (d.editing ? d.editing + ' is editing · ' : '') + 'saved ' + formatWhen(d.updated_at);
    head.append(tag, who, when);
    const text = document.createElement('div');
    text.className = 'thread-draft-text';
    text.textContent = d.preview || '(empty)';
    if (d.send_error) text.title = d.send_error;
    const actions = document.createElement('div');
    actions.className = 'thread-draft-actions';
    const edit = actionButton(d.editing ? 'Read along' : 'Edit', () => openDraft(d.id));
    edit.classList.add('primary');
    actions.appendChild(edit);
    if (d.mine) {
      const del = actionButton('Delete', () => window.ecwChoice(del, [
        { label: 'Delete draft', danger: true, onPick: async () => {
          try {
            await api('draft_discard', {}, { method: 'POST', body: { draft_id: d.id } });
            window.ecwDraftDock?.remove(d.id);
            card.remove();
            toast('Draft deleted');
          } catch (e) { toast(e.message, true); }
        } },
        { label: 'Cancel', onPick: () => {} },
      ]));
      actions.appendChild(del);
    }
    card.append(head, text);
    if ((d.files || []).length) card.appendChild(draftFileLinks(d));
    card.appendChild(actions);
    card.addEventListener('dblclick', () => openDraft(d.id));
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

  // A draft saved, closed or deleted: the conversation on screen shows it as it is now.
  document.addEventListener('ecw:composer-close', () => {
    if (state.threadId && !state.draftsView) setTimeout(() => openThread(state.threadId, { preserveScroll: true }), 300);
  });

  /** The clicked conversation's subject and a spinner until it arrives. */
  function showThreadLoading(threadId) {
    state.thread = null;
    const row = document.querySelector('.thread-row[data-thread-id="' + threadId + '"]');
    $('readingEmpty').hidden = true;
    $('threadView').hidden = false;
    $('threadSubject').textContent = row?._thread?.subject || '';
    ['threadCase', 'addToCaseBtn', 'openCaseBtn', 'removeFromCaseBtn'].forEach((id) => { const n = $(id); if (n) n.hidden = true; });
    $('detailsBody')?.replaceChildren();   // the old conversation's people and files
    const list = $('messageList');
    list.textContent = '';
    const loading = document.createElement('div');
    loading.className = 'thread-loading';
    loading.setAttribute('role', 'status');
    const spin = document.createElement('i');
    spin.className = 'fa fa-circle-notch fa-spin';
    spin.setAttribute('aria-hidden', 'true');
    loading.append(spin, ' Loading the email…');
    list.appendChild(loading);
    $('readingPane').scrollTop = 0;
  }

  /**
   * Read/unread for one conversation. The server keeps one read state per
   * mailbox (shared with the case page and pushed to Outlook / Gmail), so this
   * only has to update the row, the folder counts and the nav badges.
   */
  async function setThreadRead(threadId, isRead) {
    const row = document.querySelector('.thread-row[data-thread-id="' + threadId + '"]');
    row?.classList.toggle('unread', !isRead);
    if (row?._thread) row._thread.unread = !isRead;
    try {
      await api('mark_read', {}, { method: 'POST', body: { thread_id: threadId, is_read: isRead } });
      loadFolders().catch(() => {});
      document.dispatchEvent(new CustomEvent('ecw:unread-changed', { detail: { thread_id: threadId, is_read: isRead } }));
      // Inside a pop-up window: the mail page behind it updates the row and the badges.
      if (window.parent !== window) window.parent.postMessage({ type: 'ecw-thread-read', thread_id: threadId, is_read: isRead }, location.origin);
    } catch (e) {
      row?.classList.toggle('unread', isRead);
      toast(e.message, true);
    }
  }

  function renderThread(thread, keep) {
    $('readingEmpty').hidden = true;
    $('threadView').hidden = false;
    markInto($('threadSubject'), thread.subject, searchTerms());   // the searched words lit in the conversation's name too

    const badge = $('threadCase');
    if (thread.case) {
      badge.textContent = thread.case.number + ' · ' + thread.case.name;
      badge.hidden = false;
      $('addToCaseBtn').hidden = true;
      $('openCaseBtn').hidden = false;
      $('openCaseBtn').href = 'case.php?id=' + thread.case.id;   // a real link: a new tab with Ctrl+click, middle-click or right-click
      $('removeFromCaseBtn').hidden = false;
    } else {
      badge.hidden = true;
      $('addToCaseBtn').hidden = false;
      $('openCaseBtn').hidden = true;
      $('removeFromCaseBtn').hidden = true;
    }

    const list = $('messageList');
    list.textContent = '';
    // Only the most recent message opens expanded; every earlier one starts
    // collapsed to its "Sender · time" header, per the compact reading pane.
    const latestId = latestMessageId(thread.messages);
    // From a search: the emails that match open too, and matching files are marked.
    const matchIds = new Set((thread.search && thread.search.message_ids) || []);
    searchFileHits = (thread.search && thread.search.attachments) || {};
    // Internal notes are not in this list: like comments in Word they hang off
    // each email (its 💬 button) and open in the notes panel (mail-extras.js).
    thread.messages.forEach((m) => list.appendChild(renderMessage(m, keep && keep.open.has(m.id) ? keep.open.get(m.id) : (m.id === latestId || matchIds.has(m.id)))));
    // Saved draft replies sit in the conversation, newest end, like Outlook's "[Draft]".
    const newestFirst = (state.prefs.thread_order || 'asc') === 'desc';
    (thread.drafts || []).forEach((d) => { const card = threadDraftCard(d); newestFirst ? list.prepend(card) : list.appendChild(card); });
    if (!keep) $('readingPane').scrollTop = 0;
    // Land on the first matching email (a word search then highlights inside it, mail-extras.js).
    const firstMatch = thread.messages.find((m) => matchIds.has(m.id));
    if (!keep && firstMatch && firstMatch.id !== latestId) requestAnimationFrame(() => document.querySelector('#messageList [data-message-id="' + firstMatch.id + '"]')?.scrollIntoView({ block: 'start' }));
    renderDetailsPane(thread);
    void loadMissingBodies(thread, state.mailboxId);
    document.dispatchEvent(new CustomEvent('ecw:thread-open', { detail: thread }));
  }

  // ------------------------------------------------------------ details pane

  function mailboxLabel() {
    const select = $('mailboxSelect');
    const option = select && select.options[select.selectedIndex];
    return option ? option.textContent : null;
  }

  function renderDetailsPane(thread) {
    if (!window.ecwComputeThreadDetails || !window.ecwRenderThreadDetailsPane) return;
    const details = window.ecwComputeThreadDetails({
      subject: thread.subject,
      caseInfo: thread.case ? { number: thread.case.number, name: thread.case.name } : null,
      mailboxLabel: mailboxLabel(),
      messages: thread.messages,
    });
    window.ecwRenderThreadDetailsPane($('detailsBody'), details, {
      formatFull: formatFull,
      onParticipantClick: highlightParticipant,
    });
    renderDealSection(thread);
    // First thread ever opened this page load: reveal the pane itself. Every
    // later call is just a content refresh — the pane, once shown, stays
    // shown, the same way #threadView never reverts to the empty state.
    $('detailsPane').hidden = false;
    if (window.ecwDetailsPaneResizer) window.ecwDetailsPaneResizer.setVisible(true);
  }

  /**
   * Deal section of the details pane (lib/deals.php), for a conversation in a case:
   * where selling / buying stands, the current offer and its revision, and 📍 links that
   * scroll this conversation to the email (or open the case when it is in another one).
   */
  async function renderDealSection(thread) {
    const caseId = thread.case?.id;
    const host = $('detailsBody');
    if (!caseId || !host || document.body.dataset.deals !== '1') return;
    let d;
    try { d = await api('case_deals', { case_id: caseId }, { noMailbox: true }); } catch (_) { return; }
    if (state.thread !== thread || !d.active || !d.active.length) return;
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; };
    const box = el('details', 'details-section deal-mini');
    let open = true; try { open = localStorage.getItem('ot.pane.details.section.deal') !== '0'; } catch (_) {}
    box.open = open;
    box.addEventListener('toggle', () => { try { localStorage.setItem('ot.pane.details.section.deal', box.open ? '1' : '0'); } catch (_) {} });
    box.appendChild(el('summary', '', 'Deal · ' + (thread.case.number || 'case')));
    const inThread = new Set((thread.messages || []).map((m) => Number(m.id)));
    const go = (doc) => {
      if (inThread.has(Number(doc.message_id))) {
        const t = document.querySelector('#messageList [data-message-id="' + doc.message_id + '"]');
        if (t) { t.scrollIntoView({ block: 'center', behavior: 'smooth' }); t.classList.add('target'); setTimeout(() => t.classList.remove('target'), 2000); return; }
      }
      window.open('case.php?id=' + caseId + '#msg-' + doc.message_id, '_blank', 'noopener');
    };
    const money = (a, c) => (a === null || a === undefined ? '' : Number(a).toLocaleString([], { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (c ? ' ' + c : ''));
    d.active.forEach((side) => {
      const s = d.sides[side];
      const row = el('div', 'deal-mini-side');
      row.appendChild(el('div', 'deal-mini-title', (side === 'sell' ? 'Selling' : 'Buying') + ' · ' + d.stages[s.reached]));
      const list = el('ul', 'deal-mini-list');
      Object.keys(d.stages).forEach((stage) => {
        let doc = (s.stages[stage] || []).slice(-1)[0];
        if (stage === 'offer' && s.offers.length) doc = s.offers[0].latest;
        if (!doc) return;
        const li = el('li');
        const b = el('button', 'deal-mini-go');
        b.type = 'button';
        b.title = 'Go to the email';
        b.textContent = '📍 ' + d.stages[stage] + (doc.ref ? ' ' + doc.ref : '') + (doc.rev_label ? ' · ' + doc.rev_label : '') + (doc.amount !== null ? ' · ' + money(doc.amount, doc.currency) : '');
        b.addEventListener('click', () => go(doc));
        li.appendChild(b);
        list.appendChild(li);
      });
      row.appendChild(list);
      box.appendChild(row);
    });
    const warn = d.alerts.filter((a) => a.level === 'warn').length;
    if (warn) box.appendChild(el('p', 'deal-mini-warn', '⚠ ' + warn + ' thing' + (warn === 1 ? '' : 's') + ' to check in the case’s Deals tab'));
    const link = el('a', 'deal-mini-open', 'Open the Deals tab');
    link.href = 'case.php?id=' + caseId;
    box.appendChild(link);
    host.querySelector('.deal-mini')?.remove();
    host.insertBefore(box, host.children[1] || null);
  }

  /** Cheap "filter" per the step: flash + scroll to this person's messages
   *  rather than actually hiding the rest, since every message is already in
   *  the DOM and re-rendering the list would collapse the reader's place. */
  function highlightParticipant(participant) {
    const email = String(participant.email || '').toLowerCase();
    if (!email || !state.thread) return;
    const byId = new Map(state.thread.messages.map((m) => [String(m.id), m]));
    const matches = Array.from($('messageList').children).filter((article) => {
      const message = byId.get(article.dataset.messageId);
      if (!message) return false;
      return [message.from].concat(message.to, message.cc)
        .some((p) => p && String(p.email || '').toLowerCase() === email);
    });
    if (!matches.length) return;
    matches.forEach((article) => article.classList.add('target'));
    matches[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
    setTimeout(() => matches.forEach((article) => article.classList.remove('target')), 2000);
  }

  /** Chronologically last message, independent of the display order preference. */
  function latestMessageId(messages) {
    let id = null;
    let at = '';
    messages.forEach((m) => {
      if (m.occurred_at > at) { at = m.occurred_at; id = m.id; }
    });
    return id;
  }

  /** "Loading attachments…" where an email's files will show, until they are fetched. */
  function attachmentsLoadingRow() {
    const row = document.createElement('div');
    row.className = 'message-attachments attachments-loading';
    row.setAttribute('role', 'status');
    const spin = document.createElement('span');
    spin.className = 'ai-spin';
    spin.setAttribute('aria-hidden', 'true');
    const icon = document.createElement('i');
    icon.className = 'fa fa-paperclip';
    icon.setAttribute('aria-hidden', 'true');
    row.append(spin, icon, document.createTextNode(' Loading attachments…'));
    return row;
  }

  /**
   * The new case's group, on the name's line: a button with the group's colour,
   * and a menu of the groups with theirs. The hidden select keeps the value.
   */
  function buildGroupPick(groups) {
    const wrap = $('newCaseGroupPick'), btn = $('newCaseGroupBtn'), menu = $('newCaseGroupMenu'), select = $('newCaseGroup');
    if (!wrap || !groups.length) { if (wrap) wrap.hidden = true; return; }
    const paint = () => {
      const o = select.selectedOptions[0];
      btn.querySelector('.group-pick-name').textContent = o ? o.textContent : 'No group';
      btn.querySelector('.group-dot').style.background = o && o.dataset.color ? o.dataset.color : 'transparent';
      btn.classList.toggle('is-empty', !Number(select.value));
    };
    const close = () => { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    menu.textContent = '';
    [{ id: 0, name: 'No group', color: '' }].concat(groups).forEach((g) => {
      const item = document.createElement('button');
      item.type = 'button'; item.setAttribute('role', 'menuitemradio'); item.dataset.value = String(g.id);
      const dot = document.createElement('span'); dot.className = 'group-dot'; dot.style.background = g.color || 'transparent';
      item.append(dot, document.createTextNode(g.name));
      item.addEventListener('click', () => { select.value = String(g.id); select.dispatchEvent(new Event('change')); paint(); close(); btn.focus(); });
      menu.appendChild(item);
    });
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = menu.hidden;
      menu.hidden = !open; btn.setAttribute('aria-expanded', String(open));
      menu.querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.value === select.value)));
      if (open) (menu.querySelector('[aria-checked="true"]') || menu.querySelector('button')).focus({ preventScroll: true });
    });
    document.addEventListener('click', (e) => { if (!menu.hidden && !wrap.contains(e.target)) close(); });
    menu.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); close(); btn.focus(); return; }
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      e.preventDefault();
      const items = [...menu.querySelectorAll('button')], i = items.indexOf(document.activeElement);
      items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
    });
    wrap.hidden = false;
    paint();
  }

  async function loadMissingBodies(thread, mailboxId) {
    // Fetch sequentially to avoid flooding Graph for a long conversation.
    for (const message of thread.messages) {
      if (state.thread !== thread || state.threadId !== thread.id || state.mailboxId !== mailboxId) return;
      if ((message.body_state === 'fetched' && !message.needs_inline_images && !message.attachments_pending) || message.removed) continue;
      const article = $('messageList').querySelector('[data-message-id="' + message.id + '"]');
      if (article) await loadMessageBody(message, article, thread, mailboxId);
    }
  }

  async function loadMessageBody(message, article, thread, mailboxId) {
    const current = () => state.thread === thread && state.threadId === thread.id
      && state.mailboxId === mailboxId && article.isConnected;
    if (!current() || article.dataset.loading === '1') return;
    article.dataset.loading = '1';
    const body = article.querySelector('.message-body');
    // The text is already here (only pictures or attachments are missing): keep
    // showing it and swap in the complete email when it arrives. Only an email
    // with no text yet says "Loading".
    const quiet = message.body_state === 'fetched';
    if (!quiet) { body.textContent = 'Loading message'; body.classList.add('is-loading'); }
    // A body fetch must not collapse a header the reader already opened (or
    // silently re-collapse the one message that opened by default).
    const wasOpen = isMessageHeadOpen(article, message, thread);
    try {
      const detail = await api('body', { mailbox_id: mailboxId, message_id: message.id,
        thread_id: thread.id, images: state.showImages ? 1 : '' });
      if (!current()) return;
      const loaded = (detail.messages || []).find((item) => item.id === message.id);
      if (!loaded || loaded.body_state !== 'fetched') throw new Error('Message content could not be loaded.');
      const index = thread.messages.findIndex((item) => item.id === message.id);
      if (index !== -1) thread.messages[index] = loaded;
      // The text of an email above the view arriving must not push the reader away from theirs.
      const place = readingPlace();
      article.replaceWith(renderMessage(loaded, wasOpen));
      restoreReadingView(place);
    } catch (e) {
      if (!current()) return;
      if (quiet) return;   // the text stays; the files come with the background job
      body.textContent = 'Message content could not be loaded. ';
      body.appendChild(actionButton('Retry', () => loadMessageBody(message, article, thread, mailboxId)));
    } finally {
      delete article.dataset.loading; body.classList.remove('is-loading');
    }
  }

  /** Whatever a fresh render would default to, unless the reader already toggled it. */
  function isMessageHeadOpen(article, message, thread) {
    const existing = article.querySelector('.message-head');
    if (existing && typeof existing.open === 'boolean') return existing.open;
    return message.id === latestMessageId(thread.messages);
  }

  let searchFileHits = {};   // attachment id => searched word, for the conversation on screen
  function renderMessage(message, isExpanded) {
    const article = document.createElement('div');
    article.dataset.messageId = String(message.id);
    article.className = 'message ' + message.direction + (message.removed ? ' removed' : '');

    // Collapsed by default: "Sender · time" is the entire header. Opening it
    // reveals From/To/Cc/Date — the full header is never shown permanently.
    const head = document.createElement('details');
    head.className = 'message-head';
    if (isExpanded) head.open = true;

    const summary = document.createElement('summary');
    summary.className = 'message-summary';
    const from = document.createElement('span');
    from.className = 'message-from';
    from.textContent = (message.direction === 'outgoing' ? 'You · ' : '') + (message.from.name || message.from.email || '(unknown)')
      + (message.from.name ? ' <' + message.from.email + '>' : '');
    const when = document.createElement('span');
    when.className = 'message-when';
    when.textContent = formatCompact(message.occurred_at);
    const avatar = document.createElement('span');
    avatar.className = 'sender-avatar';
    avatar.textContent = window.ecwInitials ? window.ecwInitials(message.from.name, message.from.email) : '?';
    summary.append(avatar, from, when);
    head.appendChild(summary);

    const meta = document.createElement('div');
    meta.className = 'message-meta';
    // Names; a click on one shows its address (ui-helpers.js ecwAddressList).
    const people = (list) => (window.ecwAddressList ? window.ecwAddressList(list) : addressLine(list));
    meta.appendChild(metaLine('From', people([message.from])));
    if (message.to.length) meta.appendChild(metaLine('To', people(message.to)));
    if (message.cc.length) meta.appendChild(metaLine('Cc', people(message.cc)));
    if ((message.bcc || []).length) meta.appendChild(metaLine('Bcc', people(message.bcc)));
    meta.appendChild(metaLine('Date', formatFull(message.occurred_at)));
    if (message.removed) meta.appendChild(metaLine('Note', 'deleted in mailbox'));
    head.appendChild(meta);

    article.appendChild(head);

    const body = document.createElement('div');
    body.className = 'message-body';
    // SANITIZED SERVER-SIDE (HTMLPurifier). See the file header.
    if (message.body_html) {
      body.innerHTML = message.body_html;
      // What I sent is a typed email in my own colours, even when my signature is built of tables.
      if (message.direction !== 'outgoing') markDesigned(body);
    } else {
      body.textContent = message.body_state === 'fetched' ? (message.signature_html ? '' : '(no content)')
        : message.removed ? 'Message content was not downloaded before this email was deleted.' : 'Loading message';
      if (message.body_state !== 'fetched' && !message.removed) body.classList.add('is-loading');
    }
    article.appendChild(body);
    // "Translate" when the email is in another language than yours (assets/translate.js).
    window.ecwTranslate?.attach(body, message.id);

    if (message.signature_html) {
      const signature = document.createElement('details');
      signature.className = 'message-signature';
      const summary = document.createElement('summary');
      summary.textContent = 'Signature';
      const content = document.createElement('div');
      content.className = 'signature-content';
      // Sanitized by the same server-side policy as the message body.
      content.innerHTML = message.signature_html;
      signature.append(summary, content);
      article.appendChild(signature);
    }

    if (article.querySelector('img[data-ecw-src]')) {
      article.insertBefore(blockedImagesBar(), body.nextSibling);
    }

    if (message.has_quoted) {
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'quote-toggle';
      toggle.textContent = '...';
      toggle.title = 'Show previous email history';
      toggle.setAttribute('aria-label', 'Show previous email history');
      toggle.setAttribute('aria-expanded', 'false');

      const quoted = document.createElement('div');
      quoted.className = 'message-quoted';
      quoted.hidden = true;

      toggle.addEventListener('click', async () => {
        if (quoted.hidden && !quoted.dataset.filled) {
          // The conversation comes without each email's history (megabytes in a
          // long one): fetch this email's when it is asked for.
          if (message.quoted_html == null) {
            toggle.disabled = true; toggle.classList.add('is-loading');
            try {
              const detail = await api('body', { message_id: message.id, images: state.showImages ? 1 : '' });
              message.quoted_html = ((detail.messages || []).find((m) => m.id === message.id) || {}).quoted_html || '';
            } catch (e) { toast(e.message, true); return; }
            finally { toggle.disabled = false; toggle.classList.remove('is-loading'); }
          }
          // SANITIZED SERVER-SIDE (HTMLPurifier). See the file header.
          quoted.innerHTML = message.quoted_html || '';
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
    if (message.attachments_pending && !message.attachments.some((a) => !a.is_inline)) aboveText(attachmentsLoadingRow());

    if (message.attachments.length) {
      const wrap = document.createElement('div');
      wrap.className = 'message-attachments';
      message.attachments.forEach((attachment) => {
        const link = document.createElement('a');
        link.className = 'attachment';
        link.href = 'api/index.php?action=attachment&id=' + attachment.id;
        if (window.ecwFileIcon) link.appendChild(window.ecwFileIcon(attachment.filename, attachment.mime_type));
        link.appendChild(document.createTextNode(attachment.filename || 'attachment'));
        link.dataset.name = attachment.filename || 'attachment';
        link.dataset.mime = attachment.mime_type || '';
        link.dataset.size = String(attachment.size || 0);
        const size = document.createElement('span');
        size.className = 'size';
        size.textContent = formatSize(attachment.size);
        link.appendChild(size);
        if (searchFileHits[attachment.id]) {
          // The searched word is in this file (its name or inside it).
          link.classList.add('search-hit');
          const hit = document.createElement('span');
          hit.className = 'att-hit';
          hit.textContent = 'contains “' + searchFileHits[attachment.id] + '”';
          link.appendChild(hit);
        }
        wrap.appendChild(link);
      });
      // Several files: one menu to open them all side by side or download them as one .zip.
      const files = Array.from(wrap.querySelectorAll('a.attachment'));
      const previewable = files.filter((a) => window.ecwIsPreviewableLink?.(a));
      if (files.length > 1) wrap.appendChild(attachmentsMenu(message.id, files.length, previewable));
      aboveText(wrap);
    }

    const actions = document.createElement('div');
    actions.className = 'message-actions';
    actions.append(
      actionButton('Reply', () => openComposer('reply', message.id)),
      actionButton('Reply all', () => openComposer('reply_all', message.id)),
      actionButton('Forward', () => openComposer('forward', message.id))
    );
    if (message.web_link) {
      const open = document.createElement('a');
      open.className = 'attachment';
      open.href = message.web_link;
      open.target = '_blank';
      open.rel = 'noopener noreferrer';
      open.textContent = 'Open original';
      actions.appendChild(open);
    }
    // 💬 at the bottom right: this email's internal notes (mail-extras.js paints the count).
    if (window.ecwNoteBubble) {
      actions.appendChild(window.ecwNoteBubble(message.id, (id) => document.dispatchEvent(new CustomEvent('ecw:notes-open', { detail: {
        message_id: id, from: message.from?.name || message.from?.email || '', at: message.occurred_at,
      } }))));
    }
    article.appendChild(actions);

    return article;
  }

  /** "All files ▾": Open all (each in its own viewer, tiled) and Download all (.zip). */
  function attachmentsMenu(messageId, count, previewable) {
    const menu = document.createElement('details');
    menu.className = 'attachments-all';
    const summary = document.createElement('summary');
    summary.innerHTML = '<i class="fa fa-layer-group" aria-hidden="true"></i> All files (' + count + ') <i class="fa fa-chevron-down caret" aria-hidden="true"></i>';
    const body = document.createElement('div');
    body.className = 'attachments-all-menu';
    body.setAttribute('role', 'menu');
    if (previewable.length && window.ecwOpenAllPreviews) {
      const open = document.createElement('button');
      open.type = 'button';
      open.setAttribute('role', 'menuitem');
      open.innerHTML = '<i class="fa fa-table-cells-large" aria-hidden="true"></i> Open all <span class="muted">(' + Math.min(8, previewable.length) + ', side by side)</span>';
      open.addEventListener('click', () => { menu.open = false; window.ecwOpenAllPreviews(previewable); });
      body.appendChild(open);
    }
    const download = document.createElement('a');
    download.setAttribute('role', 'menuitem');
    download.href = 'api/index.php?action=attachments_zip&message_id=' + encodeURIComponent(messageId) + '&mailbox_id=' + encodeURIComponent(state.mailboxId || '');
    download.innerHTML = '<i class="fa fa-file-zipper" aria-hidden="true"></i> Download all <span class="muted">(.zip)</span>';
    download.addEventListener('click', () => { menu.open = false; toast('Preparing the zip file…'); });
    body.appendChild(download);
    menu.append(summary, body);
    return menu;
  }
  // One listener for every "All files" menu: a click elsewhere closes them.
  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.attachments-all[open]').forEach((m) => { if (!m.contains(e.target)) m.open = false; });
  });

  function actionButton(label, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
  }

  function blockedImagesBar() {
    const bar = document.createElement('div');
    bar.className = 'blocked-images';
    const text = document.createElement('span');
    text.textContent = 'Remote images are blocked — they can tell the sender you opened this.';
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Show images';
    button.addEventListener('click', async () => {
      state.showImages = true;
      await openThread(state.threadId);
    });
    bar.append(text, button);
    return bar;
  }

  // ------------------------------------------------------------- composer

  // Settings → Email & writing → "Where replies open": a floating window (the default), or in the
  // reading pane: a reply under its conversation, a new email in place of the conversation.
  const replyInline = (() => { try { return JSON.parse($('appearanceConfig')?.textContent || '{}').reply_mode === 'inline'; } catch (_) { return false; } })();

  /** Put the open composer where this person wants it (called once it is shown). */
  function placeComposer(reply) {
    const overlay = $('composerOverlay'), pane = $('readingPane');
    // Not in a pop-out or the case page's frame, nor while the reading pane is off screen (phone list).
    const inline = replyInline && !isPopout && window.parent === window && !!pane && pane.getClientRects().length > 0;
    const asReply = inline && reply && !$('threadView').hidden && !!state.thread && Number(state.thread.id) === Number(state.threadId);
    overlay.classList.toggle('inline', inline);
    overlay.classList.toggle('inline-reply', asReply);
    overlay.classList.toggle('inline-new', inline && !asReply);
    document.body.classList.toggle('inline-compose-new', inline && !asReply);
    if (!inline) { if (overlay.parentElement !== document.body) document.body.appendChild(overlay); return; }
    for (const k of ['left', 'top', 'right', 'bottom', 'width']) overlay.style[k] = '';   // where the window was dragged to
    if (asReply) {
      // At the newest end of the conversation, where the answered email is: above the emails
      // when they show newest first (Settings → Email & writing), under them when oldest first.
      const newestFirst = (state.prefs.thread_order || 'asc') === 'desc';
      if (newestFirst) $('messageList').before(overlay); else $('threadView').appendChild(overlay);
      requestAnimationFrame(() => { if (newestFirst) pane.scrollTo({ top: 0, behavior: 'smooth' }); else overlay.scrollIntoView({ block: 'end', behavior: 'smooth' }); });
    } else {
      overlay.style.height = '';
      pane.appendChild(overlay);
      pane.scrollTop = 0;
    }
  }

  async function openComposer(mode, messageId, options) {
    options = options || {};
    if (composerOpening) return;
    // Like Outlook: an email already being written is minimized to a tab, not lost.
    if (!$('composerOverlay').hidden) await minimizeComposer();
    composerOpening = true;
    composerMailboxId = state.mailboxId;
    clearTimeout(draftTimer);
    state.composeMode = mode;
    state.replyToMessageId = messageId || null;
    if ($('replyAssistantBtn')) $('replyAssistantBtn').hidden = !messageId || !['reply','reply_all','forward'].includes(mode);
    state.composeCaseId = options.caseId || null;
    state.draftId = null;
    setDraftSchedule(null);
    resetDraftShare();
    composeSeenLatest = 0;
    // The signatures do not depend on the email answered: asked for now, used below.
    const signaturesP = api('signature_profiles_get', {});
    signaturesP.catch(() => {});

    try {
      if (messageId) {
        // The answered email in full (body, pictures). 'body' sends that one email only, so it is
        // merged into the conversation on screen: replacing it left the composer's history with
        // just the email answered instead of the whole conversation.
        const detail = await api('body', {mailbox_id:composerMailboxId, message_id:messageId, images:1});
        if (detail?.messages) {
          if (state.thread && Number(state.thread.id) === Number(detail.id) && Array.isArray(state.thread.messages)) {
            const loaded = new Map(detail.messages.map((m) => [Number(m.id), m]));
            state.thread.messages = state.thread.messages.map((m) => loaded.get(Number(m.id)) || m);
          } else {
            state.thread = detail;   // not open here (opened from another page): what came back
          }
        }
        composeSeenLatest = Math.max(0, ...(state.thread?.messages || []).map((m) => Number(m.id) || 0));
      }
      const context = state.composeCaseId
        ? await api('case_compose', { mailbox_id:composerMailboxId, case_id: state.composeCaseId })
        : await api('compose', { mailbox_id:composerMailboxId, mode: mode, message_id: messageId || '' });

      $('composerTitle').textContent = state.composeCaseId
        ? 'New message · ' + (context.case_number || 'case')
        : (mode === 'forward' ? 'Forward' : (mode === 'new' ? 'New message' : 'Reply'));
      $('fieldTo').value = (context.to || []).map((r) => r.email).join(', ');
      $('fieldCc').value = (context.cc || []).map((r) => r.email).join(', ');
      $('fieldBcc').value = '';
      $('fieldSubject').value = context.subject || '';
      // Cc/Bcc stay collapsed unless this message already has a Cc to show.
      $('ccBccFields').hidden = !(context.cc || []).length;

      ensureEditor();
      // Signature above the quoted history, which is where a reader expects it.
      composeQuote = context.quoted_html || '';
      quill.clipboard.dangerouslyPasteHTML('<p><br></p>', 'silent');
      quoteChanged = false;
      $('composeQuoteDetails').open = false;
      $('composeQuoteDetails').hidden = !composeQuote;
      if (quoteQuill) quoteQuill.root.innerHTML = composeQuote;
      composeSignature = context.signature_html || '';
      const signatures = await signaturesP;
      signatureProfiles = signatures.profiles || [];
      if (composeSignature && !signatureProfiles.some(p => p.html === composeSignature)) signatureProfiles.push({id:'mailbox-default',name:'Mailbox signature',html:composeSignature});
      const select = $('composeSignatureSelect');
      select.replaceChildren(new Option('No signature', ''));
      signatureProfiles.forEach(p => select.add(new Option(p.name + (p.id === signatures.default_id ? ' · Default' : ''), p.id)));
      select.value = signatureProfiles.find(p => p.html === composeSignature)?.id || '';
      $('composeSignaturePreview').innerHTML = composeSignature;
      fillComposerHistory(mode !== 'new');

      state.replyToMessageId = context.in_reply_to_message_id || null;
      $('composerAttachments').textContent = '';
      $('draftStatus').textContent = '';
      $('composerOverlay').hidden = false;
      placeComposer(mode !== 'new');
      paintShare();
      // Reply / Reply all: the recipients are filled in, so the cursor waits in the text, at the
      // top (above the signature); a forward or new email starts at To.
      if (['reply', 'reply_all'].includes(mode) && $('fieldTo').value) { quill.focus(); quill.setSelection(0, 0, 'silent'); }
      else $('fieldTo').focus({ preventScroll: true });
      scheduleDraftSave();
      // mail-extras.js: presence heartbeat ("Ann is replying") and templates.
      composerThreadId = mode === 'new' ? null : (state.thread?.id || null);
      document.dispatchEvent(new CustomEvent('ecw:composer-open', { detail: {
        mode, mailboxId: composerMailboxId, threadId: mode === 'new' ? null : (state.thread?.id || null),
        caseNumber: context.case_number || state.thread?.case?.number || '', caseName: state.thread?.case?.name || '',
      } }));
    } catch (e) {
      toast(e.message, true);
    } finally { composerOpening = false; }
  }

  function ensureEditor() {
    if (quill) return;
    // Alignment as style="text-align:…" (not a class): it survives the email sanitizer and every mail client.
    Quill.register(Quill.import('attributors/style/align'), true);
    quill = new Quill('#editor', {
      theme: 'snow',
      placeholder: 'Write your message…',
      modules: {
        toolbar: [
          fontPickers(),
          ['bold', 'italic', 'underline'],
          [{color:[]}],
          [{ list: 'ordered' }, { list: 'bullet' }],
          [{ align: [] }],
          ['link', 'image'],
          ['clean'],
        ],
      },
    });
    quill.on('text-change', scheduleDraftSave);
    showFontUnderCursor(quill);
    // Templates, meeting and AI at the right end of the same toolbar: one row of tools.
    const tools = $('composerTools');
    if (tools) quill.getModule('toolbar').container.appendChild(tools);
    // Click a picture: resize, align, crop, move or remove it (assets/image-tools.js).
    if (window.ecwImageTools) window.ecwImageTools(quill, { onChange: scheduleDraftSave });
    // Greek and English spelling, and the person's own words as they type (assets/spelling.js).
    if (window.ecwSpelling) window.ecwSpelling.attach(quill, () => composerMailboxId || state.mailboxId);
  }

  // Font and size as inline styles too, from the list in Settings → Email & writing (lib/appearance.php),
  // for the composer and the signature editor. "Default" (false) leaves the text in the person's
  // own font, which the sent email carries. Returns the toolbar group.
  let fontGroup = null;
  function fontPickers() {
    if (fontGroup) return fontGroup;
    const dflt = window.ecwComposeDefault || { font: 'Segoe UI', size: 11 };
    const fontNames = (window.ecwComposeFontNames || []).filter((n) => n !== dflt.font);
    // Points, as Outlook shows them (12px read as 9 there). Drafts written in px keep their sizes.
    const sizes = (window.ecwComposeSizes || []).filter((n) => n !== dflt.size).map((n) => n + 'pt');
    const oldPx = [8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 20, 24].map((n) => n + 'px');
    const Font = Quill.import('attributors/style/font'); Font.whitelist = fontNames; Quill.register(Font, true);
    const Size = Quill.import('attributors/style/size'); Size.whitelist = [...sizes, ...oldPx]; Quill.register(Size, true);
    composerPickerLabels(dflt, fontNames, sizes);
    fontGroup = [{ font: [false, ...fontNames] }, { size: [false, ...sizes] }];
    return fontGroup;
  }

  // The font and size boxes show the text under the cursor as it is drawn, also a font or a size
  // the lists do not have (a pasted signature in "Aptos, sans-serif", 10.5 pt): data-now, shown by
  // the CSS of composerPickerLabels.
  function showFontUnderCursor(q) {
    const bar = q.getModule('toolbar').container;
    const paint = () => {
      const sel = window.getSelection();
      let node = sel && sel.rangeCount ? sel.anchorNode : null;
      if (!node || !q.root.contains(node)) return;
      if (node.nodeType === 3) node = node.parentElement;
      const cs = getComputedStyle(node);
      const font = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim();
      const pt = Math.round(parseFloat(cs.fontSize) * 0.75 * 2) / 2;   // px → pt, as Outlook shows sizes
      [['font', font], ['size', String(pt)]].forEach(([picker, text]) => {
        const label = bar.querySelector('.ql-' + picker + ' .ql-picker-label');
        if (label) { label.dataset.now = text; label.title = text; }
      });
    };
    q.on('editor-change', () => setTimeout(paint, 0));
  }

  // Quill's snow theme names picker entries in CSS (::before content), so the labels are written here from the lists.
  function composerPickerLabels(dflt, fontNames, sizes) {
    const q = (s) => JSON.stringify(String(s));
    const sel = (picker, value) => ['label', 'item'].map((part) => `.ql-snow .ql-picker.ql-${picker} .ql-picker-${part}${value === null ? '' : `[data-value=${q(value)}]`}::before`).join(',');
    const css = [`${sel('font', null)}{content:${q(dflt.font)}}`, `${sel('size', null)}{content:${q(dflt.size)}}`];
    fontNames.forEach((n) => css.push(`${sel('font', n)}{content:${q(n)}}`, `.ql-snow .ql-picker.ql-font .ql-picker-item[data-value=${q(n)}]::before{font-family:${q(n)}}`));
    sizes.forEach((s) => css.push(`${sel('size', s)}{content:${q(parseInt(s, 10))}}`));
    css.push('.ql-snow .ql-picker:is(.ql-font,.ql-size) .ql-picker-label[data-now]::before{content:attr(data-now) !important}');
    const style = document.createElement('style');
    style.textContent = css.join('\n');
    document.head.appendChild(style);
  }

  function composerPayload() {
    if (quill?.update) quill.update();
    if (quoteQuill?.update) quoteQuill.update();
    const quotation = quoteChanged && quoteQuill ? '<blockquote>' + quoteQuill.root.innerHTML + '</blockquote>' : composeQuote;
    return {
      // Kept apart so a reopened draft restores answer, signature and quote exactly.
      parts: {
        answer: quill ? quill.root.innerHTML : '',
        signature: composeSignature,
        quote: quoteChanged && quoteQuill ? quoteQuill.root.innerHTML : composeQuote,
        quote_changed: !!quoteChanged,
      },
      mode: state.composeMode,
      in_reply_to_message_id: state.replyToMessageId,
      case_id: state.composeCaseId,
      subject: $('fieldSubject').value,
      body_html: (quill ? quill.root.innerHTML : '') + composeSignature + quotation,
      to: parseAddresses($('fieldTo').value),
      cc: parseAddresses($('fieldCc').value),
      bcc: parseAddresses($('fieldBcc').value),
    };
  }

  /** Autosave, debounced. A draft that only saves on close loses work. */
  function scheduleDraftSave() {
    draftUnsaved = true;
    clearTimeout(draftTimer);
    if (sending) return;
    draftTimer = setTimeout(() => saveDraft().catch(() => { $('draftStatus').textContent = 'Draft not saved'; }), 1200);
  }

  /**
   * A scheduled email opened again: it stays scheduled while it is changed and closed
   * (saved with keep_schedule). "Cancel schedule" makes it an ordinary draft.
   */
  function setDraftSchedule(at) {
    state.draftScheduledAt = at || null;
    if (at) paintDraftSchedule(); else paintDraftBanner(null);
  }
  function paintDraftSchedule() {
    const s = $('draftStatus');
    if (!s || !state.draftScheduledAt) return;
    s.textContent = '⏱ Scheduled for ' + formatFull(state.draftScheduledAt) + (state.draftHeldInOutlook ? ' · held by Outlook: it goes even if this PC is off ' : ' · stays scheduled ');
    paintDraftBanner({ send_state: 'scheduled', send_at: state.draftScheduledAt });
  }
  /** Cancel a scheduled send: it becomes an ordinary draft (banner and status say so). */
  async function cancelDraftSchedule() {
    try {
      const c = await api('send_cancel', {}, { method: 'POST', body: { draft_id: state.draftId } });
      if (!c.cancelled) { toast('Too late — it was already sent', true); return; }
      state.draftScheduledAt = null;
      $('draftStatus').textContent = 'Schedule cancelled — it is a draft again';
      paintDraftBanner({ send_state: null, updated_at: null, cancelled: true });
      toast('Schedule cancelled — it is in Drafts');
    } catch (e) { toast(e.message, true); }
  }
  /**
   * At the top of the composer, what the opened email is: scheduled (when it goes, held by
   * Outlook or by the app, Cancel schedule), not sent (why), or a saved draft (since when).
   * Hidden for a new email (d null).
   */
  function paintDraftBanner(d) {
    const b = $('composerDraftBanner');
    if (!b) return;
    b.replaceChildren();
    b.classList.remove('scheduled', 'failed');
    if (!d) { b.hidden = true; return; }
    const icon = document.createElement('i');
    icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    if (d.send_state === 'scheduled') {
      b.classList.add('scheduled');
      icon.className = 'fa fa-clock';
      const when = document.createElement('strong');
      when.textContent = formatFull(d.send_at);
      text.append('Scheduled: it goes on ', when, state.draftHeldInOutlook ? ' · Outlook sends it, even if this PC is off' : ' · not with Outlook yet: it is handed over as soon as Outlook can be reached; until then this PC must be on at that time');
      const cancel = document.createElement('button');
      cancel.type = 'button'; cancel.className = 'link-btn draft-unschedule'; cancel.textContent = 'Cancel schedule';
      cancel.addEventListener('click', cancelDraftSchedule);
      b.append(icon, text, cancel);
    } else if (d.send_state === 'failed') {
      b.classList.add('failed');
      icon.className = 'fa fa-triangle-exclamation';
      text.textContent = 'Not sent' + (d.send_error ? ': ' + d.send_error : '') + ' · send it again, or schedule it';
      b.append(icon, text);
    } else {
      icon.className = 'fa fa-file-pen';
      text.textContent = d.cancelled ? 'Draft · the schedule was cancelled' : (d.role === 'editor' ? 'Shared draft by a colleague' : 'Draft') + (d.updated_at ? ' · saved ' + formatFull(d.updated_at) : '');
      b.append(icon, text);
    }
    b.hidden = false;
  }

  function saveDraft() {
    if ($('composerOverlay').hidden) return Promise.resolve();
    if (draftShare.readOnly) return Promise.resolve();   // a colleague holds the shared draft
    const payload = composerPayload();
    draftUnsaved = false;
    if (state.draftScheduledAt) payload.keep_schedule = true;   // a scheduled email stays scheduled while it is edited
    const mailboxId = composerMailboxId;
    const work = draftSaveChain.catch(() => {}).then(async () => {
      try {
        const result = await api('draft_save', { mailbox_id: mailboxId, draft_id: state.draftId || '',
          base_version: draftShare.shared && draftShare.version !== null ? draftShare.version : '' }, { method: 'POST', body: payload });
        state.draftId = result.draft_id;
        if (result.version !== undefined) draftShare.version = result.version;
        if (state.draftScheduledAt) paintDraftSchedule(); else $('draftStatus').textContent = 'Draft saved ' + new Date().toLocaleTimeString();
      } catch (e) {
        // Shared draft: someone else holds it, or changed it first — show theirs, read-only.
        if (e.status === 409 && (e.data?.locked || e.data?.stale)) {
          await refreshSharedDraft(true);
          if (e.data.locked) applyLock(e.data.locked);
          toast(e.message, true);
          return;
        }
        throw e;
      }
    });
    draftSaveChain = work;
    return work;
  }

  // ------------------------------------------------- writing together (shared drafts)

  function resetDraftShare() {
    stopDraftLock(false);
    Object.assign(draftShare, { role: 'owner', shared: false, canSend: true, version: null, readOnly: false });
    setReadOnly(false);
    if ($('composerLockBanner')) $('composerLockBanner').hidden = true;
    if ($('composerPresence')) $('composerPresence').hidden = true;
  }

  /** The Share button (author only) and Send (only someone who may send from the mailbox). */
  function paintShare() {
    const b = $('composerShare');
    if (b) {
      const shareable = !!(state.replyToMessageId || state.composeCaseId);
      b.hidden = !shareable || draftShare.role !== 'owner';
      b.setAttribute('aria-pressed', String(draftShare.shared));
      b.classList.toggle('on', draftShare.shared);
      b.title = draftShare.shared ? 'Shared: colleagues who see this conversation can open and edit it. Click to stop sharing.'
        : 'Write together: let colleagues who see this conversation open and edit this draft';
    }
    $('sendBtn').disabled = !draftShare.canSend || draftShare.readOnly;
    $('sendBtn').title = !draftShare.canSend ? 'Only the owner of this mailbox can send it. Your changes are saved for them.' : '';
  }

  function setReadOnly(on) {
    draftShare.readOnly = on;
    const ws = document.querySelector('.composer-workspace');
    if (ws) ws.inert = on && !sending;
    if (quill) quill.enable(!on);
    $('composerOverlay')?.classList.toggle('read-only', on);
    if ($('sendBtn')) paintShare();
  }

  /** Lock state from the server: who is editing this shared draft. */
  function applyLock(s) {
    if (!s) return;
    draftShare.shared = !!s.shared;
    const banner = $('composerLockBanner');
    const other = s.editing && !s.mine;
    setReadOnly(!!other);
    if (!banner) return;
    banner.hidden = !other && !s.last_edited_by;
    banner.textContent = '';
    banner.classList.toggle('warn', !!other);
    const i = document.createElement('i');
    i.className = 'fa ' + (other ? 'fa-lock' : 'fa-user-pen');
    i.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = other ? s.editing.name + ' is editing this draft. You can read along — it updates by itself.'
      : (s.last_edited_by ? s.last_edited_by + ' also edited this draft.' : '');
    banner.append(i, text);
    if (other) {
      const take = actionButton('Take over', () => lockBeat(true));
      take.className = 'composer-banner-btn';
      banner.appendChild(take);
    }
    paintShare();
  }

  async function lockBeat(take) {
    if (!state.draftId || $('composerOverlay').hidden) return;
    try {
      const s = await api('draft_lock', { draft_id: state.draftId }, { method: 'POST', body: { take: !!take }, noMailbox: true });
      const wasReadOnly = draftShare.readOnly;
      // Someone else saved since we loaded it: show their latest text.
      if (draftShare.version !== null && s.version !== draftShare.version && (wasReadOnly || (s.editing && !s.mine) || take)) await refreshSharedDraft(false);
      draftShare.version = s.version;
      applyLock(s);
      if (take && s.mine) toast('You are editing this draft now');
    } catch (e) { if (e.status === 404) stopDraftLock(false); }
  }
  function startDraftLock() {
    stopDraftLock(false);
    if (!draftShare.shared || !state.draftId) return;
    lockBeat(false);
    draftShare.timer = setInterval(() => { if (!document.hidden) lockBeat(false); }, 6000);
  }
  function stopDraftLock(release) {
    clearInterval(draftShare.timer);
    draftShare.timer = null;
    if (release && draftShare.shared && state.draftId && !draftShare.readOnly) {
      api('draft_unlock', { draft_id: state.draftId }, { method: 'POST', body: {}, noMailbox: true }).catch(() => {});
    }
  }

  /** Load the shared draft's current text into the composer (while someone else edits it). */
  async function refreshSharedDraft(force) {
    if (!state.draftId) return;
    try {
      const d = await api('draft_get', { draft_id: state.draftId }, { noMailbox: true });
      if (!force && d.version === draftShare.version) return;
      fillComposerFromDraft(d);
      draftShare.version = d.version;
    } catch (_) { /* gone: the next beat stops */ }
  }

  async function toggleShare() {
    if (draftShare.role !== 'owner') return;
    try {
      await saveDraft();
      if (!state.draftId) return;
      const s = await api('draft_share', { draft_id: state.draftId }, { method: 'POST', body: { shared: !draftShare.shared }, noMailbox: true });
      draftShare.shared = !!s.shared;
      draftShare.version = s.version;
      paintShare();
      if (draftShare.shared) { startDraftLock(); toast('Shared — colleagues who see this conversation can open it from the email'); }
      else { stopDraftLock(false); toast('No longer shared'); }
    } catch (e) { toast(e.message, true); }
  }

  /**
   * Pop out: save, then carry on writing the same draft in its own browser
   * window (app.php?composer_only&popout). This window lets go of it, so the
   * two never save over each other.
   */
  async function popOutComposer() {
    if (sending || uploading || $('composerOverlay').hidden) return;
    clearTimeout(draftTimer);
    try { await saveDraft(); } catch (e) { toast('Draft could not be saved. Please try again.', true); return; }
    const draftId = state.draftId;
    if (!draftId) return;
    stopDraftLock(true);   // the new window takes the lock itself
    const url = 'app.php?composer_only=1&popout=1&draft=' + encodeURIComponent(draftId) + '&mailbox=' + encodeURIComponent(composerMailboxId);
    const w = window.open(url, 'ecw-draft-' + draftId, 'popup,width=920,height=840');
    if (!w) { toast('The browser blocked the window. Allow pop-ups for this site and try again.', true); return; }
    window.ecwDraftDock?.remove(draftId);
    window.ecwDraftDock?.setActive(null);
    $('composerOverlay').hidden = true;
    state.draftId = null;
    document.dispatchEvent(new CustomEvent('ecw:composer-close'));
    if (window.parent !== window) window.parent.postMessage({type:'ecw-composer-close'}, window.location.origin);
  }

  /** Delete the email being written, now (× → Delete, or Discard). */
  async function discardComposer() {
    if (sending || uploading) return;
    clearTimeout(draftTimer);
    try {
      await draftSaveChain.catch(() => {});
      stopDraftLock(true);
      if (state.draftId) await api('draft_discard', {}, {method:'POST', body:{draft_id:state.draftId}});
      if (state.draftId) window.ecwDraftDock?.remove(state.draftId);
      window.ecwDraftDock?.setActive(null);
      const had = !!state.draftId;
      state.draftId = null;
      if (isPopout) { window.opener?.postMessage({ type: 'ecw-popout-closed' }, window.location.origin); window.close(); return; }
      $('composerOverlay').hidden = true;
      if (had) toast('Deleted');
      if (state.draftsView) selectDrafts();
      document.dispatchEvent(new CustomEvent('ecw:composer-close'));
      if (window.parent !== window) window.parent.postMessage({type:'ecw-composer-close'}, window.location.origin);
    } catch (e) { toast(e.message, true); }
  }

  /** Closing keeps the draft in Drafts (not as a tab at the bottom). */
  async function closeComposer() {
    if (sending || uploading) return;
    clearTimeout(draftTimer);
    try { await saveDraft(); } catch (e) { toast('Draft could not be saved. Please try again.', true); return; }
    stopDraftLock(true);
    if (isPopout) { window.opener?.postMessage({ type: 'ecw-popout-closed' }, window.location.origin); window.close(); return; }
    $('composerOverlay').hidden = true;
    if (state.draftId) { window.ecwDraftDock?.remove(state.draftId); toast(state.draftScheduledAt ? 'Still scheduled for ' + formatFull(state.draftScheduledAt) : 'Saved to Drafts'); }
    window.ecwDraftDock?.setActive(null);
    if (state.draftsView) selectDrafts();
    document.dispatchEvent(new CustomEvent('ecw:composer-close'));
    if (window.parent !== window) window.parent.postMessage({type:'ecw-composer-close'}, window.location.origin);
  }

  /**
   * Minimize: save, then park the draft as a tab at the bottom of the screen
   * (draft-dock.js), like Outlook. Tabs survive moving to Cases, Teams,
   * Settings or another browser tab; clicking one reopens the draft.
   */
  async function minimizeComposer() {
    if (sending || uploading || $('composerOverlay').hidden) return;
    clearTimeout(draftTimer);
    try { await saveDraft(); } catch (e) { toast('Draft could not be saved. Please try again.', true); return; }
    stopDraftLock(true);
    if (state.draftId) {
      window.ecwDraftDock?.add({ id: state.draftId, mailbox_id: composerMailboxId, thread_id: composerThreadId, subject: $('fieldSubject').value, to: $('fieldTo').value });
    }
    window.ecwDraftDock?.setActive(null);
    $('composerOverlay').hidden = true;
    document.dispatchEvent(new CustomEvent('ecw:composer-close'));
    // Inside the case page's composer frame: close that window; the tab lives on in the page's dock.
    if (window.parent !== window) window.parent.postMessage({type:'ecw-composer-close'}, window.location.origin);
  }

  /** Reopen a saved draft exactly as it was left (Drafts list, a dock tab, ?draft=). */
  async function openDraft(draftId) {
    draftId = Number(draftId);
    if (!draftId) return;
    if (!$('composerOverlay').hidden) {
      if (state.draftId === draftId) { $('fieldTo').focus(); return; }
      await minimizeComposer();   // the open one becomes a tab; nothing is lost
    }
    if (composerOpening) return;
    composerOpening = true;
    try {
      const draft = await api('draft_get', { draft_id: draftId }, { noMailbox: true });
      // A reply of another account: open it there (the page follows that account).
      if (!isPopout && window.parent === window && Number(draft.mailbox_id) !== Number(state.mailboxId) && state.mailboxes?.some((m) => Number(m.id) === Number(draft.mailbox_id))) {
        location.href = 'app.php?mailbox=' + encodeURIComponent(draft.mailbox_id) + (draft.thread_id ? '&thread=' + encodeURIComponent(draft.thread_id) : '') + '&draft=' + draftId;
        return;
      }
      // A reply or forward: its conversation opens beside it, as when it was written.
      if (!isPopout && window.parent === window && draft.thread_id && Number(state.threadId) !== Number(draft.thread_id)) {
        try { await openThread(Number(draft.thread_id)); } catch (_) { /* the draft still opens */ }
      }
      clearTimeout(draftTimer);
      resetDraftShare();
      composerMailboxId = Number(draft.mailbox_id);
      state.draftId = draftId;
      state.composeMode = draft.mode || 'new';
      state.replyToMessageId = draft.reply_to_local_message_id ? Number(draft.reply_to_local_message_id) : null;
      state.composeCaseId = draft.case_id ? Number(draft.case_id) : null;
      if ($('replyAssistantBtn')) $('replyAssistantBtn').hidden = !state.replyToMessageId;
      // The signature list is filled by openComposer: a draft opened first needs it too.
      if (!signatureProfiles.length) {
        try {
          const signatures = await api('signature_profiles_get', {});
          signatureProfiles = signatures.profiles || [];
          const select = $('composeSignatureSelect');
          select.replaceChildren(new Option('No signature', ''));
          signatureProfiles.forEach((p) => select.add(new Option(p.name + (p.id === signatures.default_id ? ' · Default' : ''), p.id)));
        } catch (_) { /* the draft still opens */ }
      }
      fillComposerFromDraft(draft);
      state.draftHeldInOutlook = draft.send_state === 'scheduled' && !!draft.outlook_internet_id;
      setDraftSchedule(draft.send_state === 'scheduled' ? draft.send_at : null);
      composeSeenLatest = Number(draft.thread_latest_message_id) || 0;
      Object.assign(draftShare, { role: draft.role || 'owner', shared: !!draft.share?.shared, canSend: draft.can_send !== false, version: draft.share?.version ?? null });
      if (state.draftScheduledAt) paintDraftSchedule();
      else { $('draftStatus').textContent = draft.role === 'editor' ? 'Shared draft by a colleague' : 'Draft reopened'; paintDraftBanner(draft); }
      $('composerOverlay').hidden = false;
      placeComposer(!!draft.thread_id);
      window.ecwDraftDock?.setActive(draftId);
      paintShare();
      if (draftShare.shared) { applyLock(draft.share); startDraftLock(); }
      ($('fieldTo').value ? quill : $('fieldTo')).focus();
      composerThreadId = draft.thread_id ? Number(draft.thread_id) : null;
      document.dispatchEvent(new CustomEvent('ecw:composer-open', { detail: {
        mode: state.composeMode, mailboxId: composerMailboxId, threadId: draft.thread_id || null, caseNumber: '', caseName: '',
      } }));
    } catch (e) {
      window.ecwDraftDock?.remove(draftId);   // sent or discarded elsewhere
      toast(/not found/i.test(e.message) ? 'That draft no longer exists (sent or discarded).' : e.message, true);
    } finally { composerOpening = false; }
  }

  /** Put a saved draft's fields into the composer (opening it, or a colleague's newer version). */
  function fillComposerFromDraft(draft) {
    const emails = (json) => { try { return (JSON.parse(json || '[]') || []).map((r) => r.email).join(', '); } catch (_) { return ''; } };
    $('composerTitle').textContent = (draft.role === 'editor' ? 'Shared draft' : 'Draft') + (draft.subject ? ' · ' + draft.subject : '');
    $('fieldTo').value = emails(draft.to_json);
    $('fieldCc').value = emails(draft.cc_json);
    $('fieldBcc').value = emails(draft.bcc_json);
    $('fieldSubject').value = draft.subject || '';
    $('ccBccFields').hidden = !$('fieldCc').value && !$('fieldBcc').value;

    ensureEditor();
    let parts = null;
    try { parts = draft.parts_json ? JSON.parse(draft.parts_json) : null; } catch (_) { parts = null; }
    // Drafts saved before parts were kept: the whole body goes into the editor.
    quill.clipboard.dangerouslyPasteHTML(parts ? (parts.answer || '<p><br></p>') : (draft.body_html || '<p><br></p>'), 'silent');
    composeSignature = parts ? (parts.signature || '') : '';
    composeQuote = parts && !parts.quote_changed ? (parts.quote || '') : '';
    quoteChanged = !!(parts && parts.quote_changed);
    $('composeQuoteDetails').open = quoteChanged;
    $('composeQuoteDetails').hidden = !(parts && parts.quote);
    if (quoteQuill) quoteQuill.root.innerHTML = parts && parts.quote ? parts.quote : '';
    $('composeSignaturePreview').innerHTML = composeSignature;
    // A signature that came with the email (one written in Outlook) is in the list under its own name.
    if (composeSignature && !signatureProfiles.some((p) => p.html === composeSignature)) {
      signatureProfiles = signatureProfiles.filter((p) => p.id !== 'this-email').concat({ id: 'this-email', name: 'This email’s signature', html: composeSignature });
      const select = $('composeSignatureSelect');
      select.querySelector('option[value="this-email"]')?.remove();
      select.add(new Option('This email’s signature', 'this-email'));
    }
    $('composeSignatureSelect').value = signatureProfiles.find((p) => p.html === composeSignature)?.id || '';
    // A reply or forward shows its conversation beside it (openDraft opened that conversation first).
    fillComposerHistory((draft.mode || 'new') !== 'new' && !!draft.thread_id && Number(state.thread?.id) === Number(draft.thread_id));
    $('composerAttachments').textContent = '';
    (draft.attachments || []).forEach((a) => $('composerAttachments').appendChild(attachmentChip(a)));
  }

  /** The conversation beside a reply / forward in the composer (state.thread), or nothing. */
  function fillComposerHistory(show) {
    const history = $('composerHistory');
    history.textContent = '';
    history.hidden = !show || !state.thread;
    $('composerDivider').hidden = history.hidden;
    if (history.hidden) return;
    for (const message of state.thread.messages) {
      const preview = renderMessage(message); history.appendChild(preview);
      if (message.body_state !== 'fetched' || message.needs_inline_images) {
        api('body', {mailbox_id:composerMailboxId, message_id:message.id, thread_id:state.thread.id, images:1})
          .then(detail => { const loaded = detail.messages.find(m => m.id === message.id); if (loaded && preview.isConnected) preview.replaceWith(renderMessage(loaded)); })
          .catch(() => { const body = preview.querySelector('.message-body'); if (body) body.textContent = 'Content unavailable. Open the original email to retry.'; });
      }
    }
  }
  /** An attachment chip with its remove button. */
  function attachmentChip(entry) {
    const chip = document.createElement('span');
    chip.className = 'attachment';
    chip.dataset.size = String(entry.size || 0);   // the email's files together stay under the limit (attachFiles)
    if (window.ecwFileIcon) chip.appendChild(window.ecwFileIcon(entry.filename, entry.mime_type));
    // Click the name to open it, as an email's file (filePreview.js: preview in the app, else download).
    const open = document.createElement('a');
    open.className = 'attachment att-open';
    open.href = 'api/index.php?action=draft_file&draft_id=' + encodeURIComponent(state.draftId) + '&file=' + encodeURIComponent(entry.id);
    open.dataset.name = entry.filename; open.dataset.mime = entry.mime_type || ''; open.dataset.size = String(entry.size || 0);
    open.title = 'Open ' + entry.filename;
    open.textContent = entry.filename;
    chip.append(open, document.createTextNode(' '));
    const size = document.createElement('span');
    size.className = 'size';
    size.textContent = formatSize(entry.size);
    chip.appendChild(size);
    const remove = actionButton('×', async () => {
      if (sending || uploading) return;
      remove.disabled = true; uploading = true; $('sendBtn').disabled = true;
      try { await api('draft_detach', {}, {method:'POST', body:{draft_id:state.draftId, attachment_id:entry.id}}); chip.remove(); }
      catch (e) { remove.disabled = false; toast(e.message, true); }
      finally { uploading = false; $('sendBtn').disabled = false; }
    });
    remove.className = 'att-remove';
    remove.title = 'Remove this attachment';
    remove.setAttribute('aria-label', 'Remove ' + entry.filename);
    chip.appendChild(remove);
    return chip;
  }

  // The files of one email together, at most (lib/compose.php ECW_MAX_ATTACHMENT_BYTES).
  const MAX_ATTACH_BYTES = 25 * 1024 * 1024;

  /** POST a file with upload progress (fetch cannot report it). Resolves like api(). */
  function uploadWithProgress(action, params, form, onProgress) {
    return new Promise((resolve, reject) => {
      const url = new URL('api/index.php', window.location.href);
      url.searchParams.set('action', action);
      if (state.mailboxId) url.searchParams.set('mailbox_id', String(state.mailboxId));
      Object.keys(params).forEach((k) => url.searchParams.set(k, String(params[k])));
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url.toString());
      xhr.setRequestHeader('X-ECW-CSRF', state.csrf);
      xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      xhr.onload = () => {
        let data; try { data = xhr.responseText ? JSON.parse(xhr.responseText) : {}; } catch (_) { data = { error: 'bad response' }; }
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else reject(new Error(data.error || (xhr.status === 413 ? 'This file is too large to attach.' : 'The file could not be attached (' + xhr.status + ').')));
      };
      xhr.onerror = () => reject(new Error('The upload broke off. Check the connection and attach the file again.'));
      xhr.send(form);
    });
  }

  /** A chip for a file on its way up: its name and a bar that fills. */
  function uploadingChip(file) {
    const chip = document.createElement('span');
    chip.className = 'attachment is-uploading';
    chip.setAttribute('role', 'progressbar'); chip.setAttribute('aria-valuemin', '0'); chip.setAttribute('aria-valuemax', '100');
    chip.setAttribute('aria-label', 'Uploading ' + file.name);
    if (window.ecwFileIcon) chip.appendChild(window.ecwFileIcon(file.name, file.type));
    chip.appendChild(document.createTextNode(file.name + ' '));
    const pct = document.createElement('span'); pct.className = 'size'; pct.textContent = '0%';
    const bar = document.createElement('span'); bar.className = 'att-progress';
    chip.append(pct, bar);
    chip.progress = (f) => { const p = Math.round(f * 100); pct.textContent = p + '%'; bar.style.width = p + '%'; chip.setAttribute('aria-valuenow', String(p)); };
    return chip;
  }

  async function attachFiles(files) {
    if (sending || uploading) return;
    uploading = true; $('sendBtn').disabled = true;
    try { await saveDraft(); } catch (e) { uploading = false; $('sendBtn').disabled = false; toast('Save the draft before attaching files.', true); return; }
    const mb = Math.round(MAX_ATTACH_BYTES / 1024 / 1024);
    for (const file of files) {
      const already = [...$('composerAttachments').querySelectorAll('.attachment[data-size]')].reduce((n, c) => n + Number(c.dataset.size || 0), 0);
      if (file.size > MAX_ATTACH_BYTES) { toast('“' + file.name + '” is larger than ' + mb + ' MB, more than an email can carry. Share it as a link instead.', true); continue; }
      if (already + file.size > MAX_ATTACH_BYTES) { toast('With “' + file.name + '” the email would carry over ' + mb + ' MB of files, more than most mail servers accept. Send it in a second email, or share it as a link.', true); continue; }
      const form = new FormData();
      form.append('file', file);
      const pending = uploadingChip(file);
      $('composerAttachments').appendChild(pending);
      try {
        const entry = await uploadWithProgress('draft_attach', { draft_id: state.draftId }, form, pending.progress);
        pending.replaceWith(attachmentChip(entry));
      } catch (e) {
        pending.remove();
        toast(e.message, true);
      }
    }
    uploading = false; $('sendBtn').disabled = false;
  }

  /**
   * Send = queue it ECW_UNDO_SEND_SECONDS ahead, so "Undo" can still stop it;
   * sendAt (a Date) = Send later. The sync daemon sends it when due.
   */
  async function send(sendAt, force) {
    if (sending || uploading || draftShare.readOnly) return;
    const payload = composerPayload();
    const answer = (quill?.root.innerHTML || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
    if (!answer && !/<img\b/i.test(quill?.root.innerHTML || '') && !quoteChanged && ['reply', 'reply_all'].includes(state.composeMode)) { toast('Write your answer or expand … to add inline comments before sending.', true); quill?.focus(); return; }
    if (!payload.to.length && !payload.cc.length && !payload.bcc.length) { toast('Add at least one recipient', true); return; }
    sending = true;
    document.querySelector('.composer-workspace').inert = true;
    clearTimeout(draftTimer);
    $('sendBtn').disabled = true;
    $('sendBtn').textContent = 'Sending…';
    $('sendProgress').hidden = false;
    $('composerOverlay').setAttribute('aria-busy', 'true');
    try {
      await saveDraft();
      const draftId = state.draftId;
      let r;
      try {
        r = await api('send', {mailbox_id:composerMailboxId}, { method: 'POST', body: {
          draft_id: draftId, send_at: sendAt ? sendAt.toISOString() : '', seen_message_id: composeSeenLatest || 0, force: !!force } });
      } catch (e) {
        // Someone answered while this was being written: ask before sending.
        if (e.status === 409 && e.data?.collision) { collisionPrompt(e.data.collision, sendAt); return; }
        throw e;
      }
      stopDraftLock(true);
      $('composerOverlay').hidden = true;
      if (draftId) window.ecwDraftDock?.remove(draftId);
      window.ecwDraftDock?.setActive(null);
      state.draftId = null;
      setDraftSchedule(null);
      if (state.draftsView) selectDrafts();
      document.dispatchEvent(new CustomEvent('ecw:composer-close'));
      if (window.parent !== window) {
        // The case page shows the Undo itself (the composer frame closes now).
        window.parent.postMessage({ type: 'ecw-composer-sent', draft_id: draftId, send_at: r.send_at, undo_seconds: r.undo_seconds, held_in_outlook: !!r.held_in_outlook }, window.location.origin);
      } else if (isPopout && window.opener && !window.opener.closed) {
        // Its own window: the main window shows "Sending… Undo", this one closes.
        window.opener.postMessage({ type: 'ecw-composer-sent', draft_id: draftId, send_at: r.send_at, undo_seconds: r.undo_seconds, held_in_outlook: !!r.held_in_outlook }, window.location.origin);
        window.close();
      } else if (isPopout) {
        // The main window is gone: keep this one open for the Undo, then close.
        sendToast(draftId, r);
        setTimeout(() => window.close(), ((r.undo_seconds || 0) + 3) * 1000);
      } else {
        sendToast(draftId, r);
      }
      if (state.threadId && !sendAt) setTimeout(() => openThread(state.threadId, { preserveScroll: true }), (r.undo_seconds || 0) * 1000 + 4000);
    } catch (e) { toast(e.message, true); }
    finally {
      document.querySelector('.composer-workspace').inert = false;
      sending = false; $('sendBtn').disabled = false; $('sendBtn').textContent = 'Send';
      $('sendProgress').hidden = true; $('composerOverlay').removeAttribute('aria-busy');
    }
  }

  /**
   * A newer email arrived in the conversation while this reply was written
   * (often a colleague answering first). Show it; Send anyway or go and read it.
   */
  function collisionPrompt(c, sendAt) {
    const box = $('composerPresence');
    if (!box) return;
    box.textContent = '';
    box.hidden = false;
    box.classList.add('warn');
    const i = document.createElement('i');
    i.className = 'fa fa-triangle-exclamation';
    i.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    const who = c.direction === 'outgoing' ? (c.from || 'A colleague') + ' already replied' : (c.from || 'Someone') + ' wrote again';
    text.textContent = who + ' ' + formatCompact(c.at) + (c.snippet ? ': “' + c.snippet + '”' : '') + ' — still send yours?';
    const go = actionButton('Send anyway', () => { box.hidden = true; box.classList.remove('warn'); send(sendAt, true); });
    go.className = 'composer-banner-btn danger';
    const read = actionButton('Read it first', async () => {
      box.hidden = true; box.classList.remove('warn');
      composeSeenLatest = 0;   // it is read now: the next Send goes through
      await minimizeComposer();
      if (state.threadId) openThread(state.threadId, { preserveScroll: true });
    });
    read.className = 'composer-banner-btn';
    box.append(i, text, go, read);
  }

  /** "Sending… Undo" for the Undo window, or "Scheduled for … Cancel". */
  function sendToast(draftId, r) {
    const el = document.getElementById('sendToast') || Object.assign(document.createElement('div'), { id: 'sendToast', className: 'undo-toast' });
    if (!el.isConnected) { el.setAttribute('role', 'status'); document.body.appendChild(el); }
    clearInterval(el._timer);
    const label = document.createElement('span');
    const undo = document.createElement('button');
    undo.type = 'button';
    el.replaceChildren(label, undo);
    el.hidden = false;
    if (r.undo_seconds) {
      let left = r.undo_seconds;
      const paint = () => { label.textContent = 'Sending in ' + left + ' s'; };
      paint();
      undo.textContent = 'Undo';
      el._timer = setInterval(() => {
        left--;
        if (left > 0) { paint(); return; }
        clearInterval(el._timer);
        label.textContent = 'Message sent'; undo.remove();
        setTimeout(() => { el.hidden = true; }, 2500);
      }, 1000);
    } else {
      label.textContent = 'Scheduled for ' + new Date(String(r.send_at).replace(' ', 'T') + 'Z').toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
      if (r.held_in_outlook) label.textContent += ' · Outlook sends it, even if this PC is off';
      undo.textContent = 'Cancel';
      el._timer = setTimeout(() => { el.hidden = true; }, 9000);
    }
    undo.addEventListener('click', async () => {
      clearInterval(el._timer); clearTimeout(el._timer);
      try {
        const c = await api('send_cancel', {}, { method: 'POST', body: { draft_id: draftId } });
        el.hidden = true;
        if (c.cancelled) { toast(r.undo_seconds ? 'Not sent — it is back in the composer' : 'Schedule cancelled — it is in Drafts'); if (r.undo_seconds) openDraft(draftId); }
        else toast('Too late — it was already sent', true);
      } catch (e) { toast(e.message, true); }
    });
  }

  // Send later menu: Tomorrow 8:00, Monday 8:00, or a chosen time.
  (function sendLater() {
    const btn = $('sendLaterBtn'), menu = $('sendLaterMenu');
    if (!btn) return;
    const at = (kind) => {
      const d = new Date();
      if (kind === 'tomorrow8') { d.setDate(d.getDate() + 1); d.setHours(8, 0, 0, 0); }
      if (kind === 'monday8') { d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(8, 0, 0, 0); }
      return d;
    };
    const fmt = (d) => d.toLocaleString([], { weekday: 'long', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    const open = (on) => { menu.hidden = !on; btn.setAttribute('aria-expanded', String(on)); };
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      menu.querySelector('[data-later="tomorrow8"]').textContent = 'Tomorrow · ' + fmt(at('tomorrow8'));
      menu.querySelector('[data-later="monday8"]').textContent = 'Monday · ' + fmt(at('monday8'));
      const soon = new Date(Date.now() + 3600000); soon.setMinutes(0, 0, 0);
      const pad = (n) => String(n).padStart(2, '0');
      $('sendLaterAt').value = soon.getFullYear() + '-' + pad(soon.getMonth() + 1) + '-' + pad(soon.getDate()) + 'T' + pad(soon.getHours()) + ':00';
      open(menu.hidden);
    });
    menu.querySelectorAll('[data-later]').forEach((b) => b.addEventListener('click', () => { open(false); send(at(b.dataset.later)); }));
    $('sendLaterPick').addEventListener('click', () => {
      const v = $('sendLaterAt').value;
      const d = v ? new Date(v) : null;
      if (!d || isNaN(d) || d < new Date()) { toast('Choose a time in the future', true); return; }
      open(false); send(d);
    });
    document.addEventListener('click', (e) => { if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) open(false); });
  })();

  // ----------------------------------------------------------------- cases

  let casePickerStatus = 'open', caseSearchSequence = 0;
  async function searchCases(term) {
    const sequence = ++caseSearchSequence;
    const results = $('caseResults');
    results.textContent = '';
    try {
      const data = await api('cases', { q: term || '', status: casePickerStatus });
      if (sequence !== caseSearchSequence) return;
      data.cases.forEach((kase) => {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = kase.case_number + ' · ' + kase.name + ' (' + kase.status + ')';
        button.addEventListener('click', () => linkCase(Number(kase.id), null));
        li.appendChild(button);
        results.appendChild(li);
      });
      if (!data.cases.length) {
        const li = document.createElement('li');
        li.className = 'muted';
        li.textContent = 'No matching case';
        results.appendChild(li);
      }
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function linkCase(caseId, name) {
    try {
      await api('case_link', {}, {
        method: 'POST',
        body: { thread_id: state.threadId, case_id: caseId || 0, name: name || '', template_id: caseId ? 0 : Number($('newCaseTemplate')?.value || 0),
          group_id: caseId ? 0 : Number($('newCaseGroup')?.value || 0) },
      });
      $('caseOverlay').hidden = true;
      toast(caseId ? 'Linked to case' : 'Case started');
      await threadCaseChanged(state.threadId);
    } catch (e) {
      toast(e.message, true);
    }
  }

  /**
   * A conversation joined or left a case: the open conversation is fetched fresh (not from the
   * page's copy, which still has the old case) and its row in the list shows the case at once,
   * wherever it is in the list; the list is then refreshed quietly behind it.
   */
  async function threadCaseChanged(threadId) {
    threadId = Number(threadId);
    forgetThread(threadId);
    let kase;
    if (Number(state.threadId) === threadId) {
      await openThread(threadId, { preserveScroll: true });
      kase = state.thread && Number(state.thread.id) === threadId ? (state.thread.case || null) : undefined;
    } else {
      try { kase = (await api('thread', threadParams(threadId))).case || null; } catch (_) { kase = undefined; }
    }
    if (kase !== undefined) {
      document.querySelectorAll('.thread-row[data-thread-id="' + threadId + '"]').forEach((row) => {
        row._thread = Object.assign({}, row._thread, { case: kase });
        row.querySelector('.row-markers .case-badge')?.remove();
        let markers = row.querySelector('.row-markers');
        if (kase) {
          if (!markers) { markers = document.createElement('div'); markers.className = 'row-markers'; row.appendChild(markers); }
          const badge = document.createElement('span');
          badge.className = 'case-badge';
          badge.textContent = kase.number + ' · ' + kase.name;
          markers.insertBefore(badge, markers.querySelector('.claim-badge'));
        } else if (markers && !markers.children.length) markers.remove();
      });
    }
    smoothRefreshList();
  }

  // ------------------------------------------------------------- signature

  async function openSignature() {
    try {
      const signature = await api('signature_get', {});
      $('sigName').value = signature.display_name || '';
      $('sigTitle').value = signature.title || '';
      $('sigCompany').value = signature.company || '';
      $('sigPhone').value = signature.phone || '';

      $('signatureOverlay').hidden = false;
      if (!sigQuill) {
        sigQuill = new Quill('#sigEditor', {
          theme: 'snow',
          modules: { toolbar: [fontPickers(), ['bold', 'italic', 'underline'], ['link', 'image'], ['clean']] },
        });
        showFontUnderCursor(sigQuill);
      }
      sigQuill.root.innerHTML = signature.signature_html || '';
    } catch (e) {
      toast(e.message, true);
    }
  }

  // ---------------------------------------------------------------- events

  // ----------------------------------------------------------- live updates

  /**
   * Mail arrives on its own.
   *
   * The sync daemon writes new mail into the database; this polls a tiny
   * change-signal endpoint and only refetches the real list when that signal
   * moves. An idle mailbox therefore costs one indexed aggregate every few
   * seconds, not a page of threads.
   *
   * Polling, not a websocket: the server has no push channel, and adding one to
   * shave a few seconds off a mailbox that already only changes when Microsoft
   * says so would be a lot of moving parts for very little.
   */
  const LIVE_POLL_MS = 8000;

  function startLiveUpdates() {
    setInterval(pollUpdates, LIVE_POLL_MS);
    pollUpdates();   // the spinners and the import bar right away, not 8 seconds later

    // A hidden tab is not worth polling, and a returning user wants the truth
    // immediately rather than up to LIVE_POLL_MS later.
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollUpdates();
    });
  }

  async function pollUpdates() {
    if (document.hidden || !state.mailboxId) return;

    try {
      const askedFor = state.threadId || null;
      const data = await api('updates', { thread_id: askedFor || '' });
      document.dispatchEvent(new CustomEvent('ecw:updates', { detail: data }));

      if (data.mailbox && data.mailbox.sync_status) {
        renderMailboxStatus(data.mailbox);
      }
      renderSyncActivity(data.sync);

      // First poll only establishes the baseline; there is nothing to refresh.
      if (state.liveSignature === null) {
        state.liveSignature = data.signature;
        state.liveThread = data.thread;
        state.liveThreadId = askedFor;
        return;
      }

      // Compare the open conversation with itself only: a reader who just opened
      // another one was redrawn (and thrown back up) by the old one's value.
      if (askedFor && askedFor === state.threadId) {
        if (state.liveThreadId !== askedFor) {
          state.liveThreadId = askedFor;
          state.liveThread = data.thread;
        } else if (data.thread && data.thread !== state.liveThread) {
          state.liveThread = data.thread;
          await openThread(askedFor, { preserveScroll: true });
        }
      }

      if (data.signature !== state.liveSignature) {
        state.liveSignature = data.signature;
        await refreshListLive();
        loadFolders().catch(() => {});
      }
    } catch (e) {
      // A failed poll is not worth a toast — it will simply try again.
    }
  }

  /**
   * Bring the list up to date without yanking the page.
   *
   * If the user is at the top, refresh in place. If they have scrolled down —
   * possibly pages deep, which a reset would discard — offer a button instead.
   * Rewriting the list under someone who is reading it is the behaviour that
   * makes people distrust a mail client.
   */
  async function refreshListLive() {
    const pane = $('listPane');
    if (pane.scrollTop > 150) {
      showNewMailPill();
      return;
    }

    // Throttle while a mailbox imports: the signal moves on every check then,
    // and redrawing every 8 seconds only makes the list jump.
    if (state.sync && state.sync.importing && Date.now() - (state.lastListRefresh || 0) < IMPORT_REFRESH_MS) {
      state.listRefreshPending = true;
      return;
    }
    await smoothRefreshList();
  }

  const IMPORT_REFRESH_MS = 30000;

  /**
   * The first page again, built off screen and swapped in at once: the list is
   * never emptied, so it never flashes "Loading". The open conversation stays
   * selected. Drafts and an empty list keep the ordinary path.
   */
  /** The row at the top of the list's view, and how far from the top it sits. */
  function listAnchor() {
    const pane = $('listPane');
    if (!pane) return null;
    const top = pane.getBoundingClientRect().top;
    for (const r of $('threadList').querySelectorAll('.thread-row')) {
      const box = r.getBoundingClientRect();
      if (box.height && box.bottom > top + 1) return { id: Number(r.dataset.threadId), offset: box.top - top, scroll: pane.scrollTop };
    }
    return { id: 0, offset: 0, scroll: pane.scrollTop };
  }
  /** Scroll so that row is where it was. @return whether the row is in the list */
  function restoreListAnchor(a) {
    if (!a) return false;
    const pane = $('listPane');
    const row = a.id ? $('threadList').querySelector('.thread-row[data-thread-id="' + a.id + '"]') : null;
    if (!row) { if (!a.id) pane.scrollTop = a.scroll || 0; return !a.id; }
    pane.scrollTop += (row.getBoundingClientRect().top - pane.getBoundingClientRect().top) - a.offset;
    return true;
  }

  /**
   * Where the person was in Mail (folder, scroll position, open email), kept
   * for this browser tab: a reload or coming back from Cases opens it there
   * again instead of at the top of the Inbox.
   */
  const POS_KEY = () => 'ecw.mailpos.' + state.mailboxId;
  let posTimer = null;
  function saveListPos() {
    clearTimeout(posTimer);
    posTimer = setTimeout(() => {
      if (!state.mailboxId || state.draftsView || state.query) return;
      try {
        sessionStorage.setItem(POS_KEY(), JSON.stringify({ folderId: state.folderId, folderType: state.folderType, title: state.listTitle,
          snoozed: !!state.snoozed, anchor: listAnchor(), open: state.threadId || null, at: Date.now() }));
      } catch (_) {}
    }, 250);
  }
  /** Back where the person was (after a reload). @return whether it did */
  async function restoreListPos() {
    let pos = null;
    try { pos = JSON.parse(sessionStorage.getItem(POS_KEY()) || 'null'); } catch (_) {}
    if (!pos || Date.now() - (pos.at || 0) > 12 * 3600 * 1000) return false;
    if (pos.folderId && !document.querySelector('.nav-item[data-folder-id="' + pos.folderId + '"]')) return false;   // folder gone
    if (pos.folderId !== state.folderId || !!pos.snoozed !== !!state.snoozed) {
      state.folderId = pos.folderId; state.folderType = pos.folderType; state.listTitle = pos.title || state.listTitle; state.snoozed = !!pos.snoozed;
      highlightNav();
      await resetList();
    }
    // Load pages until the row that was at the top is there (at most 20 pages).
    const a = pos.anchor;
    for (let i = 0; a && a.id && i < 20 && !$('threadList').querySelector('.thread-row[data-thread-id="' + a.id + '"]') && !state.exhausted; i++) {
      await loadMore();
    }
    restoreListAnchor(a);
    if (pos.open && $('threadList').querySelector('.thread-row[data-thread-id="' + pos.open + '"]') && window.innerWidth > 860) {
      await openThread(Number(pos.open));
      restoreListAnchor(a);
    }
    return true;
  }

  async function smoothRefreshList() {
    state.lastListRefresh = Date.now();
    state.listRefreshPending = false;
    if (state.draftsView || !rowCount()) return resetList();
    if (state.loading || !state.mailboxId) return;
    state.loading = true;
    const forMailbox = state.mailboxId, forFolder = state.folderId, forQuery = currentQuery();
    try {
      const data = await api('threads', {
        ...listScope(), q: forQuery, cursor: null,
        snoozed: state.snoozed ? 1 : '', claim: state.claim || '',
      });
      // The person switched folder or mailbox meanwhile: this answer is stale.
      if (state.mailboxId !== forMailbox || state.folderId !== forFolder || currentQuery() !== forQuery) return;
      // Keep what was scrolled to: the first page is new, the older rows already
      // loaded below it stay, and the row at the top of the view stays where it was.
      const anchor = listAnchor();
      const page = data.threads;
      const ids = new Set(page.map((t) => Number(t.id)));
      const oldest = page.length ? String(page[page.length - 1].latest_at || '') : '';
      const loaded = [...$('threadList').querySelectorAll('.thread-row')].filter((r) => !r.closest('li')?.hidden).map((r) => r._thread).filter(Boolean);
      const below = data.next_cursor ? loaded.filter((t) => !ids.has(Number(t.id)) && String(t.latest_at || '') <= oldest) : [];
      const fresh = document.createDocumentFragment();
      drawTopResults(fresh, forQuery ? data.top : []);
      page.concat(below).forEach((t) => appendThreadRow(t, fresh));
      $('threadList').replaceChildren(fresh);
      $('threadList')._lastGroup = fresh._lastGroup;
      $('threadList')._lastHeader = fresh._lastHeader;
      if (!below.length) { state.cursor = data.next_cursor; state.exhausted = !data.next_cursor; }
      restoreListAnchor(anchor);
      const count = rowCount();
      $('listCount').textContent = count ? count + (state.exhausted ? '' : '+') : '';
      $('listFooter').textContent = count === 0 ? 'Nothing here' : (state.exhausted ? '' : 'Scroll for more');
      const activeId = state.threadId;
      if (activeId) {
        document.querySelectorAll('.thread-row').forEach((el) => el.classList.toggle('active', Number(el.dataset.threadId) === activeId));
      }
    } catch (e) {
      // Not worth a toast: the next check tries again, and the old list is still there.
    } finally {
      state.loading = false;
    }
  }

  // ------------------------------------------------- sync activity (spinners)

  /**
   * The Sync button turns while the mailbox imports or a sync someone asked for
   * runs; so does each folder still importing. An import bar says how far it is.
   * All of it goes away only when the sync is complete (lib/sync/activity.php).
   */
  function renderSyncActivity(sync) {
    state.sync = sync || null;
    const busy = !!(sync && sync.busy);
    const btn = $('syncNowBtn');
    if (btn) {
      btn.classList.toggle('is-syncing', busy);
      // When it stops: the import (last 2 years, texts, attachments) and any sync asked for are
      // done. "Up to date" says when every folder last asked Outlook for changes (every 15 s);
      // a folder not checked for 5 minutes is named, with a dot on the button.
      const hm = (utc) => { const d = new Date(String(utc).replace(' ', 'T') + 'Z'); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };
      const behind = (sync && sync.behind) || [];
      btn.classList.toggle('is-behind', !busy && behind.length > 0);
      btn.title = busy ? 'Synchronizing… (click to sync again)'
        : behind.length ? 'Not up to date: ' + behind.slice(0, 3).map((f) => f.name + (f.checked_at ? ' (last checked ' + hm(f.checked_at) + ')' : ' (not checked yet)')).join(', ')
            + (behind.length > 3 ? ' and ' + (behind.length - 3) + ' more' : '') + '. It keeps trying; click to sync now.'
        : sync && sync.checked_at ? 'Up to date · checked ' + hm(sync.checked_at) + ' · click to sync now'
        : 'Sync now';
      btn.setAttribute('aria-label', btn.title);
      btn.setAttribute('aria-busy', String(busy));
    }
    const busyFolders = new Set((sync && sync.folders) || []);
    document.querySelectorAll('#systemFolders .nav-item[data-folder-id], #userFolders .nav-item[data-folder-id]').forEach((b) => {
      b.classList.toggle('is-syncing', busyFolders.has(Number(b.dataset.folderId)));
    });
    const bar = $('importBar');
    if (bar) {
      const importing = !!(sync && sync.importing);
      const older = !importing && !!(sync && sync.older_importing);
      bar.hidden = !importing && !older;
      bar.classList.toggle('is-quiet', older);
      if (older) {
        // The last two years are complete; older emails keep coming without anyone waiting.
        bar.querySelector('.import-text').textContent = 'The last 2 years are in. Older emails are importing in the background… ' + Number(sync.messages || 0).toLocaleString() + ' emails so far';
      }
      if (importing) {
        const left = (sync.bodies_left || 0) + (sync.attachments_left || 0);
        const n = Number(sync.conversations || 0).toLocaleString();
        bar.querySelector('.import-text').textContent = !sync.history_done
          ? 'Importing the last 2 years… ' + n + ' conversations so far'
          : 'Finishing the import… ' + left.toLocaleString() + ' emails still to complete';
      }
    }
    // The list caught up only partly while importing: one last refresh when it ends.
    if (sync && !sync.importing && state.listRefreshPending) smoothRefreshList();
  }

  async function syncNow() {
    const btn = $('syncNowBtn');
    if (!btn || !state.mailboxId) return;
    btn.classList.add('is-syncing');
    try {
      const r = await api('mailbox_sync', { mailbox_id: state.mailboxId }, { method: 'POST' });
      renderSyncActivity(r.sync);
      toast('Synchronizing. The button turns until it is complete.');
    } catch (e) {
      btn.classList.remove('is-syncing');
      toast(e.message, true);
    }
  }

  function showNewMailPill() {
    if ($('newMailPill')) return;

    const pill = document.createElement('button');
    pill.id = 'newMailPill';
    pill.type = 'button';
    pill.className = 'new-mail-pill';
    pill.textContent = '↑ New mail';
    pill.addEventListener('click', async () => {
      pill.remove();
      $('listPane').scrollTop = 0;
      await refreshListLive();
    });
    $('listPane').prepend(pill);
  }

  function syncFilterChips() {
    document.querySelectorAll('.chip, .rail-filter').forEach((chip) => {
      chip.classList.toggle('active', state.filters.has(chip.dataset.filter));
    });
  }

  /** Narrow screens: folders/cases collapse into a drawer over List + Reading. */
  function closeNavDrawer() {
    document.body.classList.remove('nav-open', 'details-open');
    $('navBackdrop').hidden = true;
    $('detailsToggleBtn')?.setAttribute('aria-expanded', 'false');
  }

  function wire() {
    $('syncNowBtn')?.addEventListener('click', syncNow);
    // Help is in the profile menu (partials/nav.php), on every page.
    $('threadList').addEventListener('click', (e) => {
      const header = e.target.closest('.list-group-toggle')?.closest('li.list-group');
      if (header) toggleGroup(header);
    });
    $('navToggle').addEventListener('click', () => {
      document.body.classList.remove('details-open');
      const open = document.body.classList.toggle('nav-open');
      $('navBackdrop').hidden = !open;
    });
    $('navBackdrop').addEventListener('click', closeNavDrawer);
    // Narrow screens (≤ 980px): thread details slide in from the right instead of taking a column.
    $('detailsToggleBtn').addEventListener('click', () => {
      document.body.classList.remove('nav-open');
      const open = document.body.classList.toggle('details-open');
      $('navBackdrop').hidden = !open;
      $('detailsToggleBtn').setAttribute('aria-expanded', String(open));
    });
    $('detailsCloseBtn').addEventListener('click', closeNavDrawer);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && document.body.matches('.nav-open, .details-open')) closeNavDrawer();
    });

    $('searchForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      document.body.classList.remove('reading-open');
      state.query = $('searchInput').value.trim();
      $('searchClear').hidden = !state.query;
      await resetList();
    });

    $('searchScopeBtn')?.addEventListener('click', (e) => {
      e.preventDefault();
      if ($('searchScopeMenu').hidden) openSearchScopeMenu(); else closeSearchScopeMenu();
    });
    document.addEventListener('pointerdown', (e) => {
      if (!$('searchScopeMenu') || $('searchScopeMenu').hidden) return;
      if (!e.target.closest('#searchScopeMenu, #searchScopeBtn')) closeSearchScopeMenu();
    }, true);
    $('searchScopeMenu')?.addEventListener('keydown', (e) => {
      const items = [...$('searchScopeMenu').querySelectorAll('.search-scope-item')];
      const i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { e.preventDefault(); closeSearchScopeMenu(); $('searchScopeBtn').focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); items[Math.min(items.length - 1, i + 1)]?.focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[Math.max(0, i - 1)]?.focus(); }
    });

    $('searchClear').addEventListener('click', async () => {
      document.body.classList.remove('reading-open');
      $('searchInput').value = '';
      ['filterFrom','filterTo','filterCc','filterSubject','filterFilename','filterExt','filterAfter','filterBefore','filterCase','filterRead','filterDirection','filterLarger'].forEach(id => { if ($(id)) $(id).value = ''; });
      $('filterAttachment').checked = false;
      if ($('filterFlagged')) $('filterFlagged').checked = false;
      state.query = '';
      $('searchClear').hidden = true;
      await resetList();
    });

    document.querySelectorAll('.chip, .rail-filter, .nav-item[data-filter]').forEach((el) => {
      el.addEventListener('click', async () => {
        const filter = el.dataset.filter;
        state.filters.has(filter) ? state.filters.delete(filter) : state.filters.add(filter);
        syncFilterChips();
        closeNavDrawer();
        await resetList();
      });
    });

    $('mailboxSelect').addEventListener('change', async (event) => {
      document.body.classList.remove('reading-open');
      state.mailboxId = Number(event.target.value);
      mailboxChanged();
      await loadFolders();
      await resetList();
    });

    $('threadOrder').addEventListener('change', async (event) => {
      state.prefs.thread_order = event.target.value;
      api('prefs_save', {}, { method: 'POST', body: { thread_order: event.target.value } }).catch(() => {});
      if (state.threadId) await openThread(state.threadId);
    });

    $('toggleCcBcc').addEventListener('click', () => {
      $('ccBccFields').hidden = !$('ccBccFields').hidden;
    });

    $('mailAssistantBtn')?.addEventListener('click', () => window.ecwOpenAssistant({mailboxId:state.mailboxId, mode:'ask'}));
    // Draft with AI: writes the reply straight into the box. Notes already typed
    // there ("yes, 450 €, Friday") are the instructions and become the reply;
    // an empty box gets a reply to the email. Ctrl+Z brings back what was there.
    $('replyAssistantBtn')?.addEventListener('click', async () => {
      const btn = $('replyAssistantBtn');
      if (btn.disabled || !state.replyToMessageId) return;
      const notes = quill.getText().trim();
      const label = btn.innerHTML;
      btn.disabled = true;
      btn.classList.add('is-writing');
      btn.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> Writing…';
      $('editor').classList.add('ai-writing');
      try {
        const r = await api('ai_assist', { mailbox_id: composerMailboxId }, { method: 'POST', body: {
          mode: 'reply', message_id: state.replyToMessageId,
          question: notes ? 'Write the full reply from these notes of mine, keeping every fact in them: ' + notes.slice(0, 900) : 'Draft a reply to this email.',
        } });
        const text = String(r.text || '').replace(/\*\*/g, '').replace(/^\s*(subject|θέμα)\s*:.*\n+/i, '').trim();
        if (!text) throw new Error('The AI returned no reply. Try again.');
        quill.setText(text + '\n', 'user');
        quill.setSelection(quill.getLength(), 0, 'silent');
        quill.focus(); scheduleDraftSave();
        toast(notes ? 'Your notes are now a full reply. Ctrl+Z to undo.' : 'Reply drafted — read it and change what you like.');
      } catch (e) {
        toast(e.message, true);
      } finally {
        btn.disabled = false;
        btn.classList.remove('is-writing');
        btn.innerHTML = label;
        $('editor').classList.remove('ai-writing');
      }
    });
    window.addEventListener('ecw-ai-insert', event => {
      const data = event.detail;
      if ($('composerOverlay').hidden || composerMailboxId !== data.mailboxId || state.replyToMessageId !== data.messageId) {
        toast('Reopen the original reply to insert this suggestion.', true); return;
      }
      const position = Math.max(0, quill.getLength() - 1);
      quill.insertText(position, (position ? '\n\n' : '') + data.text, 'user');
      quill.focus(); scheduleDraftSave();
    });
    $('composeBtn').addEventListener('click', () => openComposer('new', null));
    // × asks what to do with it: keep it in Drafts, or delete it now (confirm-button.js).
    // An empty email has nothing to keep: it just closes.
    $('composerClose').addEventListener('click', () => {
      if (sending || uploading) return;
      const empty = !$('fieldTo').value.trim() && !$('fieldSubject').value.trim() && !quill.getText().trim() && !$('composerAttachments')?.children.length;
      if (empty) { discardComposer(); return; }
      if (state.draftScheduledAt) { closeComposer(); return; }   // scheduled: it stays scheduled (closeComposer says so)
      window.ecwChoice($('composerClose'), [
        { label: 'Save to Drafts', icon: 'fa-floppy-disk', primary: true, onPick: closeComposer },
        { label: 'Delete', icon: 'fa-trash-can', danger: true, onPick: discardComposer },
      ]);
    });
    $('composerMinimize')?.addEventListener('click', minimizeComposer);
    $('composerPopout')?.addEventListener('click', popOutComposer);
    $('composerShare')?.addEventListener('click', toggleShare);
    window.addEventListener('pagehide', () => stopDraftLock(true));
    if (isPopout) {
      // The window's title follows the subject; closing the window saves the draft first.
      const title = () => { document.title = ($('fieldSubject').value || 'New message') + ' — Case Workspace'; };
      $('fieldSubject').addEventListener('input', title);
      document.addEventListener('ecw:composer-open', title);
      setTimeout(title, 1500);
      window.addEventListener('beforeunload', (e) => {
        if ($('composerOverlay').hidden || sending) return;
        clearTimeout(draftTimer);
        saveDraft().catch(() => {});
        window.opener?.postMessage({ type: 'ecw-popout-closed' }, window.location.origin);
      });
    }

    // Keep the email being written alive when leaving the mail page: an in-app
    // link first saves and parks it as a tab, then navigates.
    document.addEventListener('click', async (e) => {
      const link = e.target.closest('a[href]');
      if (!link || $('composerOverlay').hidden || sending || e.defaultPrevented || e.button !== 0
          || e.ctrlKey || e.metaKey || e.shiftKey || link.target === '_blank' || link.hasAttribute('download')) return;
      const url = new URL(link.href, location.href);
      if (url.origin !== location.origin || (url.pathname === location.pathname && url.hash)) return;
      e.preventDefault();
      await minimizeComposer();
      location.href = url.href;
    });
    // Closing the tab, reloading, or a form-driven navigation: park it as a tab
    // (autosave keeps the content no more than ~1 s behind).
    window.addEventListener('pagehide', () => {
      if ($('composerOverlay').hidden || sending || !state.draftId || window.parent !== window) return;
      window.ecwDraftDock?.add({ id: state.draftId, mailbox_id: composerMailboxId, thread_id: composerThreadId, subject: $('fieldSubject').value, to: $('fieldTo').value });
      // Only changes not saved yet: a save as the page closes may be cut off on the way.
      if (draftUnsaved) { try { saveDraft(); } catch (_) {} }
    });
    $('discardBtn').addEventListener('click', async () => {
      if (sending || uploading) return;
      // No popup: the button is replaced by "Discard" / "Cancel" (confirm-button.js).
      if (window.ecwArm && !window.ecwArm($('discardBtn'), 'Click again to discard')) return;
      discardComposer();
    });
    $('sendBtn').addEventListener('click', () => send());
    window.ecwFloatingWindow($('composerOverlay'), document.querySelector('.composer-header'));
    // A new email written in the reading pane hides the conversation there until it is closed.
    new MutationObserver(() => { if ($('composerOverlay').hidden) document.body.classList.remove('inline-compose-new'); })
      .observe($('composerOverlay'), { attributes: true, attributeFilter: ['hidden'] });
    $('applySearchFilters').addEventListener('click', () => {
      const fields = [['from','filterFrom'],['to','filterTo'],['cc','filterCc'],['subject','filterSubject'],['filename','filterFilename'],['ext','filterExt'],['after','filterAfter'],['before','filterBefore'],['case','filterCase'],['larger','filterLarger']];
      const query = fields.filter(([,id]) => $(id).value.trim()).map(([key,id]) => key + ':"' + $(id).value.replaceAll('"','').trim() + '"');
      if ($('filterRead').value) query.push('is:' + $('filterRead').value);
      if ($('filterDirection').value) query.push('is:' + $('filterDirection').value);
      if ($('filterAttachment').checked) query.push('has:attachment');
      if ($('filterFlagged').checked) query.push('is:flagged');
      // What the panel sets replaces the same operators already typed in the box.
      const words = $('searchInput').value.replace(/(?:from|to|cc|subject|filename|attachment|ext|filetype|after|before|case|larger|smaller):(?:"[^"]*"|\S+)|has:attachment|is:(?:unread|read|sent|received|flagged)\b/gi, '').replace(/\s+/g, ' ').trim();
      $('searchInput').value = [words, ...query].filter(Boolean).join(' ');
      $('searchFilters').open = false;
      $('searchForm').requestSubmit();
    });
    $('fileInput').addEventListener('change', (event) => {
      attachFiles(Array.from(event.target.files || []));
      event.target.value = '';
    });

    // Drag files from the desktop or Explorer onto the composer to attach them.
    // Capture phase, so the editor never pastes a dropped file into the text.
    (function composerDrop() {
      const target = $('composerOverlay');
      const hint = document.createElement('div');
      hint.className = 'composer-drop';
      hint.hidden = true;
      hint.innerHTML = '<span><i class="fa fa-paperclip" aria-hidden="true"></i> Drop files to attach</span>';
      target.querySelector('.composer').appendChild(hint);
      const hasFiles = (e) => Array.from(e.dataTransfer?.types || []).includes('Files');
      let depth = 0;
      target.addEventListener('dragenter', (e) => { if (!hasFiles(e)) return; e.preventDefault(); depth++; hint.hidden = false; }, true);
      target.addEventListener('dragover', (e) => { if (!hasFiles(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; }, true);
      target.addEventListener('dragleave', (e) => { if (!hasFiles(e)) return; depth = Math.max(0, depth - 1); if (!depth) hint.hidden = true; }, true);
      target.addEventListener('drop', (e) => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        e.stopPropagation();
        depth = 0;
        hint.hidden = true;
        const files = Array.from(e.dataTransfer.files || []);
        if (files.length) attachFiles(files);
      }, true);
      // A file dropped just outside the composer must not navigate the tab away to it.
      window.addEventListener('dragover', (e) => { if (hasFiles(e) && !target.hidden) e.preventDefault(); });
      window.addEventListener('drop', (e) => { if (hasFiles(e) && !target.hidden) e.preventDefault(); });
    })();

    // Teams: chat with anyone on this email (in-app window, or the Teams app).
    $('teamsThreadBtn').addEventListener('click', (e) => {
      if (!state.thread || !window.ecwTeams) return;
      const own = new Set([...$('mailboxSelect').options].map((o) => o.textContent.trim().toLowerCase()));
      const people = [];
      state.thread.messages.forEach((m) => {
        [m.from, ...(m.to || []), ...(m.cc || [])].forEach((p) => {
          if (p && p.email && !own.has(p.email.toLowerCase())) people.push({ name: p.name || '', email: p.email });
        });
      });
      window.ecwTeams.menu(e.currentTarget, people, state.thread.subject);
    });

    $('addToCaseBtn').addEventListener('click', () => {
      $('caseOverlay').hidden = false;
      $('caseSearch').value = '';
      $('newCaseName').value = state.thread ? state.thread.subject : '';
      searchCases('');
      // The new case's group: the case groups, the last one chosen ready.
      const groupSelect = $('newCaseGroup');
      if (groupSelect && !groupSelect.dataset.loaded) {
        groupSelect.dataset.loaded = '1';
        api('case_groups', {}, { noMailbox: true }).then(({ groups }) => {
          (groups || []).forEach((g) => { const o = document.createElement('option'); o.value = g.id; o.textContent = g.name; o.dataset.color = g.color || ''; groupSelect.appendChild(o); });
          let last = ''; try { last = localStorage.getItem('ecw.newCaseGroup') || ''; } catch (_) {}
          if (last && groupSelect.querySelector('option[value="' + last + '"]')) groupSelect.value = last;
          buildGroupPick(groups || []);
        }).catch(() => { $('newCaseGroupPick').hidden = true; });
        groupSelect.addEventListener('change', () => { try { localStorage.setItem('ecw.newCaseGroup', groupSelect.value); } catch (_) {} });
      }
      // Case templates (Settings → Case templates): a new case can start from one.
      const tplSelect = $('newCaseTemplate');
      if (tplSelect && !tplSelect.dataset.loaded) {
        tplSelect.dataset.loaded = '1';
        api('case_templates', {}).then(({ templates }) => {
          (templates || []).forEach((t) => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.name + ' (' + t.steps + ' steps)'; tplSelect.appendChild(o); });
          tplSelect.hidden = !(templates || []).length;
        }).catch(() => {});
      }
    });
    $('mailListBack').addEventListener('click', () => {
      document.body.classList.remove('reading-open');
      document.querySelector('.thread-row.active')?.focus();
    });
    document.querySelectorAll('[data-case-status]').forEach(button => button.addEventListener('click', () => {
      casePickerStatus = button.dataset.caseStatus;
      document.querySelectorAll('[data-case-status]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      searchCases($('caseSearch').value);
    }));
    $('caseCancel').addEventListener('click', () => { $('caseOverlay').hidden = true; });
    $('caseSearch').addEventListener('input', (event) => searchCases(event.target.value));
    $('createCaseBtn').addEventListener('click', () => {
      const name = $('newCaseName').value.trim();
      if (!name) { toast('Name the case first', true); return; }
      linkCase(0, name);
    });
    $('removeFromCaseBtn').addEventListener('click', async () => {
      if (window.ecwArm && !window.ecwArm($('removeFromCaseBtn'), 'Click again to remove')) return;
      try {
        await api('case_unlink', {}, { method: 'POST', body: { thread_id: state.threadId } });
        toast('Removed from case');
        await threadCaseChanged(state.threadId);
      } catch (e) { toast(e.message, true); }
    });

    // The signature shows as a faded strip under the text; a click (or Enter) shows it all.
    const sigPreview = $('composeSignaturePreview');
    const toggleSig = () => sigPreview.setAttribute('aria-expanded', String(sigPreview.getAttribute('aria-expanded') !== 'true'));
    sigPreview.addEventListener('click', (e) => { if (!e.target.closest('a')) toggleSig(); });
    sigPreview.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleSig(); } });
    $('composeSignatureSelect').addEventListener('change', () => {
      composeSignature = signatureProfiles.find(p => p.id === $('composeSignatureSelect').value)?.html || '';
      $('composeSignaturePreview').innerHTML = composeSignature;
      scheduleDraftSave();
    });
    $('composeQuoteDetails').addEventListener('toggle', () => {
      if (!$('composeQuoteDetails').open || quoteQuill) return;
      // Preserve the sanitized original HTML. Quill's table conversion adds
      // borders and flattens signature/image layout when importing email.
      const root = document.createElement('div');
      root.className = 'quote-editable'; root.contentEditable = 'true';
      root.setAttribute('role','textbox'); root.setAttribute('aria-multiline','true');
      root.setAttribute('aria-label','Previous message and inline comments');
      root.innerHTML = composeQuote;
      const toolbar = document.createElement('div'); toolbar.className = 'quote-edit-toolbar';
      [['Bold','bold'],['Italic','italic'],['Underline','underline'],['Red text','foreColor','#c62828'],['Default text','foreColor','#222222']].forEach(([label,command,value]) => {
        const button = actionButton(label, () => { root.focus(); document.execCommand(command, false, value || null); quoteChanged = true; scheduleDraftSave(); });
        button.addEventListener('mousedown', event => event.preventDefault()); toolbar.appendChild(button);
      });
      root.addEventListener('input', () => { quoteChanged = true; scheduleDraftSave(); });
      root.addEventListener('paste', event => { event.preventDefault(); document.execCommand('insertText', false, event.clipboardData.getData('text/plain')); });
      root.addEventListener('drop', event => event.preventDefault());
      $('quoteEditor').replaceChildren(toolbar, root);
      quoteQuill = {root};
    });
    $('sigCancel').addEventListener('click', () => { $('signatureOverlay').hidden = true; });
    $('sigSave').addEventListener('click', async () => {
      try {
        await api('signature_save', {}, {
          method: 'POST',
          body: {
            display_name: $('sigName').value,
            title: $('sigTitle').value,
            company: $('sigCompany').value,
            phone: $('sigPhone').value,
            signature_html: sigQuill ? sigQuill.root.innerHTML : '',
          },
        });
        $('signatureOverlay').hidden = true;
        toast('Signature saved');
      } catch (e) { toast(e.message, true); }
    });

    // Infinite scroll: observe the footer instead of listening to scroll, so
    // nothing fires on every pixel of movement.
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) loadMore();
    }, { root: $('listPane'), rootMargin: '200px' });
    observer.observe($('listFooter'));
    $('listPane').addEventListener('scroll', saveListPos, { passive: true });
    // Pre-load the conversation under the mouse (a short pause, so passing over the list fetches nothing).
    let hoverTimer = null;
    $('threadList').addEventListener('pointerover', (e) => {
      const row = e.target.closest('.thread-row');
      if (!row || row.dataset.threadId === String(state.threadId)) return;
      clearTimeout(hoverTimer);
      hoverTimer = setTimeout(() => prefetchThread(row.dataset.threadId), 120);
    });
    $('threadList').addEventListener('pointerout', () => clearTimeout(hoverTimer));

    // A composer-only frame (a reply from a case) has no list to keep up to date.
    if (!new URLSearchParams(window.location.search).has('composer_only')) startLiveUpdates();

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      ['caseOverlay', 'signatureOverlay'].forEach((id) => { $(id).hidden = true; });

      closeNavDrawer();
    });
  }

  window.addEventListener('message', event => {
    if (event.origin !== window.location.origin) return;
    if (event.source === window.parent && event.data?.type === 'ecw-request-close') closeComposer();
    // From a composer popped out into its own window (popOutComposer).
    if (event.source !== window.parent && event.source?.opener === window) {
      if (event.data?.type === 'ecw-composer-sent') {
        sendToast(Number(event.data.draft_id) || 0, { undo_seconds: event.data.undo_seconds, send_at: event.data.send_at, held_in_outlook: !!event.data.held_in_outlook });
        if (state.draftsView) selectDrafts();
        if (state.threadId && event.data.undo_seconds) setTimeout(() => openThread(state.threadId, { preserveScroll: true }), (event.data.undo_seconds || 0) * 1000 + 4000);
      }
      if (event.data?.type === 'ecw-popout-closed' && state.draftsView) setTimeout(selectDrafts, 800);
    }
  });
  if (window.parent && window.parent !== window) window.parent.postMessage({type:'ecw-composer-ready'}, window.location.origin);
  if (window.ecwComposerDivider) window.ecwComposerDivider($('composerDivider'), $('composerHistory'));
  // A narrow handle for mail-extras.js (snooze, reminders, presence, templates,
  // case suggestions) so those features live in their own file.
  window.ecwMail = {
    state, api, toast, openThread, resetList, refreshList: () => smoothRefreshList(), linkCase: (id, name) => linkCase(id, name),
    threadCaseChanged: (id) => threadCaseChanged(id),
    openDraft: (id) => openDraft(id), minimizeComposer: () => minimizeComposer(),
    editor: () => { ensureEditor(); return quill; },
    setThreadRead: (id, isRead) => setThreadRead(id, isRead),
    openComposer: (mode, messageId) => openComposer(mode, messageId),
    latestMessageId: (messages) => latestMessageId(messages),
    attachFiles: (files) => attachFiles(files),
    reloadFolders: () => loadFolders(),
    reloadDrafts: () => { if (state.draftsView) selectDrafts(); },
    /** The open conversation was moved away: back to "Select a conversation". */
    showEmpty: () => {
      state.threadId = null; state.thread = null;
      $('threadView').hidden = true; $('readingEmpty').hidden = false;
      document.body.classList.remove('reading-open', 'details-open');
      if (window.ecwDetailsPaneResizer) window.ecwDetailsPaneResizer.setVisible(false);
      $('detailsPane').hidden = true;
    },
  };

  wire();
  boot();
})();
