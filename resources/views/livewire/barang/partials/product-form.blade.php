{{-- Panel geser: form tambah / ubah barang --}}
@if ($editing)
    @php
        $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
        $isNew = $form->id === null;
    @endphp
    <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="product-form-title"
         x-data x-on:keydown.escape.window="$wire.cancel()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>

        <form wire:submit="save" class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl"
              x-data="{ price: $wire.entangle('form.price'), cost: $wire.entangle('form.cost_price') }"
              x-init="$nextTick(() => $el.querySelector('[autofocus]')?.focus())">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="product-form-title" class="font-semibold">{{ $isNew ? 'Tambah barang' : 'Ubah barang' }}</h2>
                <button type="button" wire:click="cancel" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                <div class="grid grid-cols-3 gap-3">
                    <label class="col-span-1 block">
                        <span class="mb-1 block text-sm font-medium">Kode</span>
                        <input type="text" wire:model="form.code" @if ($isNew) autofocus @endif placeholder="ATK010" class="{{ $input }} font-mono uppercase">
                        @error('form.code') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="col-span-2 block">
                        <span class="mb-1 block text-sm font-medium">Nama barang</span>
                        <input type="text" wire:model="form.name" @unless ($isNew) autofocus @endunless placeholder="mis. Buku Gambar A4" class="{{ $input }}">
                        @error('form.name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                {{-- Barcode: kosong = mengikuti kode barang; bisa diisi barcode pabrik atau dibuat nomor internal baru. --}}
                <fieldset class="rounded-xl border border-stone-200 p-3" data-barcode-field>
                    <legend class="px-1 text-sm font-medium">Barcode</legend>
                    <div class="flex items-start gap-3">
                        <label class="block min-w-0 flex-1">
                            <span class="sr-only">Nilai barcode</span>
                            <input type="text" wire:model.live.debounce.400ms="form.barcode" maxlength="64" placeholder="Kosong = pakai kode barang"
                                   aria-describedby="barcode-help" class="{{ $input }} font-mono">
                        </label>
                        @php $preview = trim((string) $form->barcode) !== '' ? trim((string) $form->barcode) : mb_strtoupper(trim($form->code)); @endphp
                        @if ($preview !== '')
                            <x-barcode :value="$preview" :height="36" class="w-32 shrink-0" />
                        @endif
                    </div>
                    @error('form.barcode') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    <p id="barcode-help" class="mt-1 text-xs text-stone-500">Isi dengan barcode pabrik (scan ke kolom ini), atau biarkan kosong agar label memakai kode barang.</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" wire:click="useCodeAsBarcode" class="rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50">Pakai kode barang</button>
                        <button type="button" wire:click="generateBarcode" class="rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-900 hover:bg-emerald-100">Buat nomor baru</button>
                    </div>
                </fieldset>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Kategori</span>
                        <select wire:model="form.category" class="{{ $input }}">
                            <option value="">Pilih kategori</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                        @error('form.category') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Satuan</span>
                        <select wire:model="form.unit" class="{{ $input }}">
                            @foreach (\App\Livewire\Forms\ProductForm::UNITS as $unit)
                                <option value="{{ $unit }}">{{ $unit }}</option>
                            @endforeach
                        </select>
                        @error('form.unit') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <fieldset class="rounded-xl border border-stone-200 p-4">
                    <legend class="px-1 text-sm font-medium">Harga</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="mb-1 block text-xs text-stone-600">Harga beli (Rp)</span>
                            <input type="number" inputmode="numeric" min="0" step="100" x-model.number="cost" class="{{ $input }} tabular-nums">
                            @error('form.cost_price') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs text-stone-600">Harga jual (Rp)</span>
                            <input type="number" inputmode="numeric" min="0" step="100" x-model.number="price" class="{{ $input }} tabular-nums">
                            @error('form.price') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <p class="mt-2 text-xs" x-show="price > 0 && cost !== null && cost !== ''"
                       :class="price - cost < 0 ? 'text-red-600' : 'text-stone-500'">
                        Untung per barang:
                        <strong class="tabular-nums" x-text="window.rupiah(price - cost)"></strong>
                        <span x-show="cost > 0" x-text="'(' + Math.round((price - cost) / cost * 100) + '%)'"></span>
                        <span x-show="price - cost < 0">· harga jual di bawah harga beli</span>
                    </p>
                </fieldset>

                <fieldset class="rounded-xl border border-stone-200 p-4">
                    <legend class="px-1 text-sm font-medium">Stok</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="mb-1 block text-xs text-stone-600">Jumlah stok</span>
                            <input type="number" inputmode="numeric" min="0" wire:model="form.stock" class="{{ $input }} tabular-nums">
                            @error('form.stock') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs text-stone-600">Batas stok menipis</span>
                            <input type="number" inputmode="numeric" min="0" wire:model="form.min_stock" class="{{ $input }} tabular-nums">
                            @error('form.min_stock') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-stone-500">Barang ditandai “menipis” bila stok sama dengan atau di bawah batas ini.</p>
                </fieldset>

                <label class="flex items-start gap-3 rounded-xl border border-stone-200 p-4">
                    <input type="checkbox" wire:model="form.is_active" class="mt-0.5 size-4 accent-emerald-700">
                    <span>
                        <span class="block text-sm font-medium">Dijual di kasir</span>
                        <span class="block text-xs text-stone-500">Matikan untuk menyembunyikan barang dari layar kasir tanpa menghapusnya.</span>
                    </span>
                </label>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="cancel" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                    {{ $isNew ? 'Simpan barang' : 'Simpan perubahan' }}
                </button>
            </div>
        </form>
    </div>
@endif
