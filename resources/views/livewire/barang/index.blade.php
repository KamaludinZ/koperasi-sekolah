@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); $canManage = Gate::allows('kelola-katalog'); @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Barang & Stok</h1>
                <p class="text-xs text-stone-500">Daftar barang koperasi beserta harga dan sisa stok.</p>
            </div>
            @can('kelola-katalog')
            <div class="flex gap-2">
            <button type="button" x-on:click="$dispatch('open-min-stock-settings')"
                    class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 21v-7m0-4V3m8 18v-9m0-4V3m8 18v-5m0-4V3M1 14h6m2-6h6m2 8h6"/></svg>
                <span class="hidden sm:inline">Atur batas minimum</span><span class="sm:hidden">Batas stok</span>
            </button>
            <button type="button" wire:click="create"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                Tambah barang
            </button>
            </div>
            @endcan
        </div>
        <x-barang.nav active="barang" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        @if ($flash)
            <p class="rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900" role="status">{{ $flash }}</p>
        @endif

        @include('livewire.barang.partials.low-stock-panel')

        {{-- Ringkasan (kartu habis & nonaktif bisa diklik untuk memfilter) --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Jenis barang</p>
                <p class="text-2xl font-bold tabular-nums">{{ $this->stats['total'] }}</p>
            </div>
            <button type="button" wire:click="toggleStatus('menipis')" aria-pressed="{{ $status === 'menipis' ? 'true' : 'false' }}"
                    @class(['rounded-xl border px-4 py-3 text-left transition',
                            'border-amber-200 bg-amber-50 hover:border-amber-400' => $status !== 'menipis',
                            'border-amber-600 bg-amber-100 ring-2 ring-amber-600/20' => $status === 'menipis'])>
                <p class="text-xs text-amber-800">Stok menipis</p>
                <p class="text-2xl font-bold text-amber-900 tabular-nums">{{ $this->stats['low'] }}</p>
            </button>
            <button type="button" wire:click="toggleStatus('habis')" aria-pressed="{{ $status === 'habis' ? 'true' : 'false' }}"
                    @class(['rounded-xl border px-4 py-3 text-left transition',
                            'border-red-200 bg-red-50 hover:border-red-400' => $status !== 'habis',
                            'border-red-600 bg-red-100 ring-2 ring-red-600/20' => $status === 'habis'])>
                <p class="text-xs text-red-700">Stok habis</p>
                <p class="text-2xl font-bold text-red-800 tabular-nums">{{ $this->stats['out'] }}</p>
            </button>
            <button type="button" wire:click="toggleStatus('nonaktif')" aria-pressed="{{ $status === 'nonaktif' ? 'true' : 'false' }}"
                    @class(['rounded-xl border px-4 py-3 text-left transition',
                            'border-stone-200 bg-stone-50 hover:border-stone-400' => $status !== 'nonaktif',
                            'border-stone-600 bg-stone-200 ring-2 ring-stone-600/20' => $status === 'nonaktif'])>
                <p class="text-xs text-stone-600">Nonaktif</p>
                <p class="text-2xl font-bold text-stone-700 tabular-nums">{{ $this->stats['inactive'] }}</p>
            </button>
            <div class="col-span-2 rounded-xl border border-stone-200 bg-white px-4 py-3 lg:col-span-1">
                <p class="text-xs text-stone-500">Nilai stok (harga beli)</p>
                <p class="text-xl font-bold tabular-nums sm:text-2xl">{{ $rp($this->stats['stock_value']) }}</p>
            </div>
        </div>

        {{-- Filter --}}
        <div class="flex flex-col gap-2 sm:flex-row">
            <label class="relative flex-1">
                <span class="sr-only">Cari barang</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.200ms="search" placeholder="Cari nama atau kode…  ( / )"
                       x-on:keydown.window="if ($event.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) { $event.preventDefault(); $el.focus() }"
                       x-on:keydown.escape="$wire.set('search', '')"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="category" aria-label="Kategori"
                    class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                <option value="">Semua kategori</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" aria-label="Status"
                    class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                @foreach (\App\Livewire\Barang\Index::STATUSES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="sort" aria-label="Urutkan"
                    class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                @foreach (\App\Livewire\Barang\Index::SORTS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if ($this->paginated->total() === 0)
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
                            <th class="px-4 py-2.5">Kode / barcode</th>
                            <th class="px-4 py-2.5">Nama barang</th>
                            <th class="px-4 py-2.5">Kategori</th>
                            <th class="px-4 py-2.5 text-right">Harga beli</th>
                            <th class="px-4 py-2.5 text-right">Harga jual</th>
                            <th class="px-4 py-2.5 text-right">Stok</th>
                            <th class="px-4 py-2.5"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($this->paginated as $p)
                            <tr wire:key="row-{{ $p['id'] }}"
                                @class(['hover:bg-stone-50',
                                        'bg-red-50/50 shadow-[inset_3px_0_0_var(--color-red-500)]' => $p['stock'] <= 0 && $p['is_active'],
                                        'bg-stone-50 text-stone-400 shadow-[inset_3px_0_0_var(--color-stone-300)]' => ! $p['is_active']])>
                                <td class="px-4 py-3 font-mono text-xs text-stone-500">
                                    {{ $p['code'] }}
                                    @if (($bc = \App\Support\BarcodeImage::value($p)) !== $p['code'])<span class="block text-[11px] text-stone-400" title="Barcode">▮▯ {{ $bc }}</span>@endif
                                </td>
                                <td @class(['px-4 py-3 font-medium', 'line-through decoration-stone-300' => ! $p['is_active']])>
                                    <button type="button" wire:click="show({{ $p['id'] }})" class="text-left hover:text-emerald-800 hover:underline">{{ $p['name'] }}</button>
                                    @unless ($p['is_active'])<span class="ml-1 inline-block rounded bg-stone-200 px-1.5 py-0.5 text-[11px] font-medium text-stone-600 no-underline">Nonaktif · tidak dijual</span>@endunless
                                </td>
                                <td class="px-4 py-3 text-stone-600">{{ $p['category'] }}</td>
                                <td class="px-4 py-3 text-right text-stone-600 tabular-nums">{{ $rp($p['cost_price']) }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $rp($p['price']) }}</td>
                                <td class="px-4 py-3 text-right"><x-stock-badge :stock="$p['stock']" :min="$p['min_stock']" :unit="$p['unit']" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('barang.riwayat-stok', ['barang' => $p['id'], 'periode' => 'semua']) }}" wire:navigate class="mr-3 text-sm font-medium text-stone-600 hover:underline">Riwayat</a>
                                    @can('kelola-katalog')<button type="button" wire:click="edit({{ $p['id'] }})" class="text-sm font-medium text-emerald-700 hover:underline">Ubah</button>@endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Kartu (ponsel) --}}
            <ul class="space-y-2 md:hidden">
                @foreach ($this->paginated as $p)
                    <li wire:key="card-{{ $p['id'] }}" wire:click="{{ $canManage ? 'edit' : 'show' }}({{ $p['id'] }})"
                        @class(['cursor-pointer rounded-xl border px-4 py-3 active:bg-stone-50',
                                'border-stone-200 bg-white' => $p['is_active'] && $p['stock'] > 0,
                                'border-red-200 bg-red-50/50' => $p['is_active'] && $p['stock'] <= 0,
                                'border-dashed border-stone-300 bg-stone-50 text-stone-500' => ! $p['is_active']])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[11px] text-stone-400">{{ $p['code'] }} · {{ $p['category'] }}</p>
                                <p class="truncate font-medium">{{ $p['name'] }} @unless ($p['is_active'])<span class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[11px] font-medium text-stone-600">Nonaktif</span>@endunless</p>
                            </div>
                            <x-stock-badge :stock="$p['stock']" :min="$p['min_stock']" :unit="$p['unit']" class="shrink-0" />
                        </div>
                        <p class="mt-1 text-sm tabular-nums"><span class="font-semibold text-emerald-800">{{ $rp($p['price']) }}</span> <span class="text-xs text-stone-500">· beli {{ $rp($p['cost_price']) }}</span></p>
                    </li>
                @endforeach
            </ul>
            <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-stone-500">
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-500"></span>Stok habis — tidak bisa dipilih di kasir</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-stone-300"></span>Nonaktif — disembunyikan dari kasir</span>
            </p>
            @include('livewire.barang.partials.pagination', ['paginator' => $this->paginated])
        @endif
    </div>

    @if ($d = $this->detail)
        <div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="product-detail-title"
             x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-stone-500">{{ $d['code'] }} · {{ $d['category'] ?? 'Tanpa kategori' }}</p>
                        <h2 id="product-detail-title" class="font-semibold">{{ $d['name'] }}
                            @unless ($d['is_active'])<span class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[11px] font-medium text-stone-600">Nonaktif</span>@endunless</h2>
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                    <section aria-label="Barcode" class="rounded-xl border border-stone-200 p-4 text-center">
                        <x-barcode :value="$d['barcode']" class="w-full max-w-64" />
                        <p class="mt-2 text-xs text-stone-500">CODE128 · {{ $d['barcode'] === $d['code'] ? 'mengikuti kode barang' : 'barcode khusus' }}</p>
                    </section>
                    <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2">
                        <dt class="text-stone-500">Harga jual</dt><dd class="font-semibold tabular-nums">{{ $rp($d['price']) }}</dd>
                        <dt class="text-stone-500">Harga beli</dt><dd class="tabular-nums">{{ $rp($d['cost_price']) }}</dd>
                        <dt class="text-stone-500">Stok</dt><dd><x-stock-badge :stock="$d['stock']" :min="$d['min_stock']" :unit="$d['unit']" /> <span class="text-xs text-stone-500">min. {{ $d['min_stock'] }}</span></dd>
                        <dt class="text-stone-500">Terakhir diubah</dt><dd>{{ $d['updated_at'] ? \Illuminate\Support\Carbon::parse($d['updated_at'])->translatedFormat('d M Y H:i') : '—' }}</dd>
                    </dl>
                </div>
                <div class="flex flex-wrap gap-2 border-t border-stone-200 px-5 py-3">
                    <a href="{{ route('barang.riwayat-stok', ['barang' => $d['id'], 'periode' => 'semua']) }}" wire:navigate class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Riwayat stok</a>
                    <a href="{{ route('barang.label', ['barang' => $d['id']]) }}" wire:navigate class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Cetak label</a>
                    @can('kelola-katalog')
                    <button type="button" wire:click="edit({{ $d['id'] }})" class="ml-auto rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Ubah barang</button>
                    @endcan
                </div>
            </div>
        </div>
    @endif

    @include('livewire.barang.partials.product-form')
    @can('kelola-katalog')
        <livewire:barang.min-stock-settings />
    @endcan
</div>
