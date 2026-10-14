@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $loans = $this->loans; $t = $this->totals; @endphp
<div class="space-y-5">
    <h1 class="text-xl font-semibold">Pinjaman saya</h1>

    @if ($loans === [])
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center" data-empty="pinjaman-saya">
            <p class="font-medium text-stone-700">Belum ada pinjaman</p>
            <p class="mt-1 text-sm text-stone-500">Pengajuan pinjaman dicatat oleh pengurus koperasi. Hubungi pengurus bila ingin mengajukan pinjaman.</p>
        </div>
    @else
        @if ($t['active'] > 0)
            <section class="rounded-2xl bg-emerald-900 p-4 text-white" aria-label="Ringkasan pinjaman">
                <p class="text-xs text-emerald-200">Sisa pinjaman berjalan</p>
                <p class="text-2xl font-bold tabular-nums" data-total-outstanding>{{ $rp($t['outstanding']) }}</p>
                <p class="text-xs text-emerald-200">{{ $t['active'] }} pinjaman aktif</p>
            </section>
        @endif

        <ul class="space-y-3" aria-label="Daftar pinjaman">
            @foreach ($loans as $l)
                <li wire:key="my-loan-{{ $l['id'] }}" @class(['rounded-2xl border bg-white p-4', 'border-red-200' => $l['status'] === 'menunggak', 'border-stone-200' => $l['status'] !== 'menunggak']) data-my-loan="{{ $l['code'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-mono text-xs text-stone-500">{{ $l['code'] }}</p>
                            <p class="text-lg font-semibold tabular-nums">{{ $rp($l['principal']) }}</p>
                            <p class="text-xs text-stone-500">Bunga {{ $rp($l['interest']) }} · {{ $l['tenor_months'] }} bulan · {{ $l['payment_label'] }}</p>
                        </div>
                        <x-pinjaman.status :status="$l['status']" />
                    </div>

                    @if ($l['progress'] !== null)
                        <div class="mt-3">
                            <div class="flex justify-between text-xs text-stone-500"><span>Sisa {{ $rp($l['outstanding']) }}</span><span>{{ $l['progress'] }}% terbayar</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuenow="{{ $l['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Persentase terbayar {{ $l['code'] }}">
                                <div @class(['h-full', 'bg-red-500' => $l['status'] === 'menunggak', 'bg-emerald-600' => $l['status'] !== 'menunggak']) style="width: {{ $l['progress'] }}%"></div>
                            </div>
                        </div>
                    @endif

                    @if ($l['next'])
                        <p @class(['mt-3 rounded-lg px-3 py-2 text-sm', 'bg-red-50 text-red-800' => $l['next']['late'], 'bg-stone-50 text-stone-700' => ! $l['next']['late']])>
                            Angsuran ke-{{ $l['next']['sequence'] }}: {{ $rp($l['next']['remaining']) }} · jatuh tempo @tanggal($l['next']['due_date'])
                            @if ($l['late_count'] > 0)<span class="block text-xs">{{ $l['late_count'] }} angsuran lewat jatuh tempo — segera hubungi pengurus.</span>@endif
                        </p>
                    @elseif ($l['status'] === 'lunas')
                        <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Lunas @tanggal($l['paid_off_at']). Terima kasih!</p>
                    @elseif ($l['status'] === 'diajukan')
                        <p class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">Pengajuan @tanggal($l['applied_at']) sedang menunggu keputusan pengurus.</p>
                    @elseif ($l['status'] === 'disetujui')
                        <p class="mt-3 rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-900">Disetujui — dana akan diserahkan pengurus.</p>
                    @elseif ($l['status'] === 'ditolak')
                        <p class="mt-3 rounded-lg bg-stone-100 px-3 py-2 text-sm text-stone-700">Pengajuan tidak disetujui{{ $l['decision_note'] ? ': '.$l['decision_note'] : '.' }}</p>
                    @elseif ($l['status'] === 'dibatalkan')
                        <p class="mt-3 rounded-lg bg-stone-100 px-3 py-2 text-sm text-stone-700">Pengajuan dibatalkan{{ $l['cancel_reason'] ? ': '.$l['cancel_reason'] : '.' }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
