{{-- Kartu ringkasan penjualan hari ini --}}
@php
    $t = $this->today;
    $peak = max($t['hourly'] ?: [0]) ?: 1;
@endphp
<section class="relative isolate overflow-hidden rounded-2xl bg-emerald-900 text-white shadow-sm" aria-labelledby="today-title">
    <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.06]" aria-hidden="true"></div>
    <div class="grid gap-5 p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] md:items-end">
        <div>
            <h2 id="today-title" class="text-sm text-emerald-200">Penjualan hari ini · {{ now()->translatedFormat('l, d F') }}</h2>
            <p class="mt-1 text-4xl font-bold tracking-tight tabular-nums">{{ $rp($t['revenue']) }}</p>
            <p class="mt-1 text-sm">
                @if ($t['change'] !== null)
                    <span @class(['font-semibold', 'text-emerald-300' => $t['change'] >= 0, 'text-red-300' => $t['change'] < 0])>
                        {{ $t['change'] > 0 ? '▲' : ($t['change'] < 0 ? '▼' : '') }} {{ abs($t['change']) }}%
                    </span>
                    <span class="text-emerald-200">vs kemarin jam segini ({{ $rp($t['compare']) }})</span>
                @else
                    <span class="text-emerald-200">Kemarin belum ada penjualan di jam yang sama.</span>
                @endif
            </p>

            <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                <div>
                    <dt class="text-xs text-emerald-200">Transaksi</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ $t['count'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-emerald-200">Barang terjual</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ $t['items'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-emerald-200">Terakhir</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ $t['last_at'] ? substr($t['last_at'], 11, 5) : '—' }}</dd>
                </div>
            </dl>
            @if ($t['best'])
                <p class="mt-3 text-xs text-emerald-200">Terlaris: <strong class="text-white">{{ $t['best']['name'] }}</strong> ({{ $t['best']['quantity'] }} terjual)</p>
            @endif
        </div>

        {{-- Grafik mini per jam --}}
        <div>
            <div class="flex h-24 items-end gap-1.5" role="img"
                 aria-label="Penjualan per jam hari ini: @foreach ($t['hourly'] as $h => $v){{ sprintf('%02d', $h) }}.00 {{ $rp($v) }}{{ $loop->last ? '' : ', ' }}@endforeach">
                @foreach ($t['hourly'] as $h => $v)
                    <div class="flex h-full flex-1 flex-col justify-end" title="{{ sprintf('%02d', $h) }}.00 · {{ $rp($v) }}">
                        <div @class(['rounded-t',
                                     'bg-amber-400' => $h === $t['current_hour'],
                                     'bg-emerald-400' => $h !== $t['current_hour'] && $v > 0,
                                     'bg-emerald-800' => $v === 0])
                             style="height: {{ $v > 0 ? max(6, round($v / $peak * 100)) : 4 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex gap-1.5 text-[10px] text-emerald-300 tabular-nums" aria-hidden="true">
                @foreach ($t['hourly'] as $h => $v)
                    <span class="flex-1 text-center">{{ $h }}</span>
                @endforeach
            </div>
        </div>
    </div>
</section>
