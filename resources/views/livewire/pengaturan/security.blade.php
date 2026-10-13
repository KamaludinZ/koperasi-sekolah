@use('App\Livewire\Pengaturan\Security')
@php
    $input = 'w-full rounded-lg border bg-white py-2 pr-16 pl-3 text-sm tabular-nums outline-none focus:ring-4';
    $ok = 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15';
    $bad = 'border-red-400 focus:ring-red-500/15';
    $checks = $this->checks;
    $passed = collect($checks)->where('ok', true)->count();
    // [properti, label, satuan, keterangan]
    $sections = [
        'retention' => ['Retensi data', 'Berapa lama data disimpan sebelum dibersihkan otomatis. Jejak audit selalu disimpan permanen.', [
            ['trash_days', 'Data terhapus (barang, anggota, kategori)', 'hari', 'Selama masa ini Admin masih bisa memulihkan.'],
            ['export_days', 'Berkas unduhan laporan & rekap', 'hari', 'Berkas CSV/PDF yang dibuat lewat antrean.'],
            ['notification_days', 'Notifikasi yang sudah dibaca', 'hari', 'Notifikasi belum dibaca tidak dihapus.'],
        ]],
        'session' => ['Sesi & login', 'Melindungi akun bila perangkat kasir ditinggal atau kata sandi ditebak.', [
            ['idle_minutes', 'Keluar otomatis bila tidak aktif', 'menit', 'Petugas harus masuk lagi setelah waktu ini.'],
            ['max_attempts', 'Kunci akun setelah gagal masuk', 'kali', 'Kunci bertingkat: 2× dan 3× lipatnya mengunci lebih lama.'],
        ]],
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
                <p class="text-xs text-stone-500">Masa simpan data, sesi login, dan batas berkas unggahan.</p>
            </div>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
        </div>
        <x-pengaturan.nav />
    </header>

    @unless ($this->canEdit)
        <p class="mx-4 mt-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:mx-6" role="status">
            <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            Mode baca saja — hanya Admin yang dapat mengubah pengaturan koperasi.
        </p>
    @endunless

    <form wire:submit="save" class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" novalidate>
        <fieldset class="contents" @disabled(! $this->canEdit)>
        <div class="min-w-0 space-y-5">
            @foreach ($sections as $key => [$title, $desc, $fields])
                <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-{{ $key }}">
                    <div>
                        <h2 id="sec-{{ $key }}" class="font-semibold">{{ $title }}</h2>
                        <p class="text-xs text-stone-500">{{ $desc }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($fields as [$prop, $label, $unit, $hint])
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</span>
                                <span class="relative block">
                                    <input type="number" wire:model.live.debounce.400ms="{{ $prop }}" value="{{ $this->{$prop} }}" min="{{ Security::LIMITS[$prop][0] }}" max="{{ Security::LIMITS[$prop][1] }}"
                                           class="{{ $input }} {{ $errors->has($prop) ? $bad : $ok }}" @error($prop) aria-invalid="true" @enderror>
                                    <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">{{ $unit }}</span>
                                </span>
                                @error($prop)
                                    <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                                @else
                                    <span class="mt-1 block text-xs text-stone-500">{{ $hint }} ({{ Security::LIMITS[$prop][0] }}–{{ Security::LIMITS[$prop][1] }})</span>
                                @enderror
                            </label>
                        @endforeach
                    </div>
                    @if ($key === 'session' && ! $errors->has('max_attempts'))
                        @php $n = (int) $max_attempts; @endphp
                        <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">
                            {{ $n }}× gagal → kunci 1 menit · {{ $n * 2 }}× → 15 menit · {{ $n * 3 }}× → sampai dibuka Admin.
                            <a href="{{ route('pengguna.terkunci') }}" wire:navigate class="font-semibold text-emerald-800 hover:underline">Lihat akun terkunci</a>
                        </p>
                    @endif
                </section>
            @endforeach

            {{-- Berkas unggahan --}}
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-upload">
                <div>
                    <h2 id="sec-upload" class="font-semibold">Keamanan berkas unggahan</h2>
                    <p class="text-xs text-stone-500">Berkas di luar jenis & ukuran ini ditolak. Isi berkas juga diperiksa, bukan hanya nama akhirannya.</p>
                </div>
                @foreach ([
                    ['logo', 'Logo koperasi', Security::LOGO_TYPES, 'SVG tidak diizinkan karena dapat berisi skrip.'],
                    ['import', 'Impor data barang', Security::IMPORT_TYPES, 'Berkas Excel dengan makro (.xlsm) selalu ditolak.'],
                ] as [$kind, $label, $types, $note])
                    <div class="grid gap-4 border-t border-stone-100 pt-4 first-of-type:border-0 first-of-type:pt-0 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-stone-700">{{ $label }} — ukuran maksimal</span>
                            <span class="relative block">
                                <input type="number" wire:model.live.debounce.400ms="{{ $kind }}_max_kb" value="{{ $this->{$kind.'_max_kb'} }}" min="{{ Security::LIMITS[$kind.'_max_kb'][0] }}" max="{{ Security::LIMITS[$kind.'_max_kb'][1] }}" step="64"
                                       class="{{ $input }} {{ $errors->has($kind.'_max_kb') ? $bad : $ok }}">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">KB</span>
                            </span>
                            @error($kind.'_max_kb')
                                <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                            @else
                                <span class="mt-1 block text-xs text-stone-500">= {{ Security::size($this->{$kind.'_max_kb'}) }} ({{ Security::size(Security::LIMITS[$kind.'_max_kb'][0]) }}–{{ Security::size(Security::LIMITS[$kind.'_max_kb'][1]) }})</span>
                            @enderror
                        </label>
                        <fieldset>
                            <legend class="mb-1 text-sm font-medium text-stone-700">Jenis yang diterima</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($types as $value => $text)
                                    <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white">
                                        <input type="checkbox" wire:model.live="{{ $kind }}_types" value="{{ $value }}" @checked(in_array($value, $this->{$kind.'_types'}, true)) class="sr-only"> {{ $text }}
                                    </label>
                                @endforeach
                            </div>
                            @if ($typeError = $errors->first($kind.'_types') ?: $errors->first($kind.'_types.*'))
                                <span class="mt-1 block text-xs text-red-600">{{ $typeError }}</span>
                            @else
                                <span class="mt-1 block text-xs text-stone-500">{{ $note }}</span>
                            @endif
                        </fieldset>
                    </div>
                @endforeach
            </section>

            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="restoreDefaults" class="mr-auto rounded-xl px-3 py-2.5 text-sm font-medium text-stone-600 hover:bg-stone-100">Kembalikan bawaan</button>
                <a href="{{ route('pengaturan') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan</button>
            </div>
        </div>

        {{-- Kesiapan produksi --}}
        <aside class="h-fit space-y-3 lg:sticky lg:top-4" aria-labelledby="sec-checks">
            <div class="relative isolate overflow-hidden rounded-2xl bg-emerald-950 p-5 text-emerald-50">
                <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
                <h2 id="sec-checks" class="font-display text-xl font-bold text-white">Kesiapan server</h2>
                <p class="text-sm text-emerald-200">{{ $passed }} dari {{ count($checks) }} pemeriksaan lolos</p>
                <ul class="mt-4 space-y-3 text-sm">
                    @foreach ($checks as $c)
                        <li class="flex gap-2.5">
                            @if ($c['ok'])
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-amber-400 text-emerald-950"><svg class="size-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg><span class="sr-only">Lolos</span></span>
                            @else
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-red-500/90 text-white"><svg class="size-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v6m0 4h.01"/></svg><span class="sr-only">Perlu tindakan</span></span>
                            @endif
                            <span>
                                <span class="block font-medium text-white">{{ $c['label'] }}</span>
                                <span class="block text-xs text-emerald-300">{{ $c['detail'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <p class="text-xs text-stone-500">Pemeriksaan ini dibaca dari konfigurasi server dan diubah oleh pengelola server, bukan dari halaman ini.</p>
        </aside>
        </fieldset>
    </form>
</div>
