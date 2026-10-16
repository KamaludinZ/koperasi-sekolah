/**
 * Pencarian Pusat Panduan yang menyaring daftar & kartu SEKETIKA di peramban (tanpa menunggu server), lalu menyimpan
 * kata kunci ke Livewire (URL ?q=) setelah jeda. Semua kata harus muncul; tanpa beda huruf besar/aksen.
 * Penyaringan langsung ke DOM (atribut hidden) dan diulang setiap Livewire selesai memperbarui halaman, sehingga
 * elemen yang baru disisipkan server juga ikut tersaring.
 * Pintasan: "/" fokus ke kotak cari, Enter membuka hasil pertama, Esc mengosongkan, panah atas/bawah (serta Home/End)
 * berpindah antar-panduan di daftar.
 */
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default (initial) => ({
    q: initial || '',
    count: 0,
    get words() {
        return norm(this.q).split(/\s+/).filter(Boolean);
    },
    match(hay) {
        return this.words.every((w) => hay.includes(w));
    },
    apply() {
        const root = this.$root;
        let n = 0;
        root.querySelectorAll('[data-hay]').forEach((el) => {
            const ok = this.match(el.dataset.hay);
            (el.closest('[data-guide-row]') || el).hidden = !ok;
            if (ok && el.hasAttribute('data-guide-item')) n++;
        });
        root.querySelectorAll('[data-guide-group]').forEach((g) => {
            g.hidden = ![...g.querySelectorAll('[data-hay]')].some((el) => !(el.closest('[data-guide-row]') || el).hidden);
        });
        this.count = n;
        // tampilan "tidak ditemukan" vs keterangan hasil — diatur di sini (bukan x-show) agar tetap benar setelah morph Livewire
        const empty = this.q.trim() !== '' && n === 0;
        root.querySelectorAll('[data-guide-when-empty]').forEach((el) => (el.hidden = !empty));
        root.querySelectorAll('[data-guide-when-results]').forEach((el) => (el.hidden = empty));
        root.querySelectorAll('[data-guide-hint]').forEach((el) => (el.hidden = this.q.trim() === ''));
        root.querySelectorAll('[data-guide-when-noquery]').forEach((el) => (el.hidden = this.q.trim() !== ''));
        root.querySelectorAll('[data-guide-count]').forEach((el) => (el.textContent = n));
        root.querySelectorAll('[data-guide-query]').forEach((el) => (el.textContent = this.q.trim()));
    },
    openFirst() {
        const first = [...this.$root.querySelectorAll('[data-guide-item]')].find((el) => this.match(el.dataset.hay || ''));
        if (first) this.$wire.open(first.dataset.guideItem);
    },
    /** Pindah fokus ke panduan berikut/sebelumnya yang terlihat di daftar (dir: 1, -1, 'first', 'last'). */
    move(dir) {
        const items = [...this.$root.querySelectorAll('[data-guide-item]')].filter((el) => !el.closest('[hidden]'));
        if (!items.length) return;
        const i = items.indexOf(document.activeElement);
        const next = dir === 'first' ? 0 : dir === 'last' ? items.length - 1 : i === -1 ? (dir > 0 ? 0 : items.length - 1) : Math.min(Math.max(i + dir, 0), items.length - 1);
        items[next].focus();
    },
    clear() {
        this.q = '';
        this.$wire.set('search', '');
    },
    init() {
        this.$watch('q', () => this.apply());
        this.$nextTick(() => this.apply());
        this._unhook = window.Livewire?.hook('morph.updated', () => queueMicrotask(() => this.apply()));
        this._afterCommit = window.Livewire?.hook('commit', ({ succeed }) => succeed(() => setTimeout(() => this.apply())));
        this._slash = (e) => {
            if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
                e.preventDefault();
                this.$refs.input?.focus();
            }
        };
        window.addEventListener('keydown', this._slash);
    },
    destroy() {
        window.removeEventListener('keydown', this._slash);
        typeof this._unhook === 'function' && this._unhook();
        typeof this._afterCommit === 'function' && this._afterCommit();
    },
});
