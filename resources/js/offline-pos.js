/**
 * Kasir offline (/kasir/offline): berjalan sepenuhnya di perangkat dari salinan katalog — cari/scan barang, keranjang,
 * bayar Tunai atau QRIS, cetak struk sementara (nomor OFF-…), simpan ke antrean, lalu sinkron otomatis saat server bisa
 * dihubungi lagi. Promo, kupon, diskon manual, saldo/simpanan/bon & PIN tidak tersedia offline.
 */
import * as store from './offline-store';

const pad = (n, w = 2) => String(n).padStart(w, '0');

export default (userId) => ({
    userId,
    catalog: null,
    online: navigator.onLine,
    syncing: false,
    message: null,
    search: '',
    category: '',
    cart: [], // [{id, name, price, qty, unit, stock}]
    method: 'tunai',
    paid: '',
    lastSale: null,
    sales: [],
    showQueue: false,
    _timer: null,

    async init() {
        this.catalog = await store.getMeta('catalog');
        await this.loadSales();
        await store.pruneSynced();
        window.addEventListener('online', () => this.tick());
        window.addEventListener('offline', () => (this.online = false));
        this._timer = setInterval(() => this.tick(), 30000);
        this.tick();
        this.$nextTick(() => this.$refs.search?.focus());
    },

    destroy() {
        clearInterval(this._timer);
    },

    /** Cek koneksi; bila tersambung: kirim antrean & perbarui katalog bila keranjang kosong. */
    async tick() {
        this.online = await store.reachable();
        if (!this.online) return;
        if ((await store.pendingSales()).length) await this.sync(true);
        const age = this.catalog ? Date.now() - Date.parse(this.catalog.downloaded_at) : Infinity;
        if (!this.cart.length && age > 30 * 60000) {
            try {
                this.catalog = await store.refreshCatalog();
            } catch {
                // sesi habis atau gangguan — tetap pakai salinan lama
            }
        }
    },

    get mine() {
        return this.catalog && this.catalog.user?.id === this.userId;
    },

    get expired() {
        return this.catalog && Date.parse(this.catalog.expires_at) < Date.now();
    },

    get ready() {
        return this.mine && !this.expired;
    },

    get categories() {
        return [...new Set((this.catalog?.products ?? []).map((p) => p.category).filter(Boolean))].sort();
    },

    /** Daftar barang: kode/barcode cocok persis → paling laku → nama (sama dengan kasir online). */
    get products() {
        if (!this.catalog) return [];
        const term = this.search.trim().toLowerCase();
        const rank = this.catalog.ranking ?? {};
        let rows = this.catalog.products.filter((p) => (!this.category || p.category === this.category)
            && (!term || p.name.toLowerCase().includes(term) || p.code.toLowerCase().includes(term) || String(p.barcode).toLowerCase() === term));
        const exact = (p) => (term && (p.code.toLowerCase() === term || String(p.barcode).toLowerCase() === term) ? 0 : 1);
        rows = rows.sort((a, b) => exact(a) - exact(b) || (rank[b.id] ?? 0) - (rank[a.id] ?? 0) || a.name.localeCompare(b.name, 'id'));
        return rows.slice(0, this.catalog.picker_limit ?? 60);
    },

    get totalMatches() {
        return this.catalog ? this.catalog.products.filter((p) => !this.category || p.category === this.category).length : 0;
    },

    isBest(id) {
        return (this.catalog?.best_sellers ?? []).includes(id);
    },

    inCart(id) {
        return this.cart.find((l) => l.id === id)?.qty ?? 0;
    },

    add(p) {
        if (!this.ready) return;
        if (p.stock - this.inCart(p.id) < 1) {
            this.flash('error', `${p.name} stoknya habis di salinan katalog.`);
            return;
        }
        const line = this.cart.find((l) => l.id === p.id);
        line ? line.qty++ : this.cart.push({ id: p.id, name: p.name, price: p.price, qty: 1, unit: p.unit, stock: p.stock });
        this.lastSale = null;
    },

    setQty(line, qty) {
        qty = Math.max(0, Math.min(999, parseInt(qty, 10) || 0));
        if (qty > line.stock) {
            this.flash('error', `Stok ${line.name} di salinan katalog tinggal ${line.stock}.`);
            qty = line.stock;
        }
        line.qty = qty;
        this.cart = this.cart.filter((l) => l.qty > 0);
    },

    /** Enter di kolom cari: kode/barcode persis atau hasil tunggal langsung masuk keranjang. */
    pickFromSearch() {
        const term = this.search.trim().toLowerCase();
        if (!term) return;
        const list = this.products;
        const hit = list.find((p) => p.code.toLowerCase() === term || String(p.barcode).toLowerCase() === term) ?? (list.length === 1 ? list[0] : null);
        if (hit) {
            this.add(hit);
            this.search = '';
        } else {
            this.flash(list.length ? 'info' : 'error', list.length ? `${list.length} barang cocok — pilih salah satu.` : `Kode “${this.search}” tidak dikenali.`);
        }
    },

    get subtotal() {
        return this.cart.reduce((s, l) => s + l.price * l.qty, 0);
    },

    get totals() {
        return store.compute(this.subtotal, this.catalog?.tax ?? {}, this.method);
    },

    get change() {
        return this.method === 'tunai' ? (parseInt(this.paid, 10) || 0) - this.totals.payable : 0;
    },

    async pay() {
        if (!this.ready || !this.cart.length) return;
        const total = this.totals.payable;
        const paid = this.method === 'tunai' ? parseInt(this.paid, 10) || 0 : total;
        if (paid < total) {
            this.flash('error', 'Uang bayar kurang dari total belanja.');
            return;
        }
        const now = new Date();
        const dev = (await store.deviceId()).replace(/[^A-Za-z0-9]/g, '').slice(-4).toUpperCase();
        const seq = ((await store.getMeta('seq')) ?? 0) + 1;
        await store.setMeta('seq', seq);
        const sale = {
            client_ref: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`,
            catalog_id: this.catalog.catalog_id,
            device_id: await store.deviceId(),
            local_number: `OFF-${dev}-${String(now.getFullYear()).slice(2)}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(seq, 4)}`,
            sold_at: now.toISOString(),
            payment_method: this.method,
            paid,
            total,
            items: this.cart.map((l) => ({ product_id: l.id, name: l.name, quantity: l.qty, price: l.price })),
            status: 'antre',
            user_id: this.userId,
            totals: this.totals,
        };
        await store.putSale(sale);
        // stok salinan katalog ikut berkurang agar kasir tidak menjual melebihi stok yang diketahui
        for (const l of this.cart) {
            const p = this.catalog.products.find((x) => x.id === l.id);
            if (p) p.stock = Math.max(0, p.stock - l.qty);
        }
        await store.setMeta('catalog', JSON.parse(JSON.stringify(this.catalog)));
        this.lastSale = sale;
        this.cart = [];
        this.paid = '';
        this.method = 'tunai';
        await this.loadSales();
        this.tick();
    },

    newSale() {
        this.lastSale = null;
        this.$nextTick(() => this.$refs.search?.focus());
    },

    printReceipt() {
        window.print();
    },

    async loadSales() {
        this.sales = await store.allSales();
    },

    get pendingCount() {
        return this.sales.filter((s) => s.status === 'antre').length;
    },

    get problemCount() {
        return this.sales.filter((s) => ['ditolak', 'tidak_sah', 'perlu_dicek'].includes(s.status)).length;
    },

    async sync(quiet = false) {
        if (this.syncing) return;
        this.syncing = true;
        try {
            const r = await store.syncQueue();
            await this.loadSales();
            if (r.status === 401 || r.status === 419) {
                this.flash('error', 'Sesi masuk habis. Masuk lagi (sambil online) agar antrean bisa dikirim — data di perangkat tetap aman.');
            } else if (r.status) {
                if (!quiet) this.flash('error', `Sinkron gagal (kode ${r.status}). Antrean tetap tersimpan, dicoba lagi otomatis.`);
            } else if (r.sent && !quiet) {
                this.flash('success', `${r.sent} penjualan terkirim.`);
            } else if (r.sent) {
                this.flash('success', `${r.sent} penjualan offline terkirim ke server.`);
            }
        } catch {
            if (!quiet) this.flash('error', 'Server belum bisa dihubungi. Antrean tetap tersimpan di perangkat.');
        } finally {
            this.syncing = false;
        }
    },

    flash(type, text) {
        this.message = { type, text };
        clearTimeout(this._flash);
        this._flash = setTimeout(() => (this.message = null), 6000);
    },

    statusLabel(s) {
        return { antre: 'Belum terkirim', terkirim: 'Terkirim', perlu_dicek: 'Terkirim · perlu dicek', ditolak: 'Ditolak server', tidak_sah: 'Data tidak sah' }[s] ?? s;
    },
});
