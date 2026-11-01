/**
 * Case page side panel: internal notes with @mentions, follow-up reminders,
 * and the AI case summary. (Chats linked to the case are columns of the
 * timeline: modules/chatlink.)
 *
 * XSS: note HTML comes from lib/notes.php (escaped, then mention/link markup
 * added), written with innerHTML and marked. Everything else is textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const panel = $('casePanel');
  if (!panel) return;
  const caseId = Number(document.body.dataset.case);
  const me = Number(document.body.dataset.user);
  const csrf = document.body.dataset.csrf;
  let users = [];
  let summary = null;
  let activeTab = 'notes';

  async function api(action, params, body) {
    const url = new URL('api/index.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const init = body === undefined ? {} : {
      method: 'POST', headers: { 'X-ECW-CSRF': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    };
    const r = await fetch(url.toString(), init);
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || 'Request failed (' + r.status + ')');
    return data;
  }
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const local = (utc) => { const d = new Date(String(utc).replace(' ', 'T') + 'Z'); return Number.isNaN(d.getTime()) ? '' : d.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };

  // --------------------------------------------------------- open / tabs

  // On a phone the panel is a sheet over the page (collab.css), closed by default.
  const sheet = () => window.matchMedia('(max-width: 860px)').matches;
  const backdrop = el('div', 'case-panel-backdrop');
  backdrop.addEventListener('click', () => setOpen(false));
  document.body.appendChild(backdrop);
  function setOpen(open) {
    panel.hidden = !open;
    document.body.classList.toggle('case-panel-open', open);
    if (!sheet()) { try { localStorage.setItem('ecw.casePanel.open', open ? '1' : '0'); } catch (_) {} }
  }
  function showTab(tab) {
    activeTab = tab;
    panel.querySelectorAll('[role="tab"]').forEach((b) => { const on = b.dataset.tab === tab; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); });
    panel.querySelectorAll('[data-panel]').forEach((s) => { s.hidden = s.dataset.panel !== tab; });
    if (tab === 'followups') loadFollowups();
    // Tabs of modules (lib/modules.php case_tabs) fill themselves.
    document.dispatchEvent(new CustomEvent('ecw:case-tab', { detail: { tab, caseId } }));
  }
  panel.querySelectorAll('[role="tab"]').forEach((b) => b.addEventListener('click', () => showTab(b.dataset.tab)));
  $('casePanelClose').addEventListener('click', () => setOpen(false));
  $('casePanelToggle')?.addEventListener('click', () => setOpen(panel.hidden));

  // -------------------------------------------------------------- notes
  // Like comments in Word (notes-ui.js): conversations on each email of the
  // case (the 💬 on every bubble opens them here) and general ones about the
  // whole case.

  let noteGroups = [];
  const notesUI = window.ecwNotesUI && $('caseNotesUI') ? window.ecwNotesUI($('caseNotesUI'), {
    api: {
      add: (body, o) => api('case_note_add', { case_id: caseId }, Object.assign({ body }, o)),
      remove: (id) => api('case_note_delete', { case_id: caseId }, { note_id: id }),
      resolve: (id, on) => api('case_note_resolve', { case_id: caseId }, { note_id: id, resolved: on }),
      seen: (id) => api('case_note_seen', { case_id: caseId }, { note_id: id }),
    },
    users: () => users, me,
    generalLabel: 'the whole case',
    jumpTo: (id) => document.dispatchEvent(new CustomEvent('ecw:jump-to-message', { detail: { message_id: id } })),
    onGroups: (g) => {
      noteGroups = g;
      const open = g.filter((x) => !x.resolved).length;
      $('notesCount').textContent = open ? String(open) : '';
      document.dispatchEvent(new CustomEvent('ecw:note-groups', { detail: g }));
    },
  }) : null;
  window.ecwCaseNoteGroups = () => noteGroups;
  async function loadNotes() {
    if (!notesUI) return;
    try { notesUI.setGroups((await api('case_notes', { case_id: caseId })).groups); } catch (_) {}
  }
  document.addEventListener('ecw:notes-open', (e) => {
    if (!notesUI) return;
    setOpen(true);
    showTab('notes');
    notesUI.showMessage(e.detail.message_id, { from: e.detail.from, at: e.detail.at });
  });
  // --------------------------------------------------------- follow-ups

  function toLocalInput(d) {
    const pad = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  document.querySelectorAll('.quick-times [data-in]').forEach((b) => b.addEventListener('click', () => {
    const d = new Date();
    if (b.dataset.in === 'tomorrow') { d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); }
    if (b.dataset.in === '3d') { d.setDate(d.getDate() + 3); d.setHours(9, 0, 0, 0); }
    if (b.dataset.in === 'week') { d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7)); d.setHours(9, 0, 0, 0); }
    $('followupDue').value = toLocalInput(d);
  }));
  function renderFollowups(items) {
    const list = $('followupList');
    list.textContent = '';
    $('followupsCount').textContent = items.length ? String(items.length) : '';
    if (!items.length) list.appendChild(el('li', 'muted panel-empty', 'No reminders on this case.'));
    items.forEach((f) => {
      const li = el('li', 'followup');
      const i = el('i', 'fa fa-clock'); i.setAttribute('aria-hidden', 'true');
      const text = el('span', 'followup-text');
      text.append(el('strong', '', local(f.due_at)), el('span', '', (f.note ? f.note + ' · ' : '') + (f.user.id === me ? 'you' : (f.user.name || f.user.email)) + (f.if_no_reply ? ' · if no reply' : '')));
      li.append(i, text);
      if (f.user.id === me) {
        const x = el('button', 'link-btn', 'Cancel');
        x.type = 'button';
        x.addEventListener('click', async () => { if (window.ecwArm && !window.ecwArm(x, 'Click again to cancel')) return; try { await api('followup_cancel', {}, { id: f.id }); loadFollowups(); } catch (e) { $('followupStatus').textContent = e.message; } });
        li.appendChild(x);
      }
      list.appendChild(li);
    });
  }
  async function loadFollowups() {
    try { renderFollowups((await api('case_followups', { case_id: caseId })).followups); } catch (e) { $('followupStatus').textContent = e.message; }
  }
  $('followupForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const due = new Date($('followupDue').value);
    if (Number.isNaN(due.getTime())) { $('followupStatus').textContent = 'Choose when.'; return; }
    try {
      const d = await api('case_followup_add', { case_id: caseId }, { due: due.toISOString(), note: $('followupNote').value, if_no_reply: $('followupNoReply').checked });
      renderFollowups(d.followups);
      $('followupNote').value = ''; $('followupDue').value = '';
      $('followupStatus').textContent = 'Reminder set. You will get a notification.';
    } catch (err) { $('followupStatus').textContent = err.message; }
  });

  // -------------------------------------------------------------- summary

  $('summaryBtn').addEventListener('click', async () => {
    const out = $('summaryOutput');
    const btn = $('summaryBtn');
    btn.disabled = true;
    out.textContent = 'Reading the case…';
    try {
      const d = await api('case_ai_summary', { case_id: caseId }, {});
      out.textContent = d.text;   // plain text, never HTML
      if (d.truncated) out.appendChild(el('p', 'muted small', 'The summary was cut short.'));
    } catch (e) { out.textContent = e.message; }
    finally { btn.disabled = false; }
  });

  // ------------------------------------------------------------- startup

  document.addEventListener('ecw:org-users', (e) => { users = e.detail || []; });
  // Don't depend on catching case.js's event (it can fire before this deferred
  // script listens): load the colleagues for @mentions directly.
  api('org_users').then((d) => { if (!users.length) users = d.users || []; }).catch(() => {});
  document.addEventListener('ecw:case-summary', (e) => { summary = e.detail; });
  let open = window.innerWidth > 1100;
  if (sheet()) open = false;
  else { try { const s = localStorage.getItem('ecw.casePanel.open'); if (s !== null) open = s === '1'; } catch (_) {} }
  if (location.hash === '#notes') open = true;
  setOpen(open);
  // A notification link (case.php?id=…&note=…) opens that conversation.
  const wantNote = Number(new URLSearchParams(location.search).get('note') || 0);
  loadNotes().then(() => { if (wantNote && notesUI) { setOpen(true); showTab('notes'); notesUI.showGroup(wantNote); } });
  api('case_followups', { case_id: caseId }).then((d) => renderFollowups(d.followups)).catch(() => {});
  setInterval(() => { if (!document.hidden && activeTab === 'notes' && !panel.hidden) loadNotes(); }, 30000);
})();
