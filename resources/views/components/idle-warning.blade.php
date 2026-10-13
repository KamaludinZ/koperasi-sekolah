{{--
    Peringatan sesi menganggur. Gerakan/ketikan petugas dianggap aktivitas: bila tidak ada permintaan
    lain, diberi tahu ke server lewat POST /akun/sesi/aktif (paling sering tiap 5 menit). Satu menit
    sebelum batas tampil peringatan dengan hitung mundur; saat habis halaman dimuat ulang sehingga
    server mengakhiri sesi dan mengarahkan ke halaman masuk. `minutes` 0 = nonaktif.
--}}
@props(['minutes'])
@if ($minutes > 0)
<div x-data="{
        limit: {{ (int) $minutes }} * 60000,
        last: Date.now(),
        pinged: Date.now(),
        left: null,
        init() {
            const touch = () => this.active();
            ['keydown', 'pointerdown', 'pointermove', 'wheel', 'touchstart'].forEach(e => window.addEventListener(e, touch, { passive: true }));
            document.addEventListener('livewire:navigated', touch);
            setInterval(() => this.tick(), 1000);
        },
        active() {
            if (this.left !== null && this.left <= 0) return;
            this.last = Date.now();
            this.left = null;
            if (Date.now() - this.pinged > Math.min(300000, this.limit / 2)) this.ping();
        },
        ping() {
            this.pinged = Date.now();
            fetch(@js(route('akun.sessions.ping')), { method: 'POST', headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' } })
                .then(r => { if (r.status === 401 || r.status === 419) window.location.reload() });
        },
        tick() {
            const left = Math.ceil((this.limit - (Date.now() - this.last)) / 1000);
            this.left = left <= 60 ? left : null;
            if (left <= 0) window.location.reload();
        },
     }">
    <div x-show="left !== null && left > 0" x-cloak x-transition.opacity
         class="fixed inset-x-4 bottom-4 z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-lg sm:inset-x-auto sm:right-6"
         role="alertdialog" aria-live="assertive" aria-labelledby="idle-title">
        <svg class="size-5 shrink-0 text-amber-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/></svg>
        <p class="min-w-0 flex-1"><span id="idle-title" class="font-semibold">Sesi hampir berakhir.</span>
            Keluar otomatis dalam <span class="font-semibold tabular-nums" x-text="left + ' detik'"></span> karena tidak ada aktivitas.</p>
        <button type="button" x-on:click="active(); ping()" class="shrink-0 rounded-xl bg-emerald-800 px-3 py-1.5 font-semibold text-white hover:bg-emerald-900">Tetap masuk</button>
    </div>
</div>
@endif
