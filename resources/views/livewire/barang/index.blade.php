@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Barang & Stok</h1>
            <p class="text-xs text-stone-500">Daftar barang koperasi beserta harga dan sisa stok.</p>
        </div>
        <button type="button" disabled title="Segera hadir"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            Tambah barang
        </button>
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        {{-- Ringkasan --}}
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <dt class="text-xs text-stone-500">Jenis barang</dt>
                <dd class="text-2xl font-bold tabular-nums">{{ $this->stats['total'] }}</dd>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <dt class="text-xs text-amber-800">Stok menipis</dt>
                <dd class="text-2xl font-bold text-amber-900 tabular-nums">{{ $this->stats['low'] }}</dd>
            </div>
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                <dt class="text-xs text-red-700">Stok habis</dt>
                <dd class="text-2xl font-bold text-red-800 tabular-nums">{{ $this->stats['out'] }}</dd>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <dt class="text-xs text-stone-500">Nilai stok (harga beli)</dt>
                <dd class="text-xl font-bold tabular-nums sm:text-2xl">{{ $rp($this->stats['stock_value']) }}</dd>
            </div>
        </dl>

        {{-- Filter --}}
        <div class="flex flex-col gap-2 sm:flex-row">
            <label class="relative flex-1">
                <span class="sr-only">Cari barang</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.200ms="search" placeholder="Cari nama atau kode…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="category" aria-label="Kategori"
                    class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                <option value="">Semua kategori</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
            <select wire:model.live="sort" aria-label="Urutkan"
                    class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                @foreach (\App\Livewire\Barang\Index::SORTS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if (count($this->products) === 0)
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                <p class="text-sm font-medium text-stone-600">Tidak ada barang yang cocok</p>
                <button type="button" wire:click="resetFilters" class="mt-2 text-sm font-medium text-emerald-700 hover:underline">Hapus filter</button>
            </div>
        @else
            {{-- Tabel (tablet & desktop) --}}
            <div class="hidden overflow-hidden rounded-xl border border-stone-200 bg-white md:block">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-medium text-stone-500">
                        <tr>
                            <th class="px-4 py-2.5">Kode</th>
                            <th class="px-4 py-2.5">Nama barang</th>
                            <th class="px-4 py-2.5">Kategori</th>
                            <th class="px-4 py-2.5 text-right">Harga beli</th>
                            <th class="px-4 py-2.5 text-right">Harga jual</th>
                            <th class="px-4 py-2.5 text-right">Stok</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($this->products as $p)
                            <tr wire:key="row-{{ $p['id'] }}" class="hover:bg-stone-50">
                                <td class="px-4 py-3 font-mono text-xs text-stone-500">{{ $p['code'] }}</td>
                                <td class="px-4 py-3 font-medium">{{ $p['name'] }}</td>
                                <td class="px-4 py-3 text-stone-600">{{ $p['category'] }}</td>
                                <td class="px-4 py-3 text-right text-stone-600 tabular-nums">{{ $rp($p['cost_price']) }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $rp($p['price']) }}</td>
                                <td class="px-4 py-3 text-right"><x-stock-badge :stock="$p['stock']" :min="$p['min_stock']" :unit="$p['unit']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Kartu (ponsel) --}}
            <ul class="space-y-2 md:hidden">
                @foreach ($this->products as $p)
                    <li wire:key="card-{{ $p['id'] }}" class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[11px] text-stone-400">{{ $p['code'] }} · {{ $p['category'] }}</p>
                                <p class="truncate font-medium">{{ $p['name'] }}</p>
                            </div>
                            <x-stock-badge :stock="$p['stock']" :min="$p['min_stock']" :unit="$p['unit']" class="shrink-0" />
                        </div>
                        <p class="mt-1 text-sm tabular-nums"><span class="font-semibold text-emerald-800">{{ $rp($p['price']) }}</span> <span class="text-xs text-stone-500">· beli {{ $rp($p['cost_price']) }}</span></p>
                    </li>
                @endforeach
            </ul>
            <p class="text-xs text-stone-500">Menampilkan {{ count($this->products) }} dari {{ $this->stats['total'] }} barang.</p>
        @endif
    </div>
</div>
