/**
 * Pencarian Pusat Panduan yang menyaring daftar & kartu SEKETIKA di peramban (tanpa menunggu server), lalu menyimpan
 * kata kunci ke Livewire (URL ?q=) setelah jeda. Semua kata harus muncul; tanpa beda huruf besar/aksen.
 * Penyaringan langsung ke DOM (atribut hidden) dan diulang setiap Livewire selesai memperbarui halaman, sehingga
 * elemen yang baru disisipkan server juga ikut tersaring.
 * Pintasan: "/" fokus ke kotak cari, Enter membuka hasil pertama, Esc mengosongkan.
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
    },
    openFirst() {
        const first = [...this.$root.querySelectorAll('[data-guide-item]')].find((el) => this.match(el.dataset.hay || ''));
        if (first) this.$wire.open(first.dataset.guideItem);
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
