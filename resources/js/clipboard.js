// Salin teks ke papan klip. navigator.clipboard hanya ada di koneksi aman (https/localhost);
// di jaringan sekolah lewat http dipakai cara lama (textarea + execCommand).
export async function copyText(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch (e) {
        // lanjut ke cara lama
    }
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (e) {
        ok = false;
    }
    area.remove();
    return ok;
}

// Alpine: x-on:click="$copy(teks, 'Ringkasan disalin.')" → toast berhasil/gagal.
export default function registerCopy(Alpine) {
    Alpine.magic('copy', () => async (text, message = 'Disalin ke papan klip.') => {
        const ok = await copyText(text);
        window.dispatchEvent(new CustomEvent('toast', {
            detail: ok ? { type: 'success', message } : { type: 'error', message: 'Gagal menyalin. Pilih teksnya lalu tekan Ctrl+C.' },
        }));
    });
}
