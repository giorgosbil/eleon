/**
 * "Teams meeting" in the composer: a small form (title, day, time, length),
 * then a Teams meeting is made in the Outlook calendar of the account chosen
 * at the top, and its "Join the meeting" link goes into the email where the
 * cursor is. With "Invite the recipients" ticked, Outlook also sends To and Cc
 * the calendar invitation. api teams_meeting_create (lib/calendar.php).
 * Text is written with textContent; the inserted link is escaped.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  const $ = (id) => document.getElementById(id);
  const btn = $('teamsMeetingBtn');
  if (!M || !btn) return;
  if (window.ecwFeatures && window.ecwFeatures.calendar === false) { btn.hidden = true; return; }
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad = (n) => String(n).padStart(2, '0');
  // crypto.randomUUID needs https; the office network may use plain http.
  const uuid = () => ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, (c) => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));

  function recipients() {
    return [$('fieldTo')?.value, $('fieldCc')?.value].join(',').split(/[,;]/).map((s) => s.trim()).filter(Boolean).map((s) => {
      const m = s.match(/<([^>]+)>/);
      return m ? { email: m[1].trim(), name: s.replace(/<[^>]*>/, '').replace(/"/g, '').trim() } : { email: s, name: '' };
    }).filter((r) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(r.email));
  }

  /** The next half hour; after 18:00, tomorrow at 10:00. */
  function defaultStart() {
    const d = new Date();
    d.setSeconds(0, 0);
    d.setMinutes(d.getMinutes() < 30 ? 30 : 60);
    if (d.getHours() >= 18 || d.getHours() < 8) { if (d.getHours() >= 18) d.setDate(d.getDate() + 1); d.setHours(10, 0); }
    return d;
  }

  function close() { document.querySelector('.meeting-menu')?.remove(); document.removeEventListener('pointerdown', outside, true); }
  function outside(e) { const m = document.querySelector('.meeting-menu'); if (m && !m.contains(e.target) && e.target !== btn && !btn.contains(e.target)) close(); }

  function open() {
    if (document.querySelector('.meeting-menu')) { close(); return; }
    const menu = el('form', 'meeting-menu');
    menu.setAttribute('aria-label', 'Teams meeting');
    menu.noValidate = true;
    const start = defaultStart();
    const title = el('input'); title.type = 'text'; title.maxLength = 255;
    title.value = ($('fieldSubject')?.value || '').replace(/^\s*((re|fw|fwd|απ|σχετ)\s*:\s*)+/i, '').trim() || 'Teams meeting';
    const day = el('input'); day.type = 'date'; day.required = true;
    day.value = start.getFullYear() + '-' + pad(start.getMonth() + 1) + '-' + pad(start.getDate());
    const time = el('input'); time.type = 'time'; time.step = 300; time.required = true;
    time.value = pad(start.getHours()) + ':' + pad(start.getMinutes());
    const length = el('select');
    [[15, '15 min'], [30, '30 min'], [45, '45 min'], [60, '1 hour'], [90, '1½ hours'], [120, '2 hours']].forEach(([v, t]) => {
      const o = el('option', '', t); o.value = String(v); if (v === 30) o.selected = true; length.appendChild(o);
    });
    const people = recipients();
    const invite = el('input'); invite.type = 'checkbox'; invite.checked = people.length > 0; invite.disabled = !people.length;
    const inviteLabel = el('label', 'mm-check');
    inviteLabel.append(invite, document.createTextNode(people.length
      ? ' Invite the ' + (people.length === 1 ? 'recipient' : people.length + ' recipients') + ' (Outlook sends the invitation)'
      : ' No recipients yet: only the link goes into the email'));
    const status = el('div', 'mm-status muted small'); status.setAttribute('role', 'status');
    const create = el('button', 'primary', 'Create and add the link'); create.type = 'submit';
    const field = (label, input) => { const l = el('label', 'mm-field'); l.append(el('span', '', label), input); return l; };
    const when = el('div', 'mm-row'); when.append(field('Day', day), field('Time', time), field('Length', length));
    menu.append(el('strong', 'mm-head', 'Teams meeting'), field('Title', title), when, inviteLabel, create, status);

    menu.addEventListener('submit', async (e) => {
      e.preventDefault();
      const from = new Date(day.value + 'T' + time.value);
      if (!day.value || !time.value || isNaN(from)) { status.textContent = 'Choose a day and a time.'; return; }
      const to = new Date(from.getTime() + Number(length.value) * 60000);
      create.disabled = true; status.textContent = 'Creating the meeting in Outlook…';
      try {
        const r = await M.api('teams_meeting_create', {}, { method: 'POST', body: {
          title: title.value.trim(), start: from.toISOString().replace(/\.\d+Z$/, 'Z'), end: to.toISOString().replace(/\.\d+Z$/, 'Z'),
          attendees: invite.checked ? recipients() : [], transactionId: uuid(),
        } });
        insert(r.meeting, from, to);
        close();
        M.toast(r.meeting.invited ? 'Teams meeting created; Outlook sent the invitation to ' + r.meeting.invited + (r.meeting.invited === 1 ? ' person' : ' people') : 'Teams meeting created and its link added');
      } catch (err) {
        create.disabled = false;
        status.textContent = err.message;
        if (err.data?.needs_consent) {
          const allow = el('a', '', ' Allow calendar access');
          allow.href = (window.ecwWebBase || '') + 'oauth/start.php?calendar=1'; allow.target = '_blank'; allow.rel = 'noopener';
          status.appendChild(allow);
        }
      }
    });
    menu.addEventListener('keydown', (e) => { if (e.key === 'Escape') { close(); btn.focus(); } });

    document.body.appendChild(menu);
    const r = btn.getBoundingClientRect();
    menu.style.position = 'fixed';   // the composer is a fixed window
    menu.style.left = Math.max(8, Math.min(window.innerWidth - 350, r.left)) + 'px';
    menu.style.top = Math.max(8, Math.min(window.innerHeight - menu.offsetHeight - 8, r.bottom + 4)) + 'px';
    setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
    title.focus(); title.select();
  }

  function insert(m, from, to) {
    const quill = M.editor();
    const range = quill.getSelection(true) || { index: quill.getLength() };
    const dayText = from.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const t = (d) => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const html = '<p><strong>Microsoft Teams meeting: ' + esc(m.title) + '</strong><br>'
      + esc(dayText + ', ' + t(from) + ' – ' + t(to) + (zone ? ' (' + zone + ')' : '')) + '<br>'
      + '<a href="' + esc(m.join_url) + '">Join the meeting</a></p>';
    quill.clipboard.dangerouslyPasteHTML(range.index, html, 'user');
  }

  btn.addEventListener('click', open);
})();
