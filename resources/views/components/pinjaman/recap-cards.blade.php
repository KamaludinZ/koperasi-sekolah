@props(['portfolio'])
{{-- Kartu ringkasan posisi pinjaman (tidak bergantung periode): pinjaman aktif & sisanya, dana tercairkan kumulatif & yang sudah kembali. --}}
@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $p = $portfolio; @endphp
<section class="grid grid-cols-1 gap-3 md:grid-cols-3" aria-label="Ringkasan pinjaman">
    <div class="rounded-xl bg-emerald-900 px-4 py-3 text-white" data-summary="aktif">
        <p class="text-xs text-emerald-200">Pinjaman aktif</p>
        <p class="text-2xl font-bold tabular-nums">{{ $p['active'] }} <span class="text-sm font-normal text-emerald-200">pinjaman</span></p>
        <p class="text-sm tabular-nums">Sisa {{ $rp($p['outstanding']) }}</p>
        <p class="text-xs text-emerald-200">Pokok {{ $rp($p['principal_outstanding']) }} · bunga {{ $rp($p['interest_outstanding']) }}</p>
    </div>
    <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3" data-summary="tercairkan">
        <p class="text-xs text-indigo-900">Dana tercairkan (seluruhnya)</p>
        <p class="text-2xl font-bold tabular-nums text-indigo-950">{{ $rp($p['disbursed_total']) }}</p>
        <p class="text-xs text-indigo-900/80">{{ $p['disbursed_count'] }} pencairan · {{ $p['paid_off'] }} pinjaman lunas</p>
    </div>
    <div class="rounded-xl border border-stone-200 bg-white px-4 py-3" data-summary="kembali">
        <p class="text-xs text-stone-500">Angsuran kembali (seluruhnya)</p>
        <p class="text-2xl font-bold tabular-nums">{{ $rp($p['collected_total']) }}</p>
        <div class="mt-1 h-2 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuenow="{{ $p['collection_rate'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Persentase kewajiban yang sudah kembali">
            <div class="h-full bg-emerald-600" style="width: {{ min(100, $p['collection_rate']) }}%"></div>
        </div>
        <p class="mt-1 text-xs text-stone-500">{{ str_replace('.', ',', (string) $p['collection_rate']) }}% dari pokok + bunga pinjaman yang sudah cair
            @if ($p['overdue'] > 0)· <span class="font-semibold text-red-800">{{ $p['overdue'] }} menunggak ({{ $rp($p['overdue_outstanding']) }})</span>@endif</p>
    </div>
</section>
