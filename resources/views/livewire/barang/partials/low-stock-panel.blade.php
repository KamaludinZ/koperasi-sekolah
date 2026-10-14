{{-- Panel peringatan: barang aktif yang habis / menipis, paling kritis di atas. --}}
@if (count($this->lowStock) > 0)
    @php $shown = 5; @endphp
    <section class="rounded-xl border border-amber-300 bg-amber-50" aria-labelledby="low-stock-title"
             x-data="{ open: $persist(true).as('low-stock-open'), all: false }">
        <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open"
                class="flex w-full items-center gap-3 px-4 py-3 text-left">
            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-amber-400 text-amber-950">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
            </span>
            <span class="min-w-0 flex-1">
                <span id="low-stock-title" class="block text-sm font-semibold text-amber-950">{{ count($this->lowStock) }} barang perlu segera ditambah stoknya</span>
                <span class="block text-xs text-amber-800">Stok sudah habis atau sama/di bawah batas minimum.</span>
            </span>
            <svg class="size-5 shrink-0 text-amber-800 transition" x-bind:class="open || 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg>
        </button>

        <div x-show="open" x-collapse.duration.150ms class="border-t border-amber-200">
            <ul class="divide-y divide-amber-200/70">
                @foreach ($this->lowStock as $p)
                    @php $pct = $p['min_stock'] > 0 ? min(100, (int) round($p['stock'] / $p['min_stock'] * 100)) : 0; @endphp
                    <li wire:key="low-{{ $p['id'] }}" class="flex items-center gap-3 px-4 py-2.5"
                        @if ($loop->index >= $shown) x-show="all" x-cloak @endif>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-stone-900">{{ $p['name'] }} <span class="font-mono text-[11px] font-normal text-stone-500">{{ $p['code'] }}</span></p>
                            <div class="mt-1 flex items-center gap-2">
                                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-amber-200" role="meter" aria-valuemin="0" aria-valuemax="{{ $p['min_stock'] }}" aria-valuenow="{{ $p['stock'] }}" aria-label="Sisa stok {{ $p['name'] }}">
                                    <div @class(['h-full rounded-full', 'bg-red-500' => $p['stock'] <= 0, 'bg-amber-500' => $p['stock'] > 0]) style="width: {{ $pct }}%"></div>
                                </div>
                                <span @class(['text-xs tabular-nums', 'font-semibold text-red-700' => $p['stock'] <= 0, 'text-amber-900' => $p['stock'] > 0])>
                                    {{ $p['stock'] <= 0 ? 'Habis' : 'Sisa '.$p['stock'] }} / batas {{ $p['min_stock'] }} {{ $p['unit'] }}
                                </span>
                            </div>
                        </div>
                        <span class="hidden text-right text-xs text-stone-600 sm:block">Saran restok<br><strong class="text-sm text-stone-900 tabular-nums">+{{ $p['suggested'] }} {{ $p['unit'] }}</strong></span>
                        <button type="button" wire:click="edit({{ $p['id'] }})"
                                class="shrink-0 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-950 hover:bg-amber-100">Tambah stok</button>
                    </li>
                @endforeach
            </ul>
@can('kelola-katalog')
            <p class="border-t border-amber-200 px-4 py-2 text-xs text-amber-900">
                Batas terlalu tinggi/rendah?
                <button type="button" x-on:click="$dispatch('open-min-stock-settings')" class="font-semibold underline hover:no-underline">Atur batas minimum</button>
            </p>
            @endcan
            @if (count($this->lowStock) > $shown)
                <button type="button" x-on:click="all = !all"
                        class="w-full border-t border-amber-200 px-4 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                        x-text="all ? 'Tampilkan lebih sedikit' : 'Lihat {{ count($this->lowStock) - $shown }} lainnya'"></button>
            @endif
        </div>
    </section>
@endif
