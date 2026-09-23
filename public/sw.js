const CACHE_NAME = 'eventmenu-static-v6';
const MANIFEST_URL = './manifest.webmanifest';
const STATIC_SUFFIXES = [
  '/assets/app.css',
  '/assets/premium-v4.css',
  '/assets/premium-v4.js',
  '/assets/premium-v5.css',
  '/assets/premium-v5-runtime.css',
  '/assets/menu-premium-v5.css',
  '/assets/menu-premium-v5.js',
  '/assets/order-premium-v5.css',
  '/assets/order-premium-v5.js',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.add(MANIFEST_URL))
      .catch(() => undefined)
  );
  self.skipWaiting();
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
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const isStatic = STATIC_SUFFIXES.some(suffix => url.pathname.endsWith(suffix));
  const isManifest = url.pathname.endsWith('/manifest.webmanifest');
  if (!isStatic && !isManifest) return;

  if (isStatic) {
    // Network-first impede que um bundle visual antigo sobreviva a um deploy.
    event.respondWith(
      fetch(request, { cache: 'no-cache' })
        .then(response => {
          if (response && response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }
          return response;
        })
        .catch(() => caches.match(request).then(cached => cached || Response.error()))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cached => cached || fetch(request, { cache: 'no-cache' }).then(response => {
      if (response && response.ok) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
      }
      return response;
    }))
  );
});
