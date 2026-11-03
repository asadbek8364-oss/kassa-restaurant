/* Оболочка «Кассы и склада» без сети: сначала сеть, при её отсутствии — сохранённая копия.
   Запросы к /api/kassa сюда не попадают: без связи приложение само продаёт по копии каталога
   и складывает чеки в очередь на устройстве (см. app.js). */
const CACHE = 'kassa-v7';
const SHELL = ['./index.html', './styles.css', './app.js', './manifest.webmanifest', './icon.svg', './icon-192.png', './icon-512.png', './apple-touch-icon.png'];
const CDN = /^(fonts\.googleapis\.com|fonts\.gstatic\.com|cdn\.jsdelivr\.net)$/;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('kassa-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  const inScope = url.origin === self.location.origin && url.pathname.startsWith(new URL(self.registration.scope).pathname);
  if (!inScope && !CDN.test(url.hostname)) return;
  const cached = () => caches.match(req, { ignoreSearch: true })
    .then((hit) => hit || (req.mode === 'navigate' ? caches.match('./index.html') : undefined));
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok || res.type === 'opaque') {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        }
        // сервер за прокси упал (502/503/504) — открываем сохранённую оболочку, касса продаёт без связи
        if (res.status >= 500) return cached().then((hit) => hit || res);
        return res;
      })
      .catch(() => cached().then((hit) => hit || Response.error())),
  );
});
