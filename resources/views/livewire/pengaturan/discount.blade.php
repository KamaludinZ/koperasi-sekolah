@use('App\Livewire\Pengaturan\Discount')
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $input = 'w-full rounded-lg border bg-white py-2 pr-16 pl-3 text-sm tabular-nums outline-none focus:ring-4';
    $ok = 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15';
    $bad = 'border-red-400 focus:ring-red-500/15';
    $fields = [
        ['max_percent', 'Batas persen', '%', 'Potongan persen paling besar per transaksi tanpa admin.'],
        ['max_amount', 'Batas rupiah', 'rupiah', 'Potongan paling besar per transaksi tanpa admin. Isi 0 bila kasir hanya boleh diskon lewat admin.'],
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
        <p class="text-xs text-stone-500">Potongan manual yang boleh diberikan kasir tanpa persetujuan admin.</p>
        <x-pengaturan.nav />
    </header>

    @unless ($this->canEdit)
        <p class="mx-4 mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:mx-6" role="status">Mode baca saja — hanya Admin yang dapat mengubah pengaturan koperasi.</p>
    @endunless

    <form wire:submit="save" class="space-y-5 px-4 py-6 sm:px-6" novalidate>
        <fieldset class="contents" @disabled(! $this->canEdit)>
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-discount">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="sec-discount" class="font-semibold">Diskon manual kasir</h2>
                        <p class="text-xs text-stone-500">Kasir dapat memotong total belanja (persen atau rupiah) sebelum pajak. Admin selalu boleh melewati batas.</p>
                    </div>
                    <label class="flex shrink-0 cursor-pointer items-center gap-2 text-sm font-medium">
                        <input type="checkbox" wire:model.live="enabled" class="size-4 accent-emerald-700"> Izinkan diskon manual
                    </label>
                </div>
                @unless ($enabled)
                    <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">Diskon manual dimatikan: hanya admin yang dapat memberi potongan manual. Promo otomatis dan kupon tetap berlaku.</p>
                @endunless
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as [$prop, $label, $unit, $hint])
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</span>
                            <span class="relative block">
                                <input type="number" wire:model.live.debounce.400ms="{{ $prop }}" value="{{ $this->{$prop} }}" min="{{ Discount::LIMITS[$prop][0] }}" max="{{ Discount::LIMITS[$prop][1] }}" step="{{ $unit === 'rupiah' ? 1000 : 1 }}"
                                       class="{{ $input }} {{ $errors->has($prop) ? $bad : $ok }}" @error($prop) aria-invalid="true" @enderror>
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">{{ $unit }}</span>
                            </span>
                            @error($prop)
                                <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                            @else
                                <span class="mt-1 block text-xs text-stone-500">{{ $hint }}@if ($unit === 'rupiah') = {{ $rp($this->{$prop}) }}@endif</span>
                            @enderror
                        </label>
                    @endforeach
                </div>
                <label class="flex cursor-pointer items-start gap-2 text-sm">
                    <input type="checkbox" wire:model.live="reason_required" class="mt-0.5 size-4 accent-emerald-700">
                    <span>Kasir wajib menulis alasan diskon<span class="block text-xs text-stone-500">Alasan tercatat di transaksi dan laporan diskon.</span></span>
                </label>
                <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">
                    @if ($enabled)
                        Kasir boleh memberi diskon sampai {{ (int) $max_percent }}% dan tidak lebih dari {{ $rp((int) $max_amount) }} per transaksi{{ $reason_required ? ', dengan alasan' : '' }}. Di atas itu perlu admin.
                    @else
                        Hanya admin yang dapat memberi diskon manual.
                    @endif
                </p>
            </section>

            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="restoreDefaults" class="mr-auto rounded-xl px-3 py-2.5 text-sm font-medium text-stone-600 hover:bg-stone-100">Kembalikan bawaan</button>
                <a href="{{ route('pengaturan') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan</button>
            </div>
        </fieldset>
    </form>
</div>
