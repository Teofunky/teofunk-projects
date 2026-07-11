const CACHE = 'jandjoy-shell-v1';
const SHELL = ['/', '/manifest.webmanifest', '/icon-192.png', '/icon-512.png', '/favicon.svg'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).catch(() => {}));
  self.skipWaiting();
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))));
  self.clients.claim();
});
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.pathname.startsWith('/api/') || url.pathname.startsWith('/socket.io/')) return;
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).then(response => {
      const copy = response.clone(); caches.open(CACHE).then(cache => cache.put('/', copy)); return response;
    }).catch(() => caches.match('/')));
    return;
  }
  event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request).then(response => {
    if (response.ok && url.origin === location.origin) caches.open(CACHE).then(cache => cache.put(event.request, response.clone()));
    return response;
  })));
});
self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let data = { title: 'J&Joy', body: 'Новое защищённое сообщение', url: '/', tag: 'jj-message' };
    try { data = { ...data, ...event.data.json() }; } catch {}
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    const visible = windows.some(client => client.visibilityState === 'visible');
    windows.forEach(client => client.postMessage({ type: 'push', data }));
    if (visible) return;
    await self.registration.showNotification(data.title, {
      body: data.body,
      icon: '/icon-192.png',
      badge: '/icon-192.png',
      tag: data.tag,
      renotify: true,
      data: { url: data.url || '/' },
    });
    if ('setAppBadge' in self.navigator) self.navigator.setAppBadge().catch(() => {});
  })());
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const url = event.notification.data?.url || '/';
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if ('focus' in client) { await client.focus(); client.postMessage({ type: 'open-chat' }); return; }
    }
    if (clients.openWindow) await clients.openWindow(url);
  })());
});
