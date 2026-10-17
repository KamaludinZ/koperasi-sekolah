@use('App\Livewire\Barang\Restock')
@use('App\Services\RestockAdvisor')
@php $t = $this->totals; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Barang & Stok</h1>
                <p class="text-xs text-stone-500">Saran jumlah pesan dari kecepatan penjualan {{ RestockAdvisor::WINDOW_DAYS }} hari terakhir.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('kelola-katalog')
                <a href="{{ route('barang') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Atur batas minimum</a>
                @endcan
                @if (auth()->user()->can('kelola-pengadaan'))
                    <a href="{{ route('pengadaan.pesanan.buat') }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Buat pesanan pembelian</a>
                @endif
            </div>
        </div>
        <x-barang.nav active="barang.saran-restok" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $t['out'], 'border-stone-200 bg-white' => ! $t['out']])><p class="text-xs text-stone-600">Stok habis</p><p class="text-2xl font-bold tabular-nums">{{ $t['out'] }}</p></div>
            <div @class(['rounded-xl border px-4 py-3', 'border-amber-200 bg-amber-50' => $t['urgent'], 'border-stone-200 bg-white' => ! $t['urgent']])><p class="text-xs text-stone-600">Segera pesan</p><p class="text-2xl font-bold tabular-nums">{{ $t['urgent'] }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Total saran</p><p class="text-2xl font-bold tabular-nums">{{ number_format($t['units'], 0, ',', '.') }} <span class="text-sm font-normal text-stone-500">unit</span></p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Perkiraan biaya</p><p class="text-xl font-bold tabular-nums">@rupiah($t['cost'])</p></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-stone-600">Cukup untuk</span>
            @foreach (RestockAdvisor::HORIZONS as $days => $label)
                <button type="button" wire:click="setHorizon({{ $days }})" aria-pressed="{{ $horizon === $days ? 'true' : 'false' }}"
                        @class(['rounded-full px-3 py-1.5 text-sm font-medium', 'bg-emerald-900 text-white' => $horizon === $days, 'border border-stone-300 bg-white text-stone-700' => $horizon !== $days])>{{ $label }}</button>
            @endforeach
            <label class="ml-auto flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="showAll" class="accent-emerald-700"> Tampilkan juga yang aman</label>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Saran restok">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Barang</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 text-right font-medium">Stok / min.</th><th class="px-3 py-2 text-right font-medium">Terjual 30 hr</th><th class="px-3 py-2 text-right font-medium">Habis dalam</th><th class="px-3 py-2 text-right font-medium">Saran pesan</th><th class="px-4 py-2 text-right font-medium">Biaya</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->rows as $r)
                            @php [$label, $tone] = Restock::LEVELS[$r['level']]; @endphp
                            <tr wire:key="rs-{{ $r['id'] }}">
                                <td class="px-4 py-2.5"><span class="font-mono text-xs">{{ $r['code'] }}</span> {{ $r['name'] }}<span class="block text-xs text-stone-500">{{ $r['category'] ?? '—' }}</span></td>
                                <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tone }}">{{ $label }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $r['stock'] }} <span class="text-stone-400">/ {{ $r['min_stock'] }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $r['sold'] }}<span class="block text-[11px] text-stone-500">{{ str_replace('.', ',', (string) $r['per_day']) }}/hari</span></td>
                                <td @class(['px-3 py-2.5 text-right tabular-nums', 'font-semibold text-red-700' => $r['days_left'] !== null && $r['days_left'] < RestockAdvisor::URGENT_DAYS])>{{ $r['days_left'] === null ? '—' : ($r['days_left'] === 0 ? 'hari ini' : $r['days_left'].' hari') }}</td>
                                <td class="px-3 py-2.5 text-right"><span class="font-semibold tabular-nums">{{ $r['suggested'] }} {{ $r['unit'] }}</span><span class="block text-[11px] text-stone-500">{{ $r['reason'] }}</span></td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-stone-600">@rupiah($r['suggested'] * $r['cost'])</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-stone-400">Semua stok aman untuk {{ RestockAdvisor::HORIZONS[$horizon] }} ke depan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
