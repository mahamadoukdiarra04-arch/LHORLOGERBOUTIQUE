'use strict';
// No fetch handler: never cache authenticated screens or customer information.
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) {}
  event.waitUntil(self.registration.showNotification(data.title || 'L’Horloger', {
    body: data.body || 'Une nouvelle commande attend votre appel.',
    icon: '/favicon-192.png', badge: '/favicon-48.png', tag: data.tag || 'new-order',
    data: {url: data.url || '/closer/'},
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || '/closer/', self.location.origin);
  if (target.origin !== self.location.origin || !target.pathname.startsWith('/closer/')) return;
  event.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(async windows => {
    for (const window of windows) {
      if (new URL(window.url).pathname.startsWith('/closer/')) { await window.navigate(target.href); return window.focus(); }
    }
    return clients.openWindow(target.href);
  }));
});
