@use('App\Livewire\Pengadaan\SupplierForm')
@php
    $input = 'w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4';
    $cls = fn ($f) => $input.' '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('pengadaan.supplier') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Supplier</a> / {{ $supplierId ? 'Ubah' : 'Tambah' }}</p>
                <h1 class="text-lg font-semibold">{{ $supplierId ? 'Ubah supplier' : 'Supplier baru' }}</h1>
            </div>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
        </div>
        <x-pengadaan.nav active="pengadaan.supplier" />
    </header>

    <form wire:submit="save" class="max-w-3xl space-y-5 px-4 py-6 sm:px-6" novalidate>
        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sf-id">
            <h2 id="sf-id" class="font-semibold">Identitas & kontak</h2>
            <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Nama supplier</span>
                <input type="text" wire:model="name" value="{{ $name }}" maxlength="100" placeholder="mis. CV Sinar Ilmu" class="{{ $cls('name') }}">
                @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Nama kontak <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="contact" value="{{ $contact }}" maxlength="60" placeholder="mis. Bu Lestari" class="{{ $cls('contact') }}">
                    @error('contact') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Telepon / WhatsApp</span>
                    <input type="tel" wire:model="phone" value="{{ $phone }}" maxlength="20" placeholder="0812-3456-7890" class="{{ $cls('phone') }}">
                    @error('phone') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Alamat <span class="font-normal text-stone-500">(opsional)</span></span>
                <textarea wire:model="address" rows="2" maxlength="200" class="{{ $cls('address') }}">{{ $address }}</textarea>
                @error('address') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
        </section>

        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sf-buy">
            <h2 id="sf-buy" class="font-semibold">Pembelian</h2>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-stone-700">Cara bayar</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (SupplierForm::TERMS + ['lain' => 'Tempo lain'] as $key => $label)
                        <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white">
                            <input type="radio" wire:model.live="termsChoice" value="{{ $key }}" class="sr-only" @checked((string) $termsChoice === (string) $key)> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @if ($termsChoice === 'lain')
                    <label class="mt-3 flex items-center gap-2 text-sm">
                        <input type="number" wire:model="customDays" value="{{ $customDays }}" min="1" max="120" class="{{ $cls('customDays') }} w-24 tabular-nums"> hari setelah barang diterima
                    </label>
                    @error('customDays') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                @endif
            </fieldset>
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-stone-700">Kategori barang yang dipasok</legend>
                <div class="flex flex-wrap gap-2">
                    @forelse ($this->categoryOptions as $c)
                        <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-50 has-checked:text-emerald-900">
                            <input type="checkbox" wire:model="categories" value="{{ $c }}" class="sr-only" @checked(in_array($c, $categories, true))> {{ $c }}
                        </label>
                    @empty
                        <p class="text-sm text-stone-500">Belum ada kategori barang.</p>
                    @endforelse
                </div>
                <p class="mt-1 text-xs text-stone-500">Dipakai untuk menyarankan supplier saat stok barang menipis.</p>
                @error('categories.*') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </fieldset>
            <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                <input type="text" wire:model="note" value="{{ $note }}" maxlength="200" placeholder="mis. Minimal pesan Rp300.000, gratis antar" class="{{ $cls('note') }}">
            </label>
            <label class="flex items-center gap-2.5 text-sm">
                <input type="checkbox" wire:model="is_active" class="size-4 accent-emerald-700" @checked($is_active)>
                Supplier aktif <span class="text-xs text-stone-500">(nonaktif tidak muncul saat membuat pesanan)</span>
            </label>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('pengadaan.supplier') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Simpan</button>
        </div>
    </form>
</div>
