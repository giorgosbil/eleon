/*
 * Service worker for the installed app (scope: this folder).
 * Shows push notifications sent by lib/push.php and opens the right page when
 * one is tapped. It does not cache pages: mail must always be fresh, and the
 * app needs the server anyway.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) { data = { title: 'Case Workspace', body: event.data ? event.data.text() : '' }; }
  const url = new URL(data.url || 'app.php', self.registration.scope).href;
  // The app's taskbar icon (nav-badges.js sets it while the app is open): the unread count,
  // -1 for only a dot, 0 for nothing (Settings → Notifications).
  if (typeof data.badge === 'number' && 'setAppBadge' in self.navigator) {
    (data.badge > 0 ? self.navigator.setAppBadge(data.badge) : data.badge < 0 ? self.navigator.setAppBadge() : self.navigator.clearAppBadge()).catch(() => {});
  }
  event.waitUntil(self.registration.showNotification(data.title || data.product_name || 'Case Workspace', {
    body: data.body || '',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    icon: data.icon || 'assets/icons/icon-192.png',
    badge: 'assets/icons/badge-72.png',
    data: { url },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data && event.notification.data.url;
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.startsWith(self.registration.scope)) { await c.focus(); if (url) c.navigate(url).catch(() => {}); return; }
    }
    if (url) await self.clients.openWindow(url);
  })());
});
