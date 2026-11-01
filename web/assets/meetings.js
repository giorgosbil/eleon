/**
 * Meeting invitations in the reading pane: a card on the invite email with the
 * meeting's time, place and organiser, and Accept / Tentative / Decline (with an
 * optional note to the organiser). A cancelled meeting offers "Remove from
 * calendar". Outlook mailboxes only — the provider does the work (api
 * meeting_info / meeting_respond). Text is written with textContent.
 */
(function () {
  'use strict';
  const M = window.ecwMail;
  if (!M) return;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const when = (s) => new Date(String(s).replace(' ', 'T') + 'Z');
  const RESPONSE = { accepted: 'You accepted', tentativelyAccepted: 'You replied tentative', declined: 'You declined', organizer: 'You organise this meeting', removed: 'Removed from your calendar' };

  function range(m) {
    if (!m.start) return '';
    const a = when(m.start), b = m.end ? when(m.end) : null;
    const day = a.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    if (m.all_day) return day + ' · all day';
    const t = (d) => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return day + ' · ' + t(a) + (b ? ' – ' + t(b) : '');
  }

  function card(messageId, article) {
    const box = el('div', 'meeting-card');
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', 'Meeting invitation');
    box.append(el('span', 'muted', 'Loading the meeting…'));
    article.querySelector('.message-head')?.after(box);
    load();

    async function load(after) {
      try {
        const r = after || cache.get(messageId) || await M.api('meeting_info', { message_id: messageId });
        cache.set(messageId, r);
        draw(r.meeting);
      } catch (e) {
        failed(e);
      }
    }

    // No calendar access yet (a mailbox connected before it was asked for): one click to allow it.
    function failed(e) {
      box.replaceChildren(el('i', 'fa fa-calendar-xmark'), el('span', 'muted', e.message));
      if (!e.data?.consent) return;
      const allow = el('a', 'meeting-btn accept', 'Allow calendar access');
      allow.href = (window.ecwWebBase || '') + 'oauth/start.php?calendar=1';
      allow.title = 'Sign in to Outlook once more and allow the calendar; then come back to this email.';
      box.append(allow);
    }

    function draw(m) {
      box.replaceChildren();
      box.classList.toggle('cancelled', !!m.cancelled);
      const icon = el('div', 'meeting-icon');
      const i = el('i', 'fa ' + (m.cancelled ? 'fa-calendar-xmark' : 'fa-calendar-check'));
      i.setAttribute('aria-hidden', 'true');
      icon.appendChild(i);
      const info = el('div', 'meeting-info');
      info.append(el('strong', '', (m.cancelled ? 'Cancelled: ' : '') + (m.subject || 'Meeting')));
      info.append(el('span', '', range(m)));
      if (m.location) info.append(el('span', 'muted', '📍 ' + m.location));
      if (m.organizer) info.append(el('span', 'muted', 'Organiser: ' + m.organizer));
      if (m.join_url && /^https:\/\//i.test(m.join_url) && !m.cancelled) {
        const a = el('a', 'meeting-join', 'Join online meeting');
        a.href = m.join_url; a.target = '_blank'; a.rel = 'noopener noreferrer';
        info.append(a);
      }
      const actions = el('div', 'meeting-actions');
      const status = el('span', 'meeting-status', RESPONSE[m.response] || '');
      if (m.cancelled) {
        if (m.response !== 'removed') actions.append(btn('fa-trash-can', 'Remove from calendar', 'remove'));
      } else if (m.response !== 'organizer') {
        const note = el('input', 'meeting-note');
        note.type = 'text'; note.placeholder = 'Add a note to the organiser (optional)'; note.maxLength = 500; note.hidden = true;
        const add = el('button', 'link-btn', 'Add a note'); add.type = 'button';
        add.addEventListener('click', () => { note.hidden = !note.hidden; if (!note.hidden) note.focus(); });
        actions.append(
          btn('fa-check', 'Accept', 'accept', m.response === 'accepted'),
          btn('fa-question', 'Tentative', 'tentative', m.response === 'tentativelyAccepted'),
          btn('fa-xmark', 'Decline', 'decline', m.response === 'declined'),
          add, note
        );
        box._note = note;
      }
      box.append(icon, info, actions, status);
    }

    function btn(icon, label, action, on) {
      const b = el('button', 'meeting-btn ' + action + (on ? ' on' : ''));
      b.type = 'button';
      const i = el('i', 'fa ' + icon); i.setAttribute('aria-hidden', 'true');
      b.append(i, document.createTextNode(' ' + label));
      b.setAttribute('aria-pressed', String(!!on));
      b.addEventListener('click', async () => {
        if (action === 'decline' || action === 'remove') { if (window.ecwArm && !window.ecwArm(b, 'Click again to ' + label.toLowerCase())) return; }
        box.querySelectorAll('button').forEach((x) => { x.disabled = true; });
        try {
          const r = await M.api('meeting_respond', {}, { method: 'POST', body: { message_id: messageId, response: action, comment: box._note?.value || '' } });
          load(r);
          M.toast(action === 'remove' ? 'Removed from your calendar' : 'Reply sent to the organiser');
        } catch (e) {
          if (e.data?.consent) { failed(e); return; }
          M.toast(e.message, true); box.querySelectorAll('button').forEach((x) => { x.disabled = false; });
        }
      });
      return b;
    }
  }

  const cache = new Map();          // message id -> meeting info (one call per invite)
  let invites = new Set();
  function attach() {
    invites.forEach((id) => {
      const article = document.querySelector('#messageList [data-message-id="' + id + '"]');
      if (article && !article.querySelector('.meeting-card')) card(id, article);
    });
  }
  document.addEventListener('ecw:thread-open', (e) => {
    invites = new Set((e.detail.messages || [])
      .filter((m) => m.direction === 'incoming' && /^eventMessage(Request)?$/.test(m.meeting_kind || ''))
      .map((m) => m.id));
    attach();
  });
  // A message body loading in replaces its element: put the card back.
  const list = document.getElementById('messageList');
  if (list) new MutationObserver(() => { if (invites.size) attach(); }).observe(list, { childList: true });
})();
