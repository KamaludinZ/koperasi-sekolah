@use('App\Livewire\Barang\Barcodes')
@php
    $s = $this->stats;
    $pageIds = collect($this->products->items())->pluck('id')->all();
    $allOnPage = $pageIds && count(array_diff($pageIds, $selected)) === 0;
    $n = count($selected);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Barang & Stok</h1>
                <p class="text-xs text-stone-500">Barcode tiap barang (CODE128). Barang tanpa barcode khusus memakai kode barangnya.</p>
            </div>
            <a href="{{ route('barang.label') }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Cetak label</a>
        </div>
        <x-barang.nav active="barang.barcode" />
    </header>

    <div class="space-y-4 px-4 py-5 pb-28 sm:px-6">
        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Semua barang</p><p class="text-2xl font-bold tabular-nums">{{ $s['total'] }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Mengikuti kode</p><p class="text-2xl font-bold tabular-nums">{{ $s['total'] - $s['custom'] }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Barcode khusus</p><p class="text-2xl font-bold tabular-nums">{{ $s['custom'] }}</p></div>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row">
            <label class="relative flex-1">
                <span class="sr-only">Cari barang</span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama, kode, atau barcode…"
                       class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="category" aria-label="Kategori" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Semua kategori</option>
                @foreach ($this->categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
            </select>
            <select wire:model.live="status" aria-label="Status barcode" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                @foreach (Barcodes::STATUSES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar barcode barang">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="w-10 px-4 py-2">
                                <input type="checkbox" wire:click="togglePage" @checked($allOnPage) aria-label="Pilih semua di halaman ini" class="accent-emerald-700">
                            </th>
                            <th class="px-3 py-2 font-medium">Barang</th>
                            <th class="px-3 py-2 font-medium">Kategori</th>
                            <th class="px-3 py-2 font-medium">Barcode</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->products as $p)
                            <tr wire:key="bc-{{ $p['id'] }}" @class(['hover:bg-stone-50', 'bg-emerald-50/50' => in_array($p['id'], $selected, true), 'text-stone-400' => ! $p['is_active']])>
                                <td class="px-4 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $p['id'] }}" aria-label="Pilih {{ $p['name'] }}" class="accent-emerald-700"></td>
                                <td class="px-3 py-2"><span class="font-mono text-xs text-stone-500">{{ $p['code'] }}</span> {{ $p['name'] }}@unless ($p['is_active']) <span class="text-xs">(nonaktif)</span>@endunless</td>
                                <td class="px-3 py-2 text-stone-600">{{ $p['category'] ?? '—' }}</td>
                                <td class="px-3 py-2"><x-barcode :value="$p['barcode']" :height="28" class="w-36" /></td>
                                <td class="px-4 py-2">
                                    <span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap', 'bg-sky-100 text-sky-800' => $p['custom'], 'bg-stone-100 text-stone-600' => ! $p['custom']])>{{ $p['custom'] ? 'Barcode khusus' : 'Mengikuti kode' }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400">Tidak ada barang yang cocok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if ($this->products->total() > 0)
            @include('livewire.barang.partials.pagination', ['paginator' => $this->products])
        @endif
    </div>

    {{-- Bilah aksi untuk barang terpilih --}}
    @if ($n)
        <div class="sticky bottom-0 z-10 border-t border-stone-200 bg-white/95 px-4 py-3 shadow-[0_-4px_12px_rgb(0_0_0/0.05)] backdrop-blur sm:px-6" role="region" aria-label="Aksi barang terpilih">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <p class="text-sm"><strong class="tabular-nums">{{ $n }}</strong> barang dipilih
                    <button type="button" wire:click="selectAllMatching" class="ml-2 text-xs font-medium text-emerald-800 hover:underline">Pilih semua yang cocok ({{ $this->products->total() }})</button>
                    <button type="button" wire:click="clearSelection" class="ml-2 text-xs font-medium text-stone-500 hover:underline">Batal pilih</button>
                </p>
                <a href="{{ route('barang.label', ['barang' => implode(',', array_slice($selected, 0, 200))]) }}" wire:navigate data-label-link
                   class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100">Cetak label</a>
                @if ($this->canManage)
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-1.5 text-xs text-stone-600"><input type="checkbox" wire:model.live="overwrite" class="accent-emerald-700"> Timpa barcode khusus</label>
                        <button type="button" wire:click="resetToCode" wire:confirm="Kembalikan {{ $n }} barang ke barcode kode barang? Label lama yang sudah ditempel tidak terbaca lagi."
                                class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Pakai kode barang</button>
                        <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate"
                                class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Buat nomor barcode baru</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
