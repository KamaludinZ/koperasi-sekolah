/**
 * Penjaga mode offline di layar /kasir (online): memasang service worker agar /kasir/offline bisa dibuka tanpa
 * internet, mengunduh salinan katalog di latar (saat pertama & tiap 30 menit) supaya siap sebelum sinyal hilang,
 * menampilkan jumlah antrean penjualan offline yang belum terkirim (dan mengirimnya), serta spanduk "koneksi
 * terputus → buka kasir offline" saat jaringan putus atau aksi Livewire gagal karena koneksi.
 */
import * as store from './offline-store';

export default (userId) => ({
    userId,
    offline: !navigator.onLine,
    pending: 0,
    catalogAt: null,
    syncing: false,
    _timer: null,

    async init() {
        if ('serviceWorker' in navigator) {
            try {
                const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                (reg.active ?? reg.installing ?? reg.waiting)?.postMessage?.('warm');
            } catch {
                // browser tanpa dukungan / mode privat — kasir online tetap berjalan
            }
        }
        window.addEventListener('offline', () => (this.offline = true));
        window.addEventListener('online', () => this.check());
        window.addEventListener('koneksi-putus', () => (this.offline = true));
        this._timer = setInterval(() => this.check(), 60000);
        await this.check();
    },

    destroy() {
        clearInterval(this._timer);
    },

    async check() {
        try {
            this.pending = (await store.pendingSales()).filter((s) => s.user_id === this.userId).length;
            const catalog = await store.getMeta('catalog');
            this.catalogAt = catalog && catalog.user?.id === this.userId ? catalog.downloaded_at : null;
        } catch {
            return; // IndexedDB tidak tersedia
        }
        if (!(await store.reachable())) {
            this.offline = true;
            return;
        }
        this.offline = false;
        const age = this.catalogAt ? Date.now() - Date.parse(this.catalogAt) : Infinity;
        if (age > 30 * 60000) {
            try {
                this.catalogAt = (await store.refreshCatalog()).downloaded_at;
            } catch {
                // dicoba lagi pada pemeriksaan berikutnya
            }
        }
        if (this.pending) await this.sync();
    },

    async sync() {
        if (this.syncing) return;
        this.syncing = true;
        try {
            const r = await store.syncQueue();
            if (r.sent) window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: `${r.sent} penjualan offline terkirim ke server.` } }));
            this.pending = (await store.pendingSales()).filter((s) => s.user_id === this.userId).length;
        } catch {
            // tetap di antrean
        } finally {
            this.syncing = false;
        }
    },

    get catalogLabel() {
        if (!this.catalogAt) return 'belum diunduh';
        const m = Math.round((Date.now() - Date.parse(this.catalogAt)) / 60000);
        return m < 1 ? 'baru saja' : m < 60 ? `${m} menit lalu` : `${Math.round(m / 60)} jam lalu`;
    },
});
