@use('App\Livewire\Pengaturan\Credit')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $input = 'w-full rounded-lg border bg-white py-2 pr-16 pl-3 text-sm tabular-nums outline-none focus:ring-4';
    $ok = 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15';
    $bad = 'border-red-400 focus:ring-red-500/15';
    $fields = [
        ['limit_siswa', 'Batas bon siswa', 'rupiah', 'Total bon belum lunas paling banyak per siswa.'],
        ['limit_guru', 'Batas bon guru', 'rupiah', 'Total bon belum lunas paling banyak per guru.'],
        ['default_days', 'Jatuh tempo bawaan', 'hari', 'Dipilih otomatis di kasir saat bayar dengan bon.'],
        ['max_days', 'Jatuh tempo terlama', 'hari', 'Kasir tidak bisa memilih tanggal lebih lama dari ini.'],
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
        <p class="text-xs text-stone-500">Aturan bon (bayar nanti) untuk anggota.</p>
        <x-pengaturan.nav />
    </header>

    @unless ($this->canEdit)
        <p class="mx-4 mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:mx-6" role="status">Mode baca saja — hanya Admin yang dapat mengubah pengaturan koperasi.</p>
    @endunless

    <form wire:submit="save" class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" novalidate>
        <fieldset class="contents" @disabled(! $this->canEdit)>
            <div class="min-w-0 space-y-5">
                <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-credit">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="sec-credit" class="font-semibold">Bon anggota</h2>
                            <p class="text-xs text-stone-500">Anggota aktif boleh berbelanja dulu dan membayar sebelum jatuh tempo.</p>
                        </div>
                        <label class="flex shrink-0 cursor-pointer items-center gap-2 text-sm font-medium">
                            <input type="checkbox" wire:model.live="enabled" class="size-4 accent-emerald-700"> Aktifkan bon
                        </label>
                    </div>
                    @unless ($enabled)
                        <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">Bon dimatikan: kasir tidak bisa menerima pembayaran bon. Pelunasan bon lama tetap bisa dicatat.</p>
                    @endunless
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($fields as [$prop, $label, $unit, $hint])
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</span>
                                <span class="relative block">
                                    <input type="number" wire:model.live.debounce.400ms="{{ $prop }}" value="{{ $this->{$prop} }}" min="{{ Credit::LIMITS[$prop][0] }}" max="{{ Credit::LIMITS[$prop][1] }}" step="{{ $unit === 'rupiah' ? 1000 : 1 }}"
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
                        <input type="checkbox" wire:model.live="block_overdue" class="mt-0.5 size-4 accent-emerald-700">
                        <span>Tolak bon baru bila anggota masih punya bon yang lewat jatuh tempo<span class="block text-xs text-stone-500">Disarankan aktif agar bon tidak menumpuk.</span></span>
                    </label>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="restoreDefaults" class="mr-auto rounded-xl px-3 py-2.5 text-sm font-medium text-stone-600 hover:bg-stone-100">Kembalikan bawaan</button>
                    <a href="{{ route('pengaturan') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan</button>
                </div>
            </div>
        </fieldset>

        <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-overrides">
            <h2 id="sec-overrides" class="font-semibold">Batas khusus anggota</h2>
            <p class="text-xs text-stone-500">Diatur di form anggota. Anggota ini tidak mengikuti batas bawaan.</p>
            @if ($this->overrides)
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @foreach ($this->overrides as $o)
                        <li class="flex justify-between gap-2 py-2">
                            <a href="{{ route('anggota.show', $o['id']) }}" wire:navigate class="min-w-0 truncate hover:text-emerald-800 hover:underline">{{ $o['name'] }}</a>
                            <span class="shrink-0 tabular-nums">{{ $rp($o['limit']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-stone-400">Belum ada.</p>
            @endif
        </aside>
    </form>
</div>
