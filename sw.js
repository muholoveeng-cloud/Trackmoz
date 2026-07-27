/* TrackMoz Service Worker — instalável no Chrome/Edge (desktop + Android) */
const CACHE = 'trackmoz-shell-v6';

function shellUrl() {
  try {
    return new URL('offline.html', self.registration.scope).href;
  } catch (e) {
    return self.registration.scope + 'offline.html';
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.add(shellUrl()).catch(function () { /* offline.html opcional */ }))
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// respondWith é o que o Chrome Android exige para considerar a app instalável
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  var url;
  try {
    url = new URL(event.request.url);
  } catch (e) {
    return;
  }
  if (url.origin !== self.location.origin) return;

  event.respondWith(
    fetch(event.request)
      .then(function (res) { return res; })
      .catch(function () {
        return caches.match(event.request).then(function (cached) {
          if (cached) return cached;
          if (event.request.mode === 'navigate') {
            return caches.match(shellUrl());
          }
          return Response.error();
        });
      })
  );
});
