@use('App\Livewire\Promo\Index')
@use('App\Models\Promo')
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $input = 'w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4';
    $cls = fn ($f) => $input.' '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
    $err = fn ($f) => $errors->first($f);
    $chip = 'cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white';
    $hints = [
        'persen' => 'Harga tiap barang yang dicakup dipotong sekian persen.',
        'nominal' => 'Harga tiap barang yang dicakup dipotong sekian rupiah per pcs.',
        'beli_gratis' => 'Setiap kelipatan (beli + gratis) pcs, pcs gratisnya tidak dibayar.',
        'grosir' => 'Mulai jumlah minimal, harga per pcs menjadi harga grosir.',
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <p class="text-xs text-stone-500"><a href="{{ route('promo') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Diskon & Promo</a> / {{ $promoId ? 'Ubah promo' : 'Promo baru' }}</p>
        <h1 class="text-lg font-semibold">{{ $promoId ? 'Ubah promo' : 'Promo baru' }}</h1>
        <x-promo.nav active="promo" />
    </header>

    <form wire:submit="save" class="max-w-3xl space-y-5 px-4 py-6 sm:px-6" novalidate>
        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pf-rule">
            <h2 id="pf-rule" class="font-semibold">Aturan potongan</h2>
            <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Nama promo</span>
                <input type="text" wire:model="name" value="{{ $name }}" maxlength="100" placeholder="mis. Diskon Alat Tulis Awal Semester" class="{{ $cls('name') }}">
                @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-stone-700">Jenis promo</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (Promo::TYPES as $key => $label)
                        <label class="{{ $chip }}"><input type="radio" wire:model.live="type" value="{{ $key }}" class="sr-only" @checked($type === $key)> {{ $label }}</label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-stone-500">{{ $hints[$type] ?? '' }}</p>
            </fieldset>
            <div class="grid gap-4 sm:grid-cols-3">
                @if ($type === 'beli_gratis')
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Beli</span>
                        <input type="number" wire:model="buy_qty" value="{{ $buy_qty }}" min="1" max="100" class="{{ $cls('buy_qty') }} tabular-nums">
                        @error('buy_qty') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Gratis</span>
                        <input type="number" wire:model="free_qty" value="{{ $free_qty }}" min="1" max="100" class="{{ $cls('free_qty') }} tabular-nums">
                        @error('free_qty') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @else
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">{{ ['persen' => 'Potongan (%)', 'nominal' => 'Potongan per pcs (Rp)', 'grosir' => 'Harga grosir per pcs (Rp)'][$type] }}</span>
                        <input type="number" wire:model="value" value="{{ $value }}" min="1" max="{{ $type === 'persen' ? 100 : 10000000 }}" step="{{ $type === 'persen' ? 1 : 100 }}" class="{{ $cls('value') }} tabular-nums">
                        @error('value') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Minimal jumlah (pcs)</span>
                        <input type="number" wire:model="min_qty" value="{{ $min_qty }}" min="{{ $type === 'grosir' ? 2 : 1 }}" max="1000" class="{{ $cls('min_qty') }} tabular-nums">
                        @error('min_qty') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @else <span class="mt-1 block text-xs text-stone-500">Per barang dalam satu transaksi.</span> @enderror
                    </label>
                @endif
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Minimal belanja (Rp)</span>
                    <input type="number" wire:model="min_purchase" value="{{ $min_purchase }}" min="0" step="1000" class="{{ $cls('min_purchase') }} tabular-nums">
                    @error('min_purchase') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @else <span class="mt-1 block text-xs text-stone-500">Dari barang yang dicakup. 0 = tanpa syarat.</span> @enderror
                </label>
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pf-scope">
            <h2 id="pf-scope" class="font-semibold">Barang yang dicakup</h2>
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Cakupan">
                @foreach (Promo::SCOPES as $key => $label)
                    <label class="{{ $chip }}"><input type="radio" wire:model.live="scope" value="{{ $key }}" class="sr-only" @checked($scope === $key)> {{ $label }}</label>
                @endforeach
            </div>
            @if ($scope === 'kategori')
                <div class="flex flex-wrap gap-2">
                    @forelse ($this->categoryOptions as $id => $label)
                        <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-50 has-checked:text-emerald-900">
                            <input type="checkbox" wire:model="category_ids" value="{{ $id }}" class="sr-only" @checked(in_array($id, array_map('intval', $category_ids), true))> {{ $label }}
                        </label>
                    @empty
                        <p class="text-sm text-stone-500">Belum ada kategori barang.</p>
                    @endforelse
                </div>
                @if ($err('category_ids') || $err('category_ids.*')) <span class="block text-xs text-red-600">{{ $err('category_ids') ?: $err('category_ids.*') }}</span> @endif
            @elseif ($scope === 'barang')
                <div class="relative">
                    <label class="block text-sm"><span class="sr-only">Cari barang</span>
                        <input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Cari nama, kode, atau barcode barang…" class="{{ $cls('product_ids') }}" autocomplete="off">
                    </label>
                    @if ($this->productResults)
                        <ul class="absolute z-10 mt-1 w-full divide-y divide-stone-100 overflow-hidden rounded-lg border border-stone-200 bg-white shadow-lg">
                            @foreach ($this->productResults as $p)
                                <li wire:key="pr-{{ $p['id'] }}">
                                    <button type="button" wire:click="addProduct({{ $p['id'] }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-emerald-50">
                                        <span class="min-w-0 truncate"><span class="font-mono text-xs text-stone-500">{{ $p['code'] }}</span> {{ $p['name'] }}</span>
                                        <span class="shrink-0 tabular-nums text-stone-500">{{ $rp($p['price']) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($productSearch)) >= 2)
                        <p class="mt-1 text-xs text-stone-500">Tidak ada barang aktif yang cocok.</p>
                    @endif
                </div>
                @if ($this->selectedProducts)
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($this->selectedProducts as $p)
                            <li wire:key="sp-{{ $p['id'] }}" class="inline-flex items-center gap-1.5 rounded-full border border-emerald-300 bg-emerald-50 py-1 pr-1 pl-3 text-sm text-emerald-900">
                                {{ $p['name'] }} <span class="text-xs text-emerald-700 tabular-nums">{{ $rp($p['price']) }}</span>
                                <button type="button" wire:click="removeProduct({{ $p['id'] }})" class="grid size-5 place-items-center rounded-full hover:bg-emerald-200" aria-label="Hapus {{ $p['name'] }}">×</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($err('product_ids') || $err('targets')) <span class="block text-xs text-red-600">{{ $err('product_ids') ?: $err('targets') }}</span> @endif
                @if ($type === 'grosir' && (int) $value > 0 && collect($this->selectedProducts)->contains(fn ($p) => $p['price'] <= (int) $value))
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Harga grosir tidak lebih murah dari harga jual sebagian barang terpilih — barang itu tidak mendapat potongan.</p>
                @endif
            @else
                <p class="text-xs text-stone-500">Berlaku untuk semua barang. Satu barang hanya mendapat satu promo — yang paling hemat.</p>
            @endif
            @if ($scope !== 'barang' && $err('targets')) <span class="block text-xs text-red-600">{{ $err('targets') }}</span> @endif
        </section>

        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pf-when">
            <h2 id="pf-when" class="font-semibold">Waktu berlaku</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Mulai</span>
                    <input type="date" wire:model="starts_on" value="{{ $starts_on }}" class="{{ $cls('starts_on') }}">
                    @error('starts_on') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Berakhir</span>
                    <input type="date" wire:model="ends_on" value="{{ $ends_on }}" class="{{ $cls('ends_on') }}">
                    @error('ends_on') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-stone-700">Hari berlaku</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (Index::DAY_LABELS as $d => $label)
                        <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-50 has-checked:text-emerald-900">
                            <input type="checkbox" wire:model="days" value="{{ $d }}" class="sr-only" @checked(in_array($d, array_map('intval', $days), true))> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @if ($err('days') || $err('days.*')) <span class="mt-1 block text-xs text-red-600">{{ $err('days') ?: $err('days.*') }}</span> @endif
            </fieldset>
            <label class="flex items-center gap-2.5 text-sm">
                <input type="checkbox" wire:model.live="timed" class="size-4 accent-emerald-700" @checked($timed)> Hanya pada jam tertentu
            </label>
            @if ($timed)
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Jam mulai</span>
                        <input type="time" wire:model="time_from" value="{{ $time_from }}" class="{{ $cls('time_from') }}">
                        @error('time_from') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Jam selesai</span>
                        <input type="time" wire:model="time_to" value="{{ $time_to }}" class="{{ $cls('time_to') }}">
                        @error('time_to') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            @endif
            <label class="flex items-center gap-2.5 text-sm">
                <input type="checkbox" wire:model="is_active" class="size-4 accent-emerald-700" @checked($is_active)>
                Promo aktif <span class="text-xs text-stone-500">(nonaktif tidak dipakai kasir walau dalam periode)</span>
            </label>
        </section>

        <div class="flex flex-wrap justify-end gap-3">
            @if ($promoId)
                @if ($this->usage === 0)
                    <button type="button" wire:click="delete" wire:confirm="Hapus promo ini? Promo yang dihapus tidak bisa dikembalikan." class="mr-auto rounded-xl px-3 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">Hapus promo</button>
                @else
                    <p class="mr-auto self-center text-xs text-stone-500">Sudah dipakai {{ $this->usage }}× — tidak bisa dihapus, nonaktifkan saja.</p>
                @endif
            @endif
            <a href="{{ route('promo') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan</button>
        </div>
    </form>
</div>
