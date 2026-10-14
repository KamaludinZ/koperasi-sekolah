// Pemindai barcode lewat kamera (webcam/HP) memakai html5-qrcode. Pustaka dimuat saat panel dibuka
// agar layar kasir tetap ringan. Kode yang terbaca dikirim ke callback `onCode`; kode yang sama
// diabaikan selama `cooldown` ms supaya satu barang tidak masuk berkali-kali.
const FORMATS = ['CODE_128', 'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'CODE_39', 'QR_CODE'];

const savedCamera = () => {
    try {
        return localStorage.getItem('kasir.camera') || '';
    } catch (e) {
        return '';
    }
};

export default (onCode = () => {}, cooldown = 1500) => ({
    open: false,
    status: 'idle', // idle | starting | scanning | error
    error: '',
    cameras: [],
    cameraId: savedCamera(),
    last: { code: '', at: 0 },
    lastFeedback: null, // {type, text} dari event scan-feedback (diisi tampilan)
    scanner: null,

    async start() {
        this.open = true;
        this.error = '';
        this.lastFeedback = null;
        if (!window.isSecureContext) {
            return this.fail('Kamera hanya bisa dipakai lewat alamat aman (https). Gunakan barcode reader atau ketik kode barang.');
        }
        this.status = 'starting';
        try {
            const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');
            this.cameras = await Html5Qrcode.getCameras();
            if (!this.cameras.length) return this.fail('Kamera tidak ditemukan di perangkat ini.');
            if (!this.cameras.some((c) => c.id === this.cameraId)) {
                const back = this.cameras.find((c) => /back|belakang|rear|environment/i.test(c.label));
                this.cameraId = (back || this.cameras[this.cameras.length - 1]).id;
            }
            await this.$nextTick();
            this.scanner = new Html5Qrcode(this.$refs.viewfinder.id, {
                formatsToSupport: FORMATS.map((f) => Html5QrcodeSupportedFormats[f]),
                verbose: false,
            });
            await this.scanner.start(this.cameraId, { fps: 10, qrbox: (w, h) => ({ width: Math.min(w * 0.85, 360), height: Math.min(h * 0.5, 160) }) },
                (text) => this.found(text), () => {});
            this.status = 'scanning';
        } catch (e) {
            const denied = /permission|notallowed|denied/i.test(String(e?.name || e));
            this.fail(denied ? 'Izin kamera ditolak. Izinkan kamera di pengaturan peramban, lalu coba lagi.' : 'Kamera tidak bisa dibuka. Tutup aplikasi lain yang memakai kamera, lalu coba lagi.');
        }
    },

    found(text) {
        const code = String(text).trim();
        const now = Date.now();
        if (!code || (code === this.last.code && now - this.last.at < cooldown)) return;
        this.last = { code, at: now };
        if (navigator.vibrate) navigator.vibrate(60);
        onCode(code);
    },

    async switchCamera(id) {
        this.cameraId = id;
        try { localStorage.setItem('kasir.camera', id); } catch (e) {}
        await this.stop(false);
        await this.start();
    },

    fail(message) {
        this.status = 'error';
        this.error = message;
    },

    async stop(close = true) {
        try {
            if (this.scanner?.isScanning) await this.scanner.stop();
            this.scanner?.clear();
        } catch (e) {}
        this.scanner = null;
        this.status = 'idle';
        if (close) this.open = false;
    },

    destroy() {
        this.stop();
    },
});
