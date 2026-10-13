@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
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
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2" x-data="{ custom: @js($period === 'kustom') }">
            <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="group" aria-label="Periode laporan">
                @foreach (\App\Livewire\Laporan\Index::PERIODS as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" x-on:click="custom = false" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                    'bg-white text-stone-900 shadow-sm' => $period === $key,
                                    'text-stone-600 hover:text-stone-900' => $period !== $key])>{{ $label }}</button>
                @endforeach
                <button type="button" x-on:click="custom = true" aria-pressed="{{ $period === 'kustom' ? 'true' : 'false' }}"
                        @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => $period === 'kustom', 'text-stone-600 hover:text-stone-900' => $period !== 'kustom'])>Pilih tanggal</button>
            </div>
            <form x-show="custom" x-cloak wire:submit="applyCustom" class="flex flex-wrap items-start gap-2" aria-label="Rentang tanggal">
                <label class="text-xs text-stone-600"><span class="sr-only">Dari</span>
                    <input type="date" wire:model="from" max="{{ now()->toDateString() }}" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                    @error('from') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <span class="py-1.5 text-sm text-stone-500">–</span>
                <label class="text-xs text-stone-600"><span class="sr-only">Sampai</span>
                    <input type="date" wire:model="to" max="{{ now()->toDateString() }}" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                    @error('to') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">Terapkan</button>
            </form>
        </div>
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        @include('livewire.laporan.partials.today-card')

        @php
            $cmp = $this->comparison;
            $prev = $cmp['previous'];
            $ch = $cmp['changes'];
        @endphp
        <div class="flex flex-wrap items-baseline justify-between gap-2 pt-1">
            <h2 class="text-sm font-semibold text-stone-700">Rekap periode: {{ \App\Livewire\Laporan\Index::PERIODS[$period] ?? ($start->translatedFormat('d M').' – '.$end->translatedFormat('d M Y')) }}</h2>
            <p class="text-xs text-stone-500">Dibandingkan dengan {{ $cmp['label'] }} ({{ $cmp['range_label'] }})</p>
        </div>

        @if ($this->summary['count'] === 0)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-stone-300 bg-white px-4 py-4 text-sm" role="status" data-empty="periode">
                <p><span class="font-semibold text-stone-700">Belum ada penjualan pada periode ini.</span> <span class="text-stone-500">Angka di bawah bernilai nol; coba periode yang lebih panjang.</span></p>
                @if ($period !== '30-hari')<button type="button" wire:click="setPeriod('30-hari')" class="rounded-lg border border-stone-300 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-50">Lihat 30 hari</button>@endif
            </div>
        @endif

        {{-- Angka utama + perbandingan --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan penjualan">
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1">
                <p class="text-xs text-emerald-800">Total penjualan</p>
                <p class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($s['revenue']) }}</p>
                <x-laporan.delta :pct="$ch['revenue']" :previous="$rp($prev['revenue'])" />
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Laba kotor <span class="text-stone-400">· margin {{ $s['margin'] }}%</span></p>
                <p class="text-xl font-bold tabular-nums">{{ $rp($s['gross_profit']) }}</p>
                <x-laporan.delta :pct="$ch['gross_profit']" :previous="$rp($prev['gross_profit'])" />
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Transaksi <span class="text-stone-400">· rata-rata {{ $rp($s['average']) }}</span></p>
                <p class="text-xl font-bold tabular-nums">{{ $s['count'] }}</p>
                <x-laporan.delta :pct="$ch['count']" :previous="$prev['count']" />
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Barang terjual</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['items'] }}</p>
                <x-laporan.delta :pct="$ch['items']" :previous="$prev['items']" />
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

        {{-- Barang terjual (lengkap) --}}
        @php $sold = $this->soldProducts; @endphp
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="sold-title" x-data="{ open: false }">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 id="sold-title" class="text-sm font-semibold">Barang terjual <span class="font-normal text-stone-500">· {{ count($sold) }} jenis</span></h2>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-stone-500">Urutkan:</span>
                    @foreach (\App\Livewire\Laporan\Index::SOLD_SORTS as $key => $label)
                        <button type="button" wire:click="sortSold('{{ $key }}')" @class(['rounded-full px-2.5 py-1 font-medium', 'bg-emerald-900 text-white' => $soldSort === $key, 'border border-stone-300 text-stone-600' => $soldSort !== $key])>{{ $label }}</button>
                    @endforeach
                    <a href="{{ $this->transactionsUrl }}" wire:navigate class="ml-1 font-semibold text-emerald-800 hover:underline">Lihat transaksi periode ini →</a>
                </div>
            </div>
            @if ($sold)
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500"><tr><th class="px-4 py-2 font-medium">Barang</th><th class="px-3 py-2 text-right font-medium">Jumlah</th><th class="px-3 py-2 text-right font-medium">Omzet</th><th class="px-3 py-2 text-right font-medium">Laba</th><th class="px-4 py-2 text-right font-medium">Margin</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($sold as $i => $p)
                                <tr wire:key="sold-{{ md5($p['name']) }}" @if ($i >= 10) x-show="open" x-cloak @endif>
                                    <td class="px-4 py-2">{{ $p['name'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $p['quantity'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $rp($p['revenue']) }}</td>
                                    <td @class(['px-3 py-2 text-right tabular-nums', 'text-red-700' => $p['profit'] < 0])>{{ $rp($p['profit']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-stone-600">{{ $p['margin'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($sold) > 10)
                    <button type="button" x-on:click="open = !open" class="block w-full border-t border-stone-100 py-2 text-sm font-medium text-emerald-800 hover:bg-stone-50" x-text="open ? 'Tampilkan lebih sedikit' : 'Tampilkan semua ({{ count($sold) }})'"></button>
                @endif
            @else
                <p class="px-4 py-8 text-center text-sm text-stone-400">Belum ada penjualan pada periode ini.</p>
            @endif
        </section>

        @include('livewire.laporan.partials.discount-report')

        @include('livewire.laporan.partials.export-panel')
    </div>
</div>
