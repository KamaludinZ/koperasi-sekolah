// Pendengar barcode reader (USB/Bluetooth, mode keyboard) untuk seluruh halaman kasir.
// Reader "mengetik" kode dengan sangat cepat lalu menekan Enter. Saat fokus TIDAK di kolom isian,
// semburan ketikan cepat (jeda antar-tombol ≤ maxGap ms, minimal minLength karakter, diakhiri Enter)
// dianggap hasil pindai dan dikirim ke `onCode` — kasir tidak perlu mengklik kolom cari dulu.
// Ketikan di kolom isian dibiarkan (kolom cari kasir sudah menangani Enter sendiri).
const EDITABLE = 'input, textarea, select, [contenteditable=""], [contenteditable="true"]';

export function createReader(onCode, { minLength = 4, maxGap = 50, now = () => Date.now() } = {}) {
    let buffer = '';
    let last = 0;
    let fast = true;

    const reset = () => { buffer = ''; fast = true; };

    return {
        /** @returns {boolean} true bila event ini bagian dari hasil pindai (pemanggil mencegah aksi bawaannya). */
        key(e) {
            if (e.ctrlKey || e.altKey || e.metaKey) { reset(); return false; }
            const target = e.target;
            if (target && target.closest && target.closest(EDITABLE)) { reset(); return false; }
            if (typeof document !== 'undefined' && document.querySelector('[aria-modal="true"]:not([style*="display: none"])')) { reset(); return false; }

            const t = now();
            const gap = t - last;
            last = t;

            if (e.key === 'Enter') {
                const code = buffer;
                const ok = fast && code.length >= minLength;
                reset();
                if (ok) { onCode(code); return true; }
                return false;
            }
            if (e.key.length !== 1) return false; // Shift, Tab, panah, dsb.

            if (buffer && gap > maxGap) { buffer = ''; fast = true; } // ketikan manual lambat → mulai ulang
            buffer += e.key;
            return buffer.length > 1; // karakter berikutnya dalam semburan: jangan picu shortcut halaman
        },
    };
}

export default (onCode = () => {}, options = {}) => ({
    reader: null,
    init() {
        this.reader = createReader((code) => onCode(code), options);
    },
    onKey(e) {
        if (this.reader.key(e)) e.preventDefault();
    },
});
