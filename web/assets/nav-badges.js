/**
 * Badges on the top navigation, plus desktop alerts.
 *
 *  - Mail: unread emails in the Inbox (also shown in the browser tab title).
 *  - Cases: open cases that have unread mail.
 *  - Teams: a dot when a chat is unread (count from teams-launcher.js).
 *
 * Polls api nav_badges every 30 s, when the tab becomes visible, and shortly
 * after anything on the page marks mail read or unread (event
 * ecw:unread-changed) or the mail page's live poll sees the count move.
 *
 * Desktop alerts (opt-in from the bell panel or Settings → Notifications) fire for a new Inbox email and
 * for new bell notifications. localStorage remembers what was already
 * announced, so several open tabs do not alert twice. Browsers only allow
 * them on https:// or localhost.
 */
(function () {
  'use strict';
  if (window.self !== window.top) return;   // pop-ups and embedded composers: the page around them polls
  const base = window.ecwWebBase || '/';
  const ALERT_KEY = 'ecw.desktopAlerts';
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (_) {} },
  };
  const baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');

  function badge(key, value, dotOnly) {
    const link = document.querySelector('.appnav a[data-nav="' + key + '"]');
    if (!link) return;
    let b = link.querySelector('.appnav-badge');
    if (!value) { b?.remove(); return; }
    if (!b) { b = document.createElement('span'); link.appendChild(b); }
    b.className = 'appnav-badge' + (dotOnly ? ' dot' : '');
    b.textContent = dotOnly ? '' : (value > 99 ? '99+' : String(value));
    const what = key === 'mail' ? ' unread emails' : key === 'cases' ? ' cases with unread mail' : ' unread chats';
    b.setAttribute('aria-label', value + what);
    b.title = value + what;
  }

  // Unread mail of the other accounts, on the account picker (partials/nav.php):
  // a dot on the button, the count next to each account in its menu.
  function accountBadges(counts, active) {
    const sw = document.getElementById('acctSwitch');
    if (!sw) return;
    let others = 0;
    sw.querySelectorAll('[data-account]').forEach((item) => {
      const id = item.dataset.account, n = Number(counts[id] || 0);
      let c = item.querySelector('.acct-count');
      if (String(active) !== id) others += n;
      if (!n) { c?.remove(); return; }
      if (!c) { c = document.createElement('span'); c.className = 'acct-count'; item.querySelector('.acct-text').after(c); }
      c.textContent = n > 99 ? '99+' : String(n);
      c.title = n + ' unread in the Inbox';
    });
    const btn = document.getElementById('acctBtn');
    let dot = btn.querySelector('.acct-other-dot');
    if (!others) { dot?.remove(); return; }
    if (!dot) { dot = document.createElement('span'); dot.className = 'acct-other-dot'; btn.appendChild(dot); }
    dot.title = others + ' unread in your other accounts';
  }

  // ------------------------------------------------------------ desktop alerts
  const supported = 'Notification' in window && window.isSecureContext;
  // With push on, the service worker shows alerts (even with the app closed): the page stays quiet.
  const pushOn = () => store.get('ecw.push') === '1';
  const alertsOn = () => supported && Notification.permission === 'granted' && store.get(ALERT_KEY) === '1' && !pushOn();
  const pushSupported = () => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window;
  const keyBytes = (b64) => { const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - b64.length % 4) % 4)); return Uint8Array.from(s, (c) => c.charCodeAt(0)); };
  async function post(action, body) {
    const r = await fetch(base + 'api/index.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify(body || {}) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  /** Subscribe this device to Web Push (lib/push.php). */
  async function pushSubscribe() {
    if (!pushSupported()) return false;
    const reg = await navigator.serviceWorker.ready;
    const { key } = await (await fetch(base + 'api/index.php?action=push_key')).json();
    let sub = await reg.pushManager.getSubscription();
    if (sub && sub.options && sub.options.applicationServerKey) {
      const current = new Uint8Array(sub.options.applicationServerKey), want = keyBytes(key);
      if (current.length !== want.length || current.some((v, i) => v !== want[i])) { await sub.unsubscribe(); sub = null; }
    }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
    await post('push_subscribe', { subscription: sub.toJSON() });
    store.set('ecw.push', '1');
    return true;
  }
  async function pushUnsubscribe() {
    store.set('ecw.push', '0');
    if (!pushSupported()) return;
    const sub = await (await navigator.serviceWorker.ready).pushManager.getSubscription();
    if (sub) { await post('push_unsubscribe', { endpoint: sub.endpoint }).catch(() => {}); await sub.unsubscribe().catch(() => {}); }
  }

  function alertOnce(key, id, title, body, link) {
    const seen = Number(store.get(key) || 0);
    if (!seen) { store.set(key, String(id)); return; }   // first run: remember, do not announce old mail
    if (id <= seen) return;
    store.set(key, String(id));
    if (!alertsOn() || window.ecwQuiet) return;   // quiet hours (Settings -> Notifications): seen, not shown
    try {
      const n = new Notification(title, { body, tag: key + id });
      n.onclick = () => { window.focus(); if (link) location.href = link; n.close(); };
    } catch (_) {}
  }

  // The bell panel and Settings → Notifications (#alertSettings) get a switch for desktop
  // alerts and a test. Both show the same: turning alerts on in one repaints the other.
  function alertControls(host) {
    if (!host || host.querySelector('.notif-desktop')) return;
    const row = document.createElement('div');
    row.className = 'notif-desktop';
    const panel = host;
    const text = document.createElement('span');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'link-btn';
    row.append(text, btn);
    panel.appendChild(row);
    const paint = () => {
      btn.hidden = false;
      if (!('Notification' in window)) { text.textContent = 'This browser has no desktop alerts.'; btn.hidden = true; return; }
      if (!window.isSecureContext) { text.textContent = 'Desktop alerts need https:// (they work on localhost and on the office server with HTTPS).'; btn.hidden = true; return; }
      if (Notification.permission === 'denied') { text.textContent = 'Desktop alerts are blocked in this browser’s site settings.'; btn.hidden = true; return; }
      const on = Notification.permission === 'granted' && store.get(ALERT_KEY) === '1';
      row.classList.toggle('on', on);
      text.textContent = on
        ? (pushOn() ? 'Alerts are on for this device — also when the app is closed.' : 'Desktop alerts are on for new email and mentions.')
        : 'Get an alert for new email and mentions on this device.';
      btn.textContent = on ? 'Turn off' : 'Turn on';
      test.hidden = !on;
    };
    const test = document.createElement('button');
    test.type = 'button'; test.className = 'link-btn'; test.textContent = 'Send a test'; test.hidden = true;
    // A test: one shown on this device at once (through the service worker, so also in the
    // installed app), and one sent by the server, the way new email arrives.
    test.addEventListener('click', async (e) => {
      e.stopPropagation();
      const help = ' Nothing in the bottom-right corner? In Windows: Settings → System → Notifications: notifications on, Google Chrome on, and Do not disturb off.';
      try {
        const reg = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration() : null;
        const opts = { body: 'Alerts work on this device.', icon: base + 'assets/icons/icon-192.png', tag: 'ecw-test' };
        if (reg) await reg.showNotification('Test notification', opts); else new Notification('Test notification', opts);
      } catch (err) { text.textContent = 'This device could not show it: ' + err.message; return; }
      let devices = 0;
      if (pushOn()) { try { devices = (await post('push_test')).devices || 0; } catch (_) { devices = 0; } }
      text.textContent = 'Test shown' + (pushOn() ? (devices ? ' and sent through the server to ' + devices + ' device(s).' : '; the server could not reach this device: turn alerts off and on again.') : '.') + help;
    });
    row.appendChild(test);
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (Notification.permission === 'granted' && store.get(ALERT_KEY) === '1') {
        store.set(ALERT_KEY, '0'); await pushUnsubscribe(); changed(); return;
      }
      const result = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
      if (result === 'granted') {
        store.set(ALERT_KEY, '1');
        let viaPush = false;
        try { viaPush = await pushSubscribe(); } catch (err) { viaPush = false; console.warn('push subscribe failed', err); }
        if (!viaPush) { try { new Notification('Desktop alerts are on', { body: 'You will be told about new email and mentions while the app is open.' }); } catch (_) {} }
        else { try { await post('push_test'); } catch (_) {} }
      }
      changed();
    });
    const changed = () => document.dispatchEvent(new Event('ecw:alerts-changed'));
    document.addEventListener('ecw:alerts-changed', paint);
    paint();
  }

  // ---------------------------------------------------------- taskbar badge
  /**
   * The installed app's icon on the taskbar (Windows) / dock (Mac) shows the unread Inbox emails,
   * the number of the Mail tab (Badging API; in the browser it does nothing). The service worker
   * sets it too when a new-email alert arrives with the app closed (sw.js).
   */
  // Settings → Notifications: the number, only a dot, or nothing.
  const badgeMode = (() => { try { return JSON.parse(document.getElementById('appearanceConfig')?.textContent || '{}').badge_mode || 'number'; } catch (_) { return 'number'; } })();
  let lastBadge = null;
  function appBadge(n) {
    n = badgeMode === 'off' ? 0 : Math.max(0, Number(n) || 0);
    if (n === lastBadge || !('setAppBadge' in navigator)) return;
    lastBadge = n;
    (n > 0 ? (badgeMode === 'dot' ? navigator.setAppBadge() : navigator.setAppBadge(n)) : navigator.clearAppBadge()).catch(() => {});
  }

  // --------------------------------------------------------------------- poll
  let busy = false, timer = null;
  async function refresh() {
    if (busy) return;
    busy = true;
    try {
      const r = await fetch(base + 'api/index.php?action=nav_badges', { cache: 'no-store' });
      if (!r.ok) return;
      const d = await r.json();
      badge('mail', d.mail);
      badge('cases', d.cases);
      accountBadges(d.accounts || {}, d.account_id);
      document.title = (d.mail > 0 ? '(' + (d.mail > 99 ? '99+' : d.mail) + ') ' : '') + baseTitle;
      appBadge(d.mail);
      if (d.latest) {
        alertOnce('ecw.alerted.mail', d.latest.id, d.latest.from || 'New email', d.latest.subject || '(no subject)',
          base + 'app.php?thread=' + d.latest.thread_id + '&mailbox=' + d.latest.mailbox_id);
      }
      document.dispatchEvent(new CustomEvent('ecw:nav-badges', { detail: d }));
    } catch (_) { /* try again on the next tick */ } finally { busy = false; }
  }
  const soon = () => { clearTimeout(timer); timer = setTimeout(refresh, 400); };

  document.addEventListener('ecw:unread-changed', soon);
  let lastLive = null;
  document.addEventListener('ecw:updates', (e) => {
    const n = e.detail && e.detail.unread;
    if (lastLive !== null && n !== lastLive) soon();
    lastLive = n;
  });
  document.addEventListener('ecw:teams-unread', (e) => badge('teams', e.detail, true));
  // New bell notifications (mentions, reminders, assignments) — notifications.js passes them on.
  document.addEventListener('ecw:notif-items', (e) => {
    const fresh = (e.detail || []).filter((n) => !n.read).sort((a, b) => b.id - a.id)[0];
    if (fresh) alertOnce('ecw.alerted.notif', fresh.id, 'Case Workspace', fresh.text, fresh.link ? base + fresh.link : null);
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  // A hidden tab keeps polling (more slowly) only when desktop alerts are on — that is when they matter.
  let ticks = 0;
  setInterval(() => { ticks++; if (!document.hidden || (alertsOn() && ticks % 2 === 0)) refresh(); }, 30000);
  alertControls(document.getElementById('notifPanel'));
  alertControls(document.getElementById('alertSettings'));
  refresh();
})();
