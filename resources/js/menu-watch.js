/**
 * Pantau perubahan hak akses petugas (peran ditambah/dicabut, hak menu peran diubah admin) selagi halaman terbuka.
 * Versi menu awal ditanam server di layout; setiap respons Livewire membawa header X-Menu-Version, dan tiap 60 detik
 * (saat tab terlihat) GET /akun/menu dicek dengan If-None-Match (304 bila sama — murah, tidak memperpanjang sesi).
 * Bila versinya berubah, spanduk "Hak akses Anda berubah" tampil dengan tombol muat ulang.
 */
export default (initial, url) => ({
    version: initial,
    changed: false,
    timer: null,

    init() {
        const check = (v) => {
            if (v && this.version && v !== this.version) this.changed = true;
        };
        window.Livewire?.hook('request', ({ respond }) => {
            respond(({ response }) => check(response?.headers?.get('X-Menu-Version')));
        });
        const poll = async () => {
            if (this.changed || document.visibilityState !== 'visible') return;
            try {
                const res = await fetch(url, {
                    headers: { Accept: 'application/json', 'If-None-Match': `"menu-${this.version}"` },
                    credentials: 'same-origin',
                });
                if (res.status === 200) check((await res.json())?.meta?.version);
            } catch (e) {
                // jaringan putus: coba lagi pada putaran berikutnya
            }
        };
        this.timer = setInterval(poll, 60000);
        this._onVisible = () => document.visibilityState === 'visible' && poll();
        document.addEventListener('visibilitychange', this._onVisible);
    },

    destroy() {
        clearInterval(this.timer);
        document.removeEventListener('visibilitychange', this._onVisible);
    },

    reload() {
        window.location.reload();
    },
});
