<div>
    @if ($open)
        @php $input = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15'; @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="min-stock-title"
             x-data x-on:keydown.escape.window="$wire.close()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="close"></div>

            <form wire:submit="save" class="relative flex max-h-[90dvh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-stone-200 px-5 py-4">
                    <div>
                        <h2 id="min-stock-title" class="font-semibold">Atur batas minimum stok</h2>
                        <p class="text-xs text-stone-500">Barang ditandai menipis bila stoknya sama dengan atau di bawah batas.</p>
                    </div>
                    <button type="button" wire:click="close" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                <div class="space-y-3 border-b border-stone-200 bg-stone-50 px-5 py-4">
                    {{-- Terapkan sekaligus --}}
                    <div>
                        <p class="mb-1.5 text-xs font-medium text-stone-600">Terapkan sekaligus</p>
                        <div class="flex flex-wrap items-start gap-2">
                            <select wire:model="bulkCategory" aria-label="Kategori yang diubah" class="{{ $input }}">
                                <option value="">Semua barang</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat }}">Kategori {{ $cat }}</option>
                                @endforeach
                            </select>
                            <div>
                                <input type="number" min="0" inputmode="numeric" wire:model="bulkValue" placeholder="Batas" aria-label="Batas minimum"
                                       wire:keydown.enter.prevent="applyBulk" class="{{ $input }} w-24 tabular-nums">
                                @error('bulkValue') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                            <button type="button" wire:click="applyBulk" class="rounded-lg border border-emerald-700 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-50">Terapkan</button>
                        </div>
                    </div>

                    <label class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-stone-700">Batas bawaan untuk barang baru</span>
                        <input type="number" min="0" inputmode="numeric" wire:model="defaultMinStock" class="{{ $input }} w-20 py-1.5 tabular-nums">
                        @error('defaultMinStock') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="px-5 pt-3">
                    <input type="search" wire:model.live.debounce.200ms="filter" placeholder="Cari barang…" aria-label="Cari barang"
                           class="{{ $input }} w-full">
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-3">
                    @forelse ($this->rows as $category => $items)
                        <h3 class="sticky top-0 bg-white py-1.5 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $category }}</h3>
                        <ul class="mb-3 divide-y divide-stone-100">
                            @foreach ($items as $p)
                                @php
                                    $min = $minStocks[$p['id']] ?? $p['min_stock'];
                                    $isLow = is_numeric($min) && $p['stock'] <= (int) $min;
                                @endphp
                                <li wire:key="min-{{ $p['id'] }}" class="flex items-center gap-3 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ $p['name'] }}</p>
                                        <p class="text-xs text-stone-500 tabular-nums">
                                            Stok sekarang {{ $p['stock'] }} {{ $p['unit'] }}
                                            @if ($isLow)<span class="ml-1 font-medium text-amber-700">· akan ditandai menipis</span>@endif
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <input type="number" min="0" inputmode="numeric" wire:model.live.debounce.300ms="minStocks.{{ $p['id'] }}"
                                               aria-label="Batas minimum {{ $p['name'] }}" class="{{ $input }} w-20 py-1.5 text-right tabular-nums">
                                        @error('minStocks.'.$p['id']) <span class="mt-0.5 block text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @empty
                        <p class="py-8 text-center text-sm text-stone-500">Tidak ada barang yang cocok.</p>
                    @endforelse
                </div>

                <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                    <button type="button" wire:click="close" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan batas minimum</button>
                </div>
            </form>
        </div>
    @endif
</div>
