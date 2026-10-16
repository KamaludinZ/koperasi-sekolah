/**
 * Centang langkah panduan yang sudah dikerjakan. Tersimpan di localStorage peramban (per petugas & panduan),
 * hanya kemudahan pribadi — bukan data aplikasi. Bila penyimpanan diblokir, tetap berfungsi selama halaman terbuka.
 */
export default (key, total) => ({
    done: [],
    init() {
        try {
            const saved = JSON.parse(localStorage.getItem(key) || '[]');
            this.done = Array.isArray(saved) ? saved.filter((i) => Number.isInteger(i) && i >= 0 && i < total) : [];
        } catch (e) {
            this.done = [];
        }
    },
    has(i) {
        return this.done.includes(i);
    },
    toggle(i) {
        this.done = this.has(i) ? this.done.filter((x) => x !== i) : [...this.done, i];
        this.save();
    },
    reset() {
        this.done = [];
        this.save();
    },
    save() {
        try {
            this.done.length ? localStorage.setItem(key, JSON.stringify(this.done)) : localStorage.removeItem(key);
        } catch (e) {
            // penyimpanan peramban tidak tersedia (mode privat) — abaikan
        }
    },
});
