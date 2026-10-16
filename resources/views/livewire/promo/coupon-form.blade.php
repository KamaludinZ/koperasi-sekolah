@use('App\Models\Coupon')
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $input = 'w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4';
    $cls = fn ($f) => $input.' '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
    $chip = 'cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <p class="text-xs text-stone-500"><a href="{{ route('promo') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Diskon & Promo</a> / {{ $couponId ? 'Ubah kupon' : 'Kupon baru' }}</p>
        <h1 class="text-lg font-semibold">{{ $couponId ? 'Ubah kupon' : 'Kupon baru' }}</h1>
        <x-promo.nav active="promo" />
    </header>

    <form wire:submit="save" class="max-w-3xl space-y-5 px-4 py-6 sm:px-6" novalidate>
        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="cf-id">
            <h2 id="cf-id" class="font-semibold">Kode & potongan</h2>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Kode kupon</span>
                    <input type="text" wire:model.blur="code" value="{{ $code }}" maxlength="30" placeholder="mis. MPLS2026" autocomplete="off" class="{{ $cls('code') }} font-mono uppercase tracking-wide">
                    @error('code') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @else <span class="mt-1 block text-xs text-stone-500">Diketik kasir saat pembayaran.</span> @enderror
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Nama kupon</span>
                    <input type="text" wire:model="name" value="{{ $name }}" maxlength="100" placeholder="mis. Voucher siswa baru" class="{{ $cls('name') }}">
                    @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Jenis potongan">
                @foreach (Coupon::TYPES as $key => $label)
                    <label class="{{ $chip }}"><input type="radio" wire:model.live="type" value="{{ $key }}" class="sr-only" @checked($type === $key)> {{ $label }}</label>
                @endforeach
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">{{ $type === 'persen' ? 'Potongan (%)' : 'Potongan (Rp)' }}</span>
                    <input type="number" wire:model="value" value="{{ $value }}" min="1" max="{{ $type === 'persen' ? 100 : 10000000 }}" step="{{ $type === 'persen' ? 1 : 500 }}" class="{{ $cls('value') }} tabular-nums">
                    @error('value') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                @if ($type === 'persen')
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Potongan maks. (Rp) <span class="font-normal text-stone-500">(opsional)</span></span>
                        <input type="number" wire:model="max_discount" value="{{ $max_discount }}" min="1" step="1000" class="{{ $cls('max_discount') }} tabular-nums">
                        @error('max_discount') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Minimal belanja (Rp)</span>
                    <input type="number" wire:model="min_purchase" value="{{ $min_purchase }}" min="0" step="1000" class="{{ $cls('min_purchase') }} tabular-nums">
                    @error('min_purchase') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @else <span class="mt-1 block text-xs text-stone-500">0 = tanpa syarat.</span> @enderror
                </label>
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="cf-limit">
            <h2 id="cf-limit" class="font-semibold">Kuota & masa berlaku</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Kuota total <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="number" wire:model="quota" value="{{ $quota }}" min="1" class="{{ $cls('quota') }} tabular-nums">
                    @error('quota') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @else <span class="mt-1 block text-xs text-stone-500">Kosongkan untuk tanpa batas.@if ($this->used) Sudah dipakai {{ $this->used }}×.@endif</span> @enderror
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Batas per anggota <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="number" wire:model="per_member" value="{{ $per_member }}" min="1" max="100" class="{{ $cls('per_member') }} tabular-nums">
                    @error('per_member') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @else <span class="mt-1 block text-xs text-stone-500">Berapa kali satu anggota boleh memakai.</span> @enderror
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Mulai</span>
                    <input type="date" wire:model="starts_on" value="{{ $starts_on }}" class="{{ $cls('starts_on') }}">
                    @error('starts_on') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Berakhir</span>
                    <input type="date" wire:model="ends_on" value="{{ $ends_on }}" class="{{ $cls('ends_on') }}">
                    @error('ends_on') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="flex items-center gap-2.5 text-sm">
                <input type="checkbox" wire:model="is_active" class="size-4 accent-emerald-700" @checked($is_active)>
                Kupon aktif <span class="text-xs text-stone-500">(nonaktif ditolak kasir walau dalam masa berlaku)</span>
            </label>
        </section>

        <div class="flex flex-wrap justify-end gap-3">
            @if ($couponId)
                @if ($this->used === 0)
                    <button type="button" wire:click="delete" wire:confirm="Hapus kupon ini? Kupon yang dihapus tidak bisa dikembalikan." class="mr-auto rounded-xl px-3 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50">Hapus kupon</button>
                @else
                    <p class="mr-auto self-center text-xs text-stone-500">Sudah dipakai {{ $this->used }}× — tidak bisa dihapus, nonaktifkan saja.</p>
                @endif
            @endif
            <a href="{{ route('promo') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan</button>
        </div>
    </form>
</div>
