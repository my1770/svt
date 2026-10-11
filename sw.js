/* Service Worker: stale-while-revalidate для статики, network-first для остального */
const CACHE_STATIC = 'svt-static-v2';
const CACHE_RUNTIME = 'svt-runtime-v2';

const STATIC_ASSETS = [
  'css/app.css',
  'js/app.js',
  'js/vendor/alpine.min.js',
  'js/vendor/html5-qrcode.min.js',
  'manifest.webmanifest',
  'icon.svg',
  'offline.html'
];

/* В кэш кладём только настоящие статические ответы (не HTML-заглушки/редиректы) */
function cacheableStatic(resp) {
  if (!resp || !resp.ok) return false;
  const ct = resp.headers.get('content-type') || '';
  return !ct.includes('text/html');
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_STATIC)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_STATIC && k !== CACHE_RUNTIME).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  /* API — всегда сеть */
  if (url.pathname.includes('api_') || url.pathname.includes('auth')) {
    return;
  }

  const isStatic = /\.(css|js|svg|webmanifest)$/.test(url.pathname) || url.pathname.includes('/vendor/');

  if (isStatic) {
    /* stale-while-revalidate: отдаём кэш сразу, параллельно обновляем его сетью */
    event.respondWith(
      caches.match(req, { ignoreSearch: false }).then((cached) => {
        const network = fetch(req)
          .then((resp) => {
            if (cacheableStatic(resp)) {
              const clone = resp.clone();
              caches.open(CACHE_STATIC).then((cache) => cache.put(req, clone));
            }
            return resp;
          })
          .catch(() => null);
        if (cached) return cached;
        return network.then((resp) => resp || caches.match('offline.html'));
      })
    );
    return;
  }

  /* network-first для страниц */
  event.respondWith(
    fetch(req)
      .then((resp) => {
        if (resp.ok) {
          const clone = resp.clone();
          caches.open(CACHE_RUNTIME).then((cache) => cache.put(req, clone));
        }
        return resp;
      })
      .catch(() => caches.match(req).then((cached) => cached || caches.match('offline.html')))
  );
});
