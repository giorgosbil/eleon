/**
 * Out of office from the top bar: "Automatic replies" in the account menu (or
 * the profile menu with one account), a small panel to turn them on, off or on
 * between two times with a message, and "Away" on the account button while
 * they are on. Saved in Outlook / Gmail (api auto_reply_get / auto_reply_save,
 * the same as Settings → Mailbox), for the account chosen at the top.
 * Text is written with textContent.
 */
(function () {
  'use strict';
  const base = window.ecwWebBase || '/';
  const mailbox = Number(window.ecwAccountId || 0);
  const item = document.getElementById('oooItem');
  if (!item || !mailbox || window.ecwFeatures?.auto_reply === false) { if (item) item.hidden = true; return; }
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  async function api(action, body) {
    const r = await fetch(base + 'api/index.php?action=' + action + '&mailbox_id=' + mailbox, body === undefined ? { credentials: 'same-origin' }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) { const e = new Error(d.error || 'Request failed'); e.data = d; throw e; }
    return d;
  }
  const local = (utc) => { if (!utc) return ''; const d = new Date(String(utc).replace(' ', 'T') + 'Z'); if (isNaN(d)) return ''; const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); };
  const text = (html) => { const d = document.createElement('div'); d.innerHTML = String(html || '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n'); return (d.textContent || '').trim(); };
  const html = (t) => String(t || '').split(/\n/).map((l) => '<p>' + l.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>').join('');
  const day = (utc) => new Date(String(utc).replace(' ', 'T') + 'Z').toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

  /** "Away" on the account button and the state in the menu item. */
  function paint(s) {
    const now = Date.now();
    const active = s && (s.status === 'on' || (s.status === 'scheduled' && s.start && s.end && new Date(s.start.replace(' ', 'T') + 'Z') <= now && new Date(s.end.replace(' ', 'T') + 'Z') > now));
    const state = item.querySelector('.ooo-state');
    if (state) state.textContent = !s || !s.status ? '' : s.status === 'off' ? 'Off' : active ? (s.status === 'scheduled' ? 'On until ' + day(s.end) : 'On') : (s.start ? 'From ' + day(s.start) : '');
    item.classList.toggle('is-on', !!active);
    document.querySelectorAll('.ooo-badge').forEach((b) => { b.hidden = !active; b.title = active ? 'Automatic replies are on' + (s.status === 'scheduled' ? ' until ' + day(s.end) : '') : ''; });
  }
  try { paint(JSON.parse(item.dataset.state || 'null')); } catch (_) {}

  let panel = null;
  function close() { panel?.remove(); panel = null; }
  async function open() {
    close();
    panel = el('div', 'ooo-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Automatic replies');
    const head = el('div', 'ooo-head');
    head.append(el('strong', '', 'Automatic replies'), el('small', '', item.dataset.address || ''));
    const x = el('button', 'ooo-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.addEventListener('click', close);
    head.appendChild(x);
    const body = el('div', 'ooo-body', 'Loading…');
    panel.append(head, body);
    document.body.appendChild(panel);
    let s;
    try { s = (await api('auto_reply_get')).auto_reply; }
    catch (e) {
      body.textContent = e.message;
      if (e.data?.needs_consent && e.data.connect_url) { const a = el('a', 'button primary', 'Allow automatic replies'); a.href = e.data.connect_url; body.append(el('br'), a); }
      return;
    }
    paint(s);
    const form = el('form', 'ooo-form');
    const modes = el('div', 'ooo-modes');
    [['off', 'Off'], ['on', 'On'], ['scheduled', 'Between these times']].forEach(([v, label]) => {
      const l = el('label', 'ooo-mode'); const r = el('input'); r.type = 'radio'; r.name = 'ooo-status'; r.value = v; r.checked = s.status === v;
      l.append(r, el('span', '', label)); modes.appendChild(l);
    });
    const times = el('div', 'ooo-times');
    const start = el('input'); start.type = 'datetime-local'; start.value = local(s.start);
    const end = el('input'); end.type = 'datetime-local'; end.value = local(s.end);
    const ls = el('label', '', 'From'); ls.appendChild(start);
    const le = el('label', '', 'Until'); le.appendChild(end);
    times.append(ls, le);
    const msg = el('textarea'); msg.rows = 5; msg.value = text(s.internal);
    msg.placeholder = 'Thank you for your email. I am out of the office until … and will reply when I am back.';
    const lm = el('label', '', 'Message'); lm.appendChild(msg);
    const same = el('label', 'ooo-same'); const sameBox = el('input'); sameBox.type = 'checkbox';
    sameBox.checked = !s.external || text(s.external) === text(s.internal);
    same.append(sameBox, document.createTextNode(' The same message for people outside the company'));
    const ext = el('textarea'); ext.rows = 4; ext.value = text(s.external);
    const lx = el('label', '', 'Message for people outside the company'); lx.appendChild(ext);
    const save = el('button', 'primary', 'Save'); save.type = 'submit';
    const status = el('span', 'muted small'); status.setAttribute('role', 'status');
    const row = el('div', 'ooo-row'); row.append(save, status);
    const more = el('a', 'ooo-more', 'More options'); more.href = base + 'settings/mailbox.php#auto-reply';
    form.append(modes, times, lm, same, lx, row, more);
    const sync = () => {
      const mode = form.querySelector('input[name=ooo-status]:checked')?.value || 'off';
      times.hidden = mode !== 'scheduled';
      lm.hidden = same.hidden = mode === 'off';
      lx.hidden = mode === 'off' || sameBox.checked;
    };
    form.addEventListener('change', sync);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const mode = form.querySelector('input[name=ooo-status]:checked')?.value || 'off';
      save.disabled = true; status.textContent = 'Saving…';
      try {
        const r = await api('auto_reply_save', {
          status: mode, start: start.value ? new Date(start.value).toISOString() : '', end: end.value ? new Date(end.value).toISOString() : '',
          internal: html(msg.value), external: html(sameBox.checked ? msg.value : ext.value), audience: s.audience || 'all', subject: s.subject || 'Out of office',
        });
        paint(r.auto_reply);
        status.textContent = mode === 'off' ? 'Automatic replies are off.' : 'Saved. People who write get your reply.';
        (window.ecwPageToast || (() => {}))(mode === 'off' ? 'Automatic replies off' : 'Automatic replies on');
        setTimeout(close, 900);
      } catch (err) { status.textContent = err.message; }
      finally { save.disabled = false; }
    });
    body.replaceChildren(form);
    sync();
    msg.focus();
  }
  item.addEventListener('click', (e) => { e.preventDefault(); document.getElementById('acctMenu')?.setAttribute('hidden', ''); document.getElementById('appnavMeMenu')?.setAttribute('hidden', ''); open(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && panel) close(); });
  document.addEventListener('mousedown', (e) => { if (panel && !panel.contains(e.target) && !item.contains(e.target)) close(); });
  // What Outlook says now, now and then (it can be changed there too): at most every 30 minutes.
  const at = Number(item.dataset.checked || 0);
  if (Date.now() / 1000 - at > 1800) setTimeout(() => api('auto_reply_get').then((r) => paint(r.auto_reply)).catch(() => {}), 2500);
})();
