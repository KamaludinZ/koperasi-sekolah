@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    [$start, $end] = $this->range;
    $s = $this->summary;
    $maxQty = max(array_column($this->topProducts, 'quantity') ?: [1]);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Laporan & Rekap</h1>
            <p class="text-xs text-stone-500">
                {{ $start->isSameDay($end) ? $start->translatedFormat('l, d F Y') : $start->translatedFormat('d M').' – '.$end->translatedFormat('d M Y') }}
                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">data tiruan</span>
            </p>
        </div>
        <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="group" aria-label="Periode laporan">
            @foreach (\App\Livewire\Laporan\Index::PERIODS as $key => $label)
                <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                        @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                'bg-white text-stone-900 shadow-sm' => $period === $key,
                                'text-stone-600 hover:text-stone-900' => $period !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        @include('livewire.laporan.partials.today-card')

        <h2 class="pt-1 text-sm font-semibold text-stone-700">Rekap periode: {{ \App\Livewire\Laporan\Index::PERIODS[$period] }}</h2>

        {{-- Angka utama --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan penjualan">
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1">
                <p class="text-xs text-emerald-800">Total penjualan</p>
                <p class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($s['revenue']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Laba kotor</p>
                <p class="text-xl font-bold tabular-nums">{{ $rp($s['gross_profit']) }}</p>
                <p class="text-xs text-stone-500">margin {{ $s['margin'] }}%</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Transaksi</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['count'] }}</p>
                <p class="text-xs text-stone-500">rata-rata {{ $rp($s['average']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Barang terjual</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['items'] }}</p>
            </div>
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            @include('livewire.laporan.partials.sales-chart')

            {{-- Barang terlaris --}}
            <section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="top-title">
                <h2 id="top-title" class="text-sm font-semibold">Barang terlaris</h2>
                @if (count($this->topProducts) === 0)
                    <p class="mt-6 text-center text-sm text-stone-400">Belum ada penjualan pada periode ini.</p>
                @else
                    <ol class="mt-3 space-y-3">
                        @foreach ($this->topProducts as $p)
                            <li>
                                <div class="flex items-baseline justify-between gap-2 text-sm">
                                    <span class="truncate"><span class="mr-1 text-stone-400 tabular-nums">{{ $loop->iteration }}.</span>{{ $p['name'] }}</span>
                                    <span class="shrink-0 font-semibold tabular-nums">{{ $p['quantity'] }}</span>
                                </div>
                                <div class="mt-1 h-1.5 rounded-full bg-stone-100">
                                    <div class="h-full rounded-full bg-emerald-600" style="width: {{ round($p['quantity'] / $maxQty * 100) }}%"></div>
                                </div>
                                <p class="mt-0.5 text-[11px] text-stone-500 tabular-nums">{{ $rp($p['revenue']) }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        {{-- Export (diisi pada task export laporan) --}}
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-4">
            <div>
                <h2 class="text-sm font-semibold">Export laporan</h2>
                <p class="text-xs text-stone-500">Unduh rekap penjualan periode ini untuk dilaporkan ke sekolah.</p>
            </div>
            <button type="button" disabled title="Segera hadir"
                    class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-500 disabled:cursor-not-allowed">Unduh rekap</button>
        </section>
    </div>
</div>
