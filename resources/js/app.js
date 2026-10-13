import './bootstrap';
import salesChart from './sales-chart';
import registerCopy from './clipboard';

/** Format rupiah seragam (sama dengan App\Support\Rupiah): Rp12.500, minus memakai "−". */
window.rupiah = (n, signed = false) => {
    const v = Math.round(Number(n) || 0);
    return (v < 0 ? '−' : signed && v > 0 ? '+' : '') + 'Rp' + Math.abs(v).toLocaleString('id-ID');
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('salesChart', salesChart);
    registerCopy(window.Alpine);
});

// Gagal terhubung / galat server saat aksi Livewire (mis. menyimpan) → tampilkan toast merah.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) return; // sesi habis: biarkan Livewire menampilkan halaman 419
            preventDefault();
            const message = status === 403
                ? 'Gagal: Anda tidak punya akses untuk tindakan ini.'
                : status === 0 || status === undefined
                    ? 'Gagal menyimpan: periksa koneksi internet lalu coba lagi.'
                    : 'Gagal menyimpan karena gangguan server. Coba lagi sebentar lagi.';
            window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message } }));
        });
    });
});
