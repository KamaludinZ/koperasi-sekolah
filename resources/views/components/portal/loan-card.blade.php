@props(['loan'])
{{-- Kartu satu pinjaman anggota (portal): kode, nominal, status, progres terbayar, dan pesan sesuai status. --}}
@php $l = $loan; $rp = fn ($n) => \App\Support\Rupiah::format($n); @endphp
<li {{ $attributes->class(['rounded-2xl border bg-white p-4', 'border-red-200' => $l['status'] === 'menunggak', 'border-stone-200' => $l['status'] !== 'menunggak']) }} data-my-loan="{{ $l['code'] }}">
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

    @if ($l['balance'] ?? null)
        @php $b = $l['balance']; @endphp
        <dl class="mt-3 grid grid-cols-3 gap-2 text-xs" data-loan-balance="{{ $l['code'] }}">
            <div><dt class="text-stone-500">Sisa pokok</dt><dd class="font-medium tabular-nums">{{ $rp($b['principal_remaining']) }}</dd></div>
            <div><dt class="text-stone-500">Sisa bunga</dt><dd class="font-medium tabular-nums">{{ $rp($b['interest_remaining']) }}</dd></div>
            <div><dt class="text-stone-500">Angsuran lunas</dt><dd class="font-medium tabular-nums">{{ $b['installments_paid'] }} dari {{ $b['installments'] }}</dd></div>
        </dl>
    @endif

    @if ($l['next'])
        <p @class(['mt-3 rounded-lg px-3 py-2 text-sm', 'bg-red-50 text-red-800' => $l['next']['late'], 'bg-stone-50 text-stone-700' => ! $l['next']['late']])>
            Angsuran ke-{{ $l['next']['sequence'] }}: {{ $rp($l['next']['remaining']) }} · jatuh tempo @tanggal($l['next']['due_date'])
            @if ($l['late_count'] > 0)<span class="block text-xs">{{ $l['late_count'] }} angsuran lewat jatuh tempo, total {{ $rp($l['late_amount']) }} — segera hubungi pengurus.</span>@endif
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
