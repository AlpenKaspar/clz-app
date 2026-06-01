const CACHE_NAME = 'clz-app-shell-v5';
const APP_SHELL = [
  '/',
  '/index.html',
  '/js/songs-audio-pitch.js',
  '/manifest.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  const url = new URL(request.url);
  if (url.origin !== location.origin || url.pathname.startsWith('/api/')) {
    return;
  }

  event.respondWith(
    fetch(request)
      .then(response => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, copy)).catch(() => {});
        return response;
      })
      .catch(() => caches.match(request).then(cached => cached || caches.match('/index.html')))
  );
});

self.addEventListener('push', event => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = {};
  }

  const show = async () => {
    if (!data.title && !data.body) {
      try {
        const res = await fetch('/api/push-summary.php', {
          cache: 'no-store',
          credentials: 'same-origin'
        });
        if (res.ok) {
          const summary = await res.json();
          if (summary && summary.ok && summary.notification) {
            data = Object.assign({}, data, summary.notification);
          }
        }
      } catch (e) {}
    }

    if (!data.title && !data.body) {
      try {
        const res = await fetch('/api/birthday-push-summary.php', {
          cache: 'no-store',
          credentials: 'same-origin'
        });
        if (res.ok) {
          const summary = await res.json();
          if (summary && summary.ok && summary.notification) {
            data = Object.assign({}, data, summary.notification);
          }
        }
      } catch (e) {}
    }

    const title = data.title || 'CLZ Spiez';
    const options = {
      body: data.body || 'Öffne die App für aktuelle Geburtstage.',
      icon: data.icon || 'https://rollsimply.com/elvanto-icon.png',
      badge: data.badge || 'https://rollsimply.com/elvanto-icon.png',
      tag: data.tag || 'clz-notification',
      renotify: !!data.renotify,
      data: {
        url: data.url || '/'
      }
    };

    return self.registration.showNotification(title, options);
  };

  event.waitUntil(show());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
      for (const client of windowClients) {
        if ('focus' in client && client.url && new URL(client.url).origin === self.location.origin) {
          client.navigate(targetUrl).catch(() => {});
          return client.focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(targetUrl);
      return null;
    })
  );
});
