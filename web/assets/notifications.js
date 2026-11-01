/**
 * The notification bell in the top navigation: mentions, reminders, reopened
 * cases, assignments. Polls the unread count every 30 seconds (and when the tab
 * becomes visible). Everything is written with textContent.
 *
 * This workspace's notifications (lib/notifications.php); the badge is red when something
 * important is unread, grey otherwise. "Important" shows only those. Several unread ones of
 * the same kind about the same case fold into one line ("and 2 more"). Unread ones in the
 * person's other workspaces are counted at the bottom. In quiet hours there are no desktop
 * pop-ups (window.ecwQuiet, nav-badges.js).
 */
(function () {
  'use strict';
  const bell = document.getElementById('notifBell');
  if (!bell || window.self !== window.top) return;   // embedded (pop-up) pages have no visible bell
  const base = window.ecwWebBase || '/';
  const badge = document.getElementById('notifCount');
  const panel = document.getElementById('notifPanel');
  const list = document.getElementById('notifList');
  let lastUnread = -1;
  let filter = 'all';
  let lastItems = [];
  let elsewhere = 0;

  // "All | Important" in the panel's header, before "Mark all read".
  const tabs = document.createElement('div');
  tabs.className = 'notif-tabs';
  tabs.setAttribute('role', 'tablist');
  [['all', 'All'], ['important', 'Important']].forEach(([key, label]) => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'notif-tab'; b.dataset.filter = key; b.textContent = label;
    b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', String(key === filter));
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      filter = key;
      tabs.querySelectorAll('.notif-tab').forEach((t) => t.setAttribute('aria-selected', String(t.dataset.filter === filter)));
      render(lastItems);
    });
    tabs.appendChild(b);
  });
  panel.querySelector('header strong')?.after(tabs);
  const away = document.createElement('p');
  away.className = 'notif-elsewhere muted';
  away.hidden = true;
  list.after(away);

  async function api(action, body) {
    const url = base + 'api/index.php?action=' + action;
    const init = body === undefined ? {} : {
      method: 'POST', headers: { 'X-ECW-CSRF': window.ecwCsrf || '', 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    };
    const r = await fetch(url, init);
    if (!r.ok) throw new Error('notifications unavailable');
    return r.json();
  }

  function setCount(n, important) {
    badge.hidden = n <= 0;
    if (important !== undefined) badge.classList.toggle('is-quiet', !important);   // grey: nothing important waits
    badge.textContent = n > 99 ? '99+' : String(n);
    bell.setAttribute('aria-label', n > 0 ? n + ' unread notifications' : 'Notifications');
    if (lastUnread >= 0 && n > lastUnread) bell.classList.add('ring'), setTimeout(() => bell.classList.remove('ring'), 1200);
    lastUnread = n;
  }

  const icons = { mention: 'fa-at', note_reply: 'fa-comment-dots', rule: 'fa-wand-magic-sparkles', reminder: 'fa-bell', reopened: 'fa-rotate-left', assigned: 'fa-user-check', sla: 'fa-stopwatch', digest: 'fa-sun', system: 'fa-heart-pulse',
    due: 'fa-calendar-day', send_failed: 'fa-triangle-exclamation', chat: 'fa-comments', review: 'fa-list-check', similar_case: 'fa-clone' };
  function ago(iso) {
    const s = Math.max(0, (Date.now() - new Date(iso.replace(' ', 'T') + 'Z').getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    return Math.floor(s / 86400) + ' d ago';
  }

  /** Unread ones of the same kind about the same case, together: the newest leads. */
  function grouped(items) {
    const out = []; const byKey = new Map();
    items.forEach((n) => {
      const key = !n.read && n.case_id ? n.type + ':' + n.case_id : null;
      if (key && byKey.has(key)) { byKey.get(key).more.push(n); return; }
      const g = { lead: n, more: [] };
      if (key) byKey.set(key, g);
      out.push(g);
    });
    return out;
  }

  function render(items) {
    lastItems = items;
    list.textContent = '';
    away.hidden = elsewhere <= 0;
    away.textContent = elsewhere > 0 ? elsewhere + ' unread in your other workspace' + (elsewhere === 1 ? '' : 's') + ': switch account at the top to see ' + (elsewhere === 1 ? 'it' : 'them') + '.' : '';
    const shown = filter === 'important' ? items.filter((n) => n.important) : items;
    if (!shown.length) {
      const li = document.createElement('li');
      li.className = 'muted notif-empty';
      li.textContent = filter === 'important' ? 'Nothing important. Mentions, assignments, reminders and due dates appear here.' : 'Nothing new. Mentions, reminders and assignments appear here.';
      list.appendChild(li);
      return;
    }
    grouped(shown).forEach(({ lead: n, more }) => {
      const li = document.createElement('li');
      const a = document.createElement(n.link ? 'a' : 'div');
      a.className = 'notif-item' + (n.read ? '' : ' unread') + (n.important ? ' important' : '');
      if (n.link) a.href = base + n.link;
      const icon = document.createElement('span');
      icon.className = 'notif-icon';
      if (n.actor && window.ecwAvatar) icon.appendChild(window.ecwAvatar(n.actor, 28));
      else { const i = document.createElement('i'); i.className = 'fa ' + (icons[n.type] || 'fa-circle-info'); i.setAttribute('aria-hidden', 'true'); icon.appendChild(i); }
      const text = document.createElement('span');
      text.className = 'notif-text';
      const msg = document.createElement('span');
      msg.textContent = n.text;
      const when = document.createElement('span');
      when.className = 'muted';
      when.textContent = ago(n.created_at);
      text.append(msg, when);
      if (more.length) {
        const extra = document.createElement('span');
        extra.className = 'notif-more';
        extra.textContent = 'and ' + more.length + ' more like this';
        text.appendChild(extra);
      }
      a.append(icon, text);
      // Opening the lead reads the ones folded under it too.
      a.addEventListener('click', () => { [n, ...more].forEach((x) => { if (!x.read) api('notifications_read', { id: x.id }).catch(() => {}); }); });
      li.appendChild(a);
      list.appendChild(li);
    });
  }

  function take(d) {
    elsewhere = Number(d.elsewhere || 0);
    window.ecwQuiet = !!d.quiet;
    setCount(d.unread, d.important);
  }

  async function refreshCount() {
    try {
      const d = await api('notifications'); take(d); if (!panel.hidden) render(d.items);
      document.dispatchEvent(new CustomEvent('ecw:notif-items', { detail: d.items }));   // nav-badges.js: desktop alerts
    } catch (_) {}
  }
  const desktopAlerts = () => { try { return localStorage.getItem('ecw.desktopAlerts') === '1'; } catch (_) { return false; } };

  bell.addEventListener('click', async (e) => {
    e.stopPropagation();
    panel.hidden = !panel.hidden;
    bell.setAttribute('aria-expanded', String(!panel.hidden));
    if (!panel.hidden) {
      try { const d = await api('notifications'); take(d); render(d.items); } catch (err) { list.textContent = err.message; }
    }
  });
  document.getElementById('notifReadAll').addEventListener('click', async () => {
    const d = await api('notifications_read', {});
    setCount(d.unread, d.important);
    list.querySelectorAll('.unread').forEach((el) => el.classList.remove('unread'));
  });
  document.addEventListener('click', (e) => { if (!panel.hidden && !panel.contains(e.target)) { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); } });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; bell.focus(); } });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshCount(); });
  let ticks = 0;
  setInterval(() => { ticks++; if (!document.hidden || (desktopAlerts() && ticks % 2 === 0)) refreshCount(); }, 30000);
  refreshCount();
})();
