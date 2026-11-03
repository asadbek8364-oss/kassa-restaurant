/* Service worker приложения «{{ $app }}» ресторана.
   Сначала сеть (сервер ресторана в локальной сети), при её отсутствии — сохранённая копия оболочки:
   приложение откроется и покажет, что нет связи с сервером. Запросы к /api не кэшируются. */
const CACHE = @json($cache);
const SHELL = @json($shell);
const PREFIX = @json('restoran-'.$app.'-');

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith(PREFIX) && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;
  const cached = () => caches.match(req).then((hit) => hit || caches.match(req, { ignoreSearch: true }))
    .then((hit) => hit || (req.mode === 'navigate' ? caches.match(@json('/'.$app)) : undefined));
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        }
        if (res.status >= 500) return cached().then((hit) => hit || res);
        return res;
      })
      .catch(() => cached().then((hit) => hit || Response.error())),
  );
});
