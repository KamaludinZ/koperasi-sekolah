@use('App\Livewire\Pengaturan\Returns')
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $input = 'w-full rounded-lg border bg-white py-2 pr-16 pl-3 text-sm tabular-nums outline-none focus:ring-4';
    $ok = 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15';
    $bad = 'border-red-400 focus:ring-red-500/15';
    $fields = [
        ['limit_days', 'Batas pengajuan', 'hari', 'Retur & pembatalan paling lambat sekian hari setelah pembelian.'],
        ['void_auto_max', 'Batal otomatis sampai', 'rupiah', 'Isi 0 agar setiap pembatalan perlu persetujuan.'],
        ['void_auto_minutes', 'Dalam waktu', 'menit', 'Dihitung sejak transaksi dibuat.'],
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
        <p class="text-xs text-stone-500">Batas waktu retur dan aturan persetujuan pembatalan transaksi.</p>
        <x-pengaturan.nav />
    </header>

    @unless ($this->canEdit)
        <p class="mx-4 mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:mx-6" role="status">Mode baca saja — hanya Admin yang dapat mengubah pengaturan koperasi.</p>
    @endunless

    <form wire:submit="save" class="space-y-5 px-4 py-6 sm:px-6" novalidate>
        <fieldset class="contents" @disabled(! $this->canEdit)>
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-returns">
                <div>
                    <h2 id="sec-returns" class="font-semibold">Retur & pembatalan</h2>
                    <p class="text-xs text-stone-500">Retur dan pembatalan perlu persetujuan Admin/Pengurus, kecuali pembatalan kecil yang langsung diperbaiki kasir.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ($fields as [$prop, $label, $unit, $hint])
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</span>
                            <span class="relative block">
                                <input type="number" wire:model.live.debounce.400ms="{{ $prop }}" value="{{ $this->{$prop} }}" min="{{ Returns::LIMITS[$prop][0] }}" max="{{ Returns::LIMITS[$prop][1] }}" step="{{ $unit === 'rupiah' ? 1000 : 1 }}"
                                       class="{{ $input }} {{ $errors->has($prop) ? $bad : $ok }}" @error($prop) aria-invalid="true" @enderror>
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">{{ $unit }}</span>
                            </span>
                            @error($prop)
                                <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                            @else
                                <span class="mt-1 block text-xs text-stone-500">{{ $hint }}</span>
                            @enderror
                        </label>
                    @endforeach
                </div>
                <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">
                    @if ((int) $void_auto_max > 0)
                        Pembatalan oleh kasir yang membuat transaksi, bernilai ≤ {{ $rp($void_auto_max) }} dan diajukan ≤ {{ (int) $void_auto_minutes }} menit setelah transaksi, langsung disetujui. Selebihnya menunggu persetujuan.
                    @else
                        Setiap pembatalan menunggu persetujuan Admin/Pengurus.
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
