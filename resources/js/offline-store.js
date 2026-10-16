/**
 * Penyimpanan kasir offline di perangkat (IndexedDB "kopsis-kasir"):
 * - meta: salinan katalog terakhir (barang, harga, pajak, struk, shift) & id perangkat;
 * - queue: penjualan offline (status antre | terkirim | perlu_dicek | ditolak | tidak_sah) — DIPERTAHANKAN sampai
 *   terkirim, walau petugas keluar; hanya petugas pemilik salinan katalog yang bisa menyinkronkannya.
 * Juga hitung total (sama persis dengan App\Support\PriceCalculator) dan sinkron ke server.
 */
const DB_NAME = 'kopsis-kasir';
const DB_VERSION = 1;
let dbPromise = null;

function db() {
    dbPromise ??= new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const d = req.result;
            if (!d.objectStoreNames.contains('meta')) d.createObjectStore('meta');
            if (!d.objectStoreNames.contains('queue')) {
                const q = d.createObjectStore('queue', { keyPath: 'client_ref' });
                q.createIndex('status', 'status');
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

async function tx(store, mode, fn) {
    const d = await db();
    return new Promise((resolve, reject) => {
        const t = d.transaction(store, mode);
        const result = fn(t.objectStore(store));
        t.oncomplete = () => resolve(result?.result ?? result);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error);
    });
}

const req2promise = (r) => new Promise((resolve, reject) => { r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error); });

export async function getMeta(key) {
    const d = await db();
    return req2promise(d.transaction('meta').objectStore('meta').get(key));
}

export function setMeta(key, value) {
    return tx('meta', 'readwrite', (s) => s.put(value, key));
}

export async function deviceId() {
    let id = await getMeta('device_id');
    if (!id) {
        id = 'dev-' + (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
        await setMeta('device_id', id);
    }
    return id;
}

export async function allSales() {
    const d = await db();
    const rows = await req2promise(d.transaction('queue').objectStore('queue').getAll());
    return rows.sort((a, b) => (a.sold_at < b.sold_at ? 1 : -1));
}

export function putSale(sale) {
    return tx('queue', 'readwrite', (s) => s.put(sale));
}

/** Penjualan yang masih perlu dikirim. */
export async function pendingSales() {
    return (await allSales()).filter((s) => s.status === 'antre').reverse();
}

/** Buang catatan yang sudah terkirim lebih dari 14 hari (yang belum terkirim tidak pernah dibuang). */
export async function pruneSynced(days = 14) {
    const limit = Date.now() - days * 86400000;
    const old = (await allSales()).filter((s) => s.status !== 'antre' && s.synced_at && Date.parse(s.synced_at) < limit);
    await tx('queue', 'readwrite', (store) => old.forEach((s) => store.delete(s.client_ref)));
    return old.length;
}

/** Sama persis dengan App\Support\PriceCalculator::compute. */
export function compute(subtotal, tax, method = 'tunai') {
    const rate = tax?.enabled ? Math.max(0, Number(tax.rate) || 0) : 0;
    const included = !!tax?.included;
    const phpRound = (x) => Math.sign(x) * Math.round(Math.abs(x)); // round() PHP: setengah menjauhi nol
    const added = rate > 0 && !included ? phpRound((subtotal * rate) / 100) : 0;
    const inside = rate > 0 && included ? phpRound(subtotal - subtotal / (1 + rate / 100)) : 0;
    const total = subtotal + added;
    const mode = tax?.rounding ?? 'none';
    const applies = mode !== 'none' && (!(tax?.cash_only ?? true) || method === 'tunai');
    const unit = Math.max(1, parseInt(tax?.rounding_unit ?? 100, 10));
    const payable = !applies ? total : mode === 'up' ? Math.ceil(total / unit) * unit : mode === 'down' ? Math.floor(total / unit) * unit : phpRound(total / unit) * unit;
    return { subtotal, tax: added, tax_included: inside, total, rounding: payable - total, payable };
}

let token = null; // token CSRF terbaru dari /kasir/offline/sesi (halaman dari cache bisa membawa token lama)

function csrf() {
    return token ?? document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** Ambil token CSRF baru dari server (halaman offline bisa dimuat dari cache dengan token lama). */
async function freshToken() {
    try {
        const res = await fetch('/kasir/offline/sesi', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) return false;
        token = (await res.json()).csrf;
        return !!token;
    } catch {
        return false;
    }
}

/** Unduh salinan katalog baru (butuh internet & sesi masuk). Mengembalikan katalog atau melempar galat. */
export async function refreshCatalog() {
    const id = await deviceId();
    const res = await fetch('/kasir/offline/katalog?perangkat=' + encodeURIComponent(id), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store',
    });
    if (!res.ok) throw Object.assign(new Error('Gagal mengunduh katalog (' + res.status + ')'), { status: res.status });
    const catalog = (await res.json()).data;
    catalog.downloaded_at = new Date().toISOString();
    await setMeta('catalog', catalog);
    return catalog;
}

/**
 * Kirim antrean (≤ 50 per kiriman). Hasil tiap penjualan disimpan kembali di perangkat.
 * @returns {Promise<{sent: number, results: Array, status?: number}>}
 */
export async function syncQueue() {
    const pending = await pendingSales();
    if (!pending.length) return { sent: 0, results: [] };
    if (!csrf()) await freshToken();
    let sent = 0;
    let retried = false;
    const results = [];
    for (let i = 0; i < pending.length; i += 50) {
        const batch = pending.slice(i, i + 50);
        const res = await fetch('/kasir/offline/sinkron', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ penjualan: batch.map(({ status, result, synced_at, user_id, totals, ...sale }) => sale) }),
        });
        if (res.status === 419 && !retried) {
            retried = true;
            if (await freshToken()) { i -= 50; continue; } // token CSRF lama (halaman dari cache) → ambil baru, ulangi
        }
        if (!res.ok) return { sent, results, status: res.status };
        const data = (await res.json()).data;
        for (const r of data) {
            const sale = batch.find((s) => s.client_ref === r.client_ref);
            if (!sale) continue;
            sale.status = r.status === 'diterima' ? 'terkirim' : r.status;
            sale.result = r;
            sale.synced_at = new Date().toISOString();
            await putSale(sale);
            results.push(r);
            sent++;
        }
    }
    return { sent, results };
}

/** Server bisa dihubungi? (navigator.onLine saja tidak cukup: Wi-Fi tersambung belum tentu ada internet). */
export async function reachable(timeoutMs = 4000) {
    if (!navigator.onLine) return false;
    try {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), timeoutMs);
        const res = await fetch('/up', { method: 'GET', cache: 'no-store', signal: ctrl.signal });
        clearTimeout(t);
        return res.ok;
    } catch {
        return false;
    }
}
