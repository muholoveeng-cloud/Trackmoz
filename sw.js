/* TrackMoz Service Worker — shell offline do modo condução */
const CACHE = 'trackmoz-shell-v7';

function abs(path) {
  try {
    return new URL(path, self.registration.scope).href;
  } catch (e) {
    return self.registration.scope + path.replace(/^\//, '');
  }
}

const PRECACHE = [
  'offline.html',
  'assets/js/offline-sync.js',
  'assets/js/gps-tracker.js',
  'assets/js/mapa-core.js',
  'assets/js/pwa-install.js',
  'assets/css/pwa-install.css',
];

function shellUrl() {
  return abs('offline.html');
}

function isApiRequest(url) {
  var p = url.pathname || '';
  return (
    p.indexOf('/api/') !== -1 ||
    p.indexOf('atualizar-status-viagem') !== -1 ||
    p.indexOf('update-localizacao') !== -1 ||
    p.indexOf('conducao-control') !== -1 ||
    p.indexOf('entrega-confirmar') !== -1 ||
    p.indexOf('checklist-viagem') !== -1
  );
}

function shouldCacheResponse(url, request) {
  var p = url.pathname || '';
  if (request.mode === 'navigate') {
    return p.indexOf('modo-direcao') !== -1 || p.indexOf('offline.html') !== -1;
  }
  return (
    p.indexOf('/assets/js/offline-sync') !== -1 ||
    p.indexOf('/assets/js/gps-tracker') !== -1 ||
    p.indexOf('/assets/js/mapa-core') !== -1 ||
    p.indexOf('/assets/js/pwa-install') !== -1 ||
    p.indexOf('/assets/css/pwa-install') !== -1 ||
    p.indexOf('modo-direcao') !== -1 ||
    p.indexOf('offline.html') !== -1
  );
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return Promise.all(
        PRECACHE.map(function (path) {
          return cache.add(abs(path)).catch(function () { /* opcional */ });
        })
      );
    }).then(function () { return self.skipWaiting(); })
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

// Network-first para shell; APIs passam sempre à rede (sem cache)
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  var url;
  try {
    url = new URL(event.request.url);
  } catch (e) {
    return;
  }
  if (url.origin !== self.location.origin) return;
  if (isApiRequest(url)) return;

  event.respondWith(
    fetch(event.request)
      .then(function (res) {
        if (res && res.ok && shouldCacheResponse(url, event.request)) {
          var copy = res.clone();
          caches.open(CACHE).then(function (cache) {
            cache.put(event.request, copy);
          });
        }
        return res;
      })
      .catch(function () {
        return caches.match(event.request).then(function (cached) {
          if (cached) return cached;
          // Página modo condução: tentar match sem query string
          if ((url.pathname || '').indexOf('modo-direcao') !== -1) {
            return caches.keys().then(function () {
              return caches.open(CACHE).then(function (cache) {
                return cache.keys().then(function (keys) {
                  var hit = keys.find(function (k) {
                    return (k.url || '').indexOf('modo-direcao') !== -1;
                  });
                  return hit ? cache.match(hit) : null;
                });
              });
            }).then(function (page) {
              if (page) return page;
              if (event.request.mode === 'navigate') return caches.match(shellUrl());
              return Response.error();
            });
          }
          if (event.request.mode === 'navigate') {
            return caches.match(shellUrl());
          }
          return Response.error();
        });
      })
  );
});
