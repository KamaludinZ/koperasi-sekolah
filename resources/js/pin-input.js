// Input PIN 6 kotak (Alpine): hanya angka, pindah otomatis, tempel sekaligus, Backspace mundur.
// Nilai disinkronkan ke properti Livewire lewat `model` (entangle); `complete` = 6 digit terisi.
export default (model, length = 6) => ({
    digits: Array(length).fill(''),
    value: model,
    length,
    get complete() {
        return this.digits.every((d) => /^\d$/.test(d));
    },
    init() {
        this.$watch('value', (v) => {
            if ((v ?? '') === '') this.digits = Array(this.length).fill(''); // dikosongkan server (mis. PIN salah)
        });
    },
    sync() {
        this.value = this.digits.join('');
    },
    input(i, e) {
        const only = e.target.value.replace(/\D/g, '');
        if (only.length > 1) return this.fill(only, i);
        this.digits[i] = only;
        e.target.value = only;
        this.sync();
        if (only && i < this.length - 1) this.$refs['d' + (i + 1)].focus();
    },
    fill(text, from = 0) {
        const only = String(text).replace(/\D/g, '').slice(0, this.length - from);
        [...only].forEach((d, k) => (this.digits[from + k] = d));
        this.sync();
        this.$refs['d' + Math.min(from + only.length, this.length - 1)].focus();
    },
    paste(e) {
        e.preventDefault();
        this.fill(e.clipboardData.getData('text'), 0);
    },
    back(i, e) {
        if (this.digits[i] === '' && i > 0) {
            e.preventDefault();
            this.digits[i - 1] = '';
            this.sync();
            this.$refs['d' + (i - 1)].focus();
        }
    },
});
