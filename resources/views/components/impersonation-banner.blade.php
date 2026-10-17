{{--
    Spanduk penanda sesi impersonasi — selalu tampil (menempel di atas) selama admin masuk sebagai akun lain, di aplikasi
    petugas maupun portal anggota. Menunjukkan admin pelaku, akun target, alasan, sisa waktu (hitung mundur), dan satu
    tombol untuk kembali ke akun admin. Saat waktu habis halaman dimuat ulang agar server menutup sesinya.
--}}
@php
    $imp = \App\Support\ImpersonationSession::current();
    $exitUrl = route('impersonasi.selesai');
    $left = \App\Support\ImpersonationSession::remainingSeconds();
    $recorded = $imp ? \App\Services\RoleImpersonationTrail::actionCount($imp['id']) : 0;
@endphp
@if ($imp)
    <div class="sticky top-0 z-[60] border-b-2 border-red-800 bg-[repeating-linear-gradient(135deg,#991b1b_0_14px,#b91c1c_14px_28px)] text-white shadow-md"
         role="region" aria-label="Mode impersonasi" data-impersonation-banner
         x-data="{
             left: {{ $left }},
             get soon() { return this.left <= 120 },
             timer: null,
             get clock() { const m = Math.floor(this.left / 60), s = this.left % 60; return m + ':' + String(s).padStart(2, '0') },
             init() { this.timer = setInterval(() => { if (this.left > 0) { this.left--; } else { clearInterval(this.timer); window.location.reload(); } }, 1000) },
             destroy() { clearInterval(this.timer) }
         }">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-bold tracking-wider uppercase">
                <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                Mode impersonasi
            </span>
            <p class="min-w-0 flex-1">
                <strong>{{ $imp['admin_name'] }}</strong> sedang masuk sebagai <strong data-impersonation-target>{{ $imp['target_name'] }}</strong>{{ $imp['target_label'] ? ' · '.$imp['target_label'] : '' }}
                <span class="text-red-100">({{ $imp['target_type'] === 'anggota' ? 'portal anggota' : 'petugas' }})</span>
                <span class="block truncate text-xs text-red-100" title="{{ $imp['reason'] }}">Alasan: {{ $imp['reason'] }} · semua aksi tercatat atas nama {{ $imp['admin_name'] }}</span>
                @if ($imp['target_type'] === 'anggota')
                    <span class="block text-xs font-semibold text-white" data-read-only-mode>Mode lihat saja: belanja, pembayaran, PIN & pembatalan pesanan dinonaktifkan.</span>
                @endif
            </p>
            <span class="rounded-full bg-white/15 px-2 py-0.5 text-xs" data-recorded-actions title="Aksi yang tercatat di jejak audit atas nama {{ $imp['admin_name'] }} selama sesi ini">{{ $recorded }} aksi tercatat</span>
            <span class="text-xs tabular-nums" aria-live="off" x-bind:class="soon && 'animate-pulse rounded bg-amber-300 px-1.5 text-red-900'">
                <span x-show="soon" x-cloak class="font-bold">Segera berakhir · </span>Sisa <strong class="text-sm" x-text="clock">{{ intdiv($left, 60) }}:{{ str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT) }}</strong></span>
            @if ($exitUrl)
                <form method="POST" action="{{ $exitUrl }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-white px-3 py-1.5 text-sm font-bold text-red-800 shadow hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-red-800" data-impersonation-exit>
                        Kembali ke akun saya
                    </button>
                </form>
            @endif
        </div>
    </div>
@endif
