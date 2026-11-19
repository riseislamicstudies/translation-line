// Service worker of «سکوی رایز»: only receives push messages and handles taps on them.
// It does not cache anything, so a new index.html uploaded to the host is always what people get.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => { /* network only (a handler is kept so browsers treat the app as installable) */ });

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'سکوی رایز', {
    body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag, lang: 'fa', dir: 'rtl',
    icon: 'icon-192.png', badge: 'icon-192.png', data: { url: d.url || './' },
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const target = new URL((e.notification.data && e.notification.data.url) || './', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const c of list) { if (c.url.startsWith(self.registration.scope) && 'focus' in c) return c.focus(); }
    return self.clients.openWindow(target);
  }));
});
