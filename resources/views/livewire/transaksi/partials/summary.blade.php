{{-- Ringkasan periode: jumlah transaksi, total penjualan, perbandingan periode sebelumnya, per metode bayar. --}}
@php
    $s = $this->summary;
    $prev = $s['previous'];
    $trend = function (?int $pct) {
        if ($pct === null) return null;
        return [
            'text' => ($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')).abs($pct).'%',
            'class' => $pct > 0 ? 'text-emerald-700' : ($pct < 0 ? 'text-red-600' : 'text-stone-500'),
        ];
    };
    $countTrend = $prev ? $trend($prev['count_change']) : null;
    $totalTrend = $prev ? $trend($prev['total_change']) : null;
@endphp
<div class="space-y-3">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
            <p class="text-xs text-stone-500">Jumlah transaksi</p>
            <p class="text-2xl font-bold tabular-nums">{{ number_format($s['count'], 0, ',', '.') }}</p>
            @if ($countTrend)
                <p class="text-xs"><span class="{{ $countTrend['class'] }} font-medium">{{ $countTrend['text'] }}</span> <span class="text-stone-500">vs {{ $prev['label'] }} ({{ $prev['count'] }})</span></p>
            @endif
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-xs text-emerald-800">Total penjualan</p>
            <p class="text-xl font-bold text-emerald-900 tabular-nums sm:text-2xl">{{ $rp($s['total']) }}</p>
            @if ($totalTrend)
                <p class="text-xs"><span class="{{ $totalTrend['class'] }} font-medium">{{ $totalTrend['text'] }}</span> <span class="text-emerald-800/70">vs {{ $rp($prev['total']) }}</span></p>
            @elseif ($prev)
                <p class="text-xs text-emerald-800/70">Periode sebelumnya belum ada penjualan</p>
            @endif
        </div>
        <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
            <p class="text-xs text-stone-500">Barang terjual</p>
            <p class="text-2xl font-bold tabular-nums">{{ number_format($s['items'], 0, ',', '.') }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
            <p class="text-xs text-stone-500">Rata-rata per transaksi</p>
            <p class="text-xl font-bold tabular-nums sm:text-2xl">{{ $rp($s['average']) }}</p>
        </div>
    </div>

    @if ($s['total'] > 0)
        <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
            <p class="mb-2 text-xs text-stone-500">Per metode bayar</p>
            <div class="flex h-2.5 overflow-hidden rounded-full bg-stone-100" role="img"
                 aria-label="@foreach ($s['by_method'] as $m){{ $m['label'] }} {{ $m['share'] }}%{{ $loop->last ? '' : ', ' }}@endforeach">
                @foreach ($s['by_method'] as $key => $m)
                    <div @class(['h-full', 'bg-emerald-600' => $key === 'tunai', 'bg-sky-500' => $key !== 'tunai']) style="width: {{ $m['share'] }}%"></div>
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs">
                @foreach ($s['by_method'] as $key => $m)
                    <span class="inline-flex items-center gap-1.5">
                        <span @class(['size-2.5 rounded-sm', 'bg-emerald-600' => $key === 'tunai', 'bg-sky-500' => $key !== 'tunai'])></span>
                        <span class="text-stone-700">{{ $m['label'] }}</span>
                        <strong class="tabular-nums">{{ $rp($m['total']) }}</strong>
                        <span class="text-stone-500">· {{ $m['count'] }} trx · {{ $m['share'] }}%</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
