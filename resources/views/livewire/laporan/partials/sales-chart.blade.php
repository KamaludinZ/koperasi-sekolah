{{-- Grafik penjualan (satu seri, batang) dengan pemilih periode sendiri + tabel data --}}
@php
    $points = $this->chartPoints;
    $total = array_sum(array_column($points, 'value'));
    $best = collect($points)->sortByDesc('value')->first();
    $unit = $chartPeriod === '12-minggu' ? 'minggu' : 'hari';
@endphp
<section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="chart-title">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="chart-title" class="text-sm font-semibold">Penjualan per {{ $unit }}</h2>
            <p class="text-xs text-stone-500">
                Total {{ $rp($total) }}
                @if ($best && $best['value'] > 0) · tertinggi {{ $rp($best['value']) }} ({{ $best['label'] }}) @endif
            </p>
        </div>
        <div class="flex gap-1 rounded-lg bg-stone-100 p-0.5" role="group" aria-label="Periode grafik">
            @foreach (\App\Livewire\Laporan\Index::CHART_PERIODS as $key => $label)
                <button type="button" wire:click="setChartPeriod('{{ $key }}')" aria-pressed="{{ $chartPeriod === $key ? 'true' : 'false' }}"
                        @class(['rounded-md px-2.5 py-1 text-xs font-medium',
                                'bg-white text-stone-900 shadow-sm' => $chartPeriod === $key,
                                'text-stone-600 hover:text-stone-900' => $chartPeriod !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="relative mt-4 h-60" wire:key="chart-{{ $chartPeriod }}" wire:ignore
         x-data="salesChart(@js($points))">
        @if ($total === 0)
            <p class="pointer-events-none absolute inset-0 z-10 grid place-items-center text-sm text-stone-400" data-empty="grafik">Belum ada penjualan pada rentang grafik ini.</p>
        @endif
        <canvas x-ref="canvas" role="img"
                aria-label="Grafik batang penjualan per {{ $unit }}, {{ count($points) }} {{ $unit }}, total {{ $rp($total) }}. Rincian ada di tabel data."></canvas>
    </div>

    <details class="mt-3 text-sm">
        <summary class="cursor-pointer text-xs font-medium text-stone-500 hover:text-stone-800">Lihat tabel data</summary>
        <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-stone-200">
            <table class="w-full text-xs">
                <thead class="sticky top-0 bg-stone-50 text-left text-stone-500">
                    <tr><th class="px-3 py-1.5">{{ ucfirst($unit) }}</th><th class="px-3 py-1.5 text-right">Transaksi</th><th class="px-3 py-1.5 text-right">Penjualan</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($points as $p)
                        <tr><td class="px-3 py-1.5">{{ $p['tooltip'] }}</td><td class="px-3 py-1.5 text-right tabular-nums">{{ $p['count'] }}</td><td class="px-3 py-1.5 text-right tabular-nums">{{ $rp($p['value']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</section>
