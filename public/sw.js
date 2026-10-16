/*
 * Service worker kasir offline. Hanya menyimpan (cache) yang dibutuhkan agar halaman /kasir/offline bisa dibuka tanpa
 * internet: halaman itu sendiri (jaringan dulu, cadangan dari cache), aset build Vite (/build/assets) dan skrip Livewire
 * (membawa Alpine) — keduanya cache dulu.
 * Halaman & data lain TIDAK pernah disimpan. Pesan 'clear' menghapus cache (dipakai saat keluar).
 */
const CACHE = 'kopsis-kasir-v1';
const PAGE = '/kasir/offline';

async function warm() {
    const cache = await caches.open(CACHE);
    const res = await fetch(PAGE, { credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok || res.redirected) return; // belum masuk / bukan kasir → tidak disimpan
    await cache.put(PAGE, res.clone());
    const html = await res.text();
    // aset Vite (/build/assets) + skrip Livewire (membawa Alpine) — dengan query versinya
    const assets = [...new Set([...html.matchAll(/(?:href|src)="([^"]*\/(?:build\/assets|livewire[^"\/]*)\/[^"]+)"/g)].map((m) => { const u = new URL(m[1].replace(/&amp;/g, '&'), self.location.origin); return u.pathname + u.search; }))];
    await Promise.all(assets.map((a) => cache.add(a).catch(() => null)));
}

self.addEventListener('install', (event) => {
    event.waitUntil(warm().catch(() => null));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('kopsis-kasir-') && key !== CACHE) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data === 'warm') event.waitUntil(warm().catch(() => null));
    if (event.data === 'clear') event.waitUntil(caches.delete(CACHE));
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (url.pathname === PAGE) {
        event.respondWith((async () => {
            try {
                const res = await fetch(event.request);
                if (res.ok && !res.redirected) (await caches.open(CACHE)).put(PAGE, res.clone());
                return res;
            } catch {
                return (await caches.match(PAGE)) ?? new Response('Kasir offline belum disiapkan di perangkat ini. Buka /kasir sekali saat online.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
            }
        })());
        return;
    }

    if (url.pathname.startsWith('/build/assets/') || /^\/livewire[^/]*\/livewire(\.min)?\.js$/.test(url.pathname)) {
        event.respondWith((async () => {
            const hit = await caches.match(event.request);
            if (hit) return hit;
            const res = await fetch(event.request);
            if (res.ok) (await caches.open(CACHE)).put(event.request, res.clone());
            return res;
        })());
    }
});
