@php
    $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $label = 'mb-1 block text-sm font-medium text-stone-700';
    $err = fn ($f) => $errors->first($f);
    $logoSrc = $logoUpload && ! $errors->has('logoUpload') ? $logoUpload->temporaryUrl() : $logo;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto"
     x-data="{ dirty: false }" x-on:input="dirty = true" x-on:change="dirty = true" x-on:profile-saved.window="dirty = false"
     x-init="window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = '' } })">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
        <p class="text-xs text-stone-500">Identitas yang tampil di struk, beranda publik, dan laporan.</p>
        <x-pengaturan.nav />
    </header>

    @unless ($this->canEdit)
        <p class="mx-4 mt-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:mx-6" role="status">
            <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            Mode baca saja — hanya Admin yang dapat mengubah pengaturan koperasi.
        </p>
    @endunless
    <form wire:submit="save" class="grid gap-5 px-4 py-6 sm:px-6 xl:grid-cols-[minmax(0,1fr)_20rem]" novalidate>
        <fieldset class="contents" @disabled(! $this->canEdit)>
        <div class="min-w-0 space-y-5">

            {{-- Identitas --}}
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-identity">
                <h2 id="sec-identity" class="font-semibold">Identitas</h2>
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                    <label class="block"><span class="{{ $label }}">Nama koperasi</span>
                        <input type="text" wire:model.live.debounce.400ms="name" class="{{ $input }}" placeholder="mis. Koperasi Siswa Al-Hikmah">
                        @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block"><span class="{{ $label }}">Nama singkat</span>
                        <input type="text" wire:model.live.debounce.400ms="short_name" class="{{ $input }}" placeholder="mis. Kopsis Al-Hikmah">
                        @error('short_name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        <span class="mt-1 block text-[11px] text-stone-500">Dipakai bila nama aplikasi kosong.</span>
                    </label>
                </div>
                <label class="block"><span class="{{ $label }}">Nama aplikasi <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model.live.debounce.400ms="app_name" maxlength="60" class="{{ $input }}" placeholder="{{ $short_name ?: 'mis. KOPSIS Al-Hikmah MTs 1' }}">
                    @error('app_name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    <span class="mt-1 block text-[11px] text-stone-500">Tampil di judul tab, menu samping, beranda, dan halaman masuk. Kosongkan untuk memakai nama singkat.</span>
                </label>
                <label class="block"><span class="{{ $label }}">Nama madrasah / sekolah</span>
                    <input type="text" wire:model.live.debounce.400ms="school" class="{{ $input }}" placeholder="mis. Madrasah Tsanawiyah Negeri 1 …">
                    @error('school') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="{{ $label }}">Motto <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model.live.debounce.400ms="motto" class="{{ $input }}" placeholder="mis. Jujur, amanah, dan saling menolong">
                    @error('motto') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                {{-- Logo --}}
                <div>
                    <span class="{{ $label }}">Logo</span>
                    <div class="flex flex-wrap items-center gap-4">
                        @if ($logoSrc)
                            <img src="{{ $logoSrc }}" alt="Pratinjau logo" class="size-20 rounded-xl object-cover ring-1 ring-stone-200">
                        @else
                            <span class="grid size-20 place-items-center rounded-xl bg-emerald-950" title="Lambang bawaan"><x-brand.mark class="size-12" /></span>
                        @endif
                        <div class="space-y-2">
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm font-medium hover:bg-stone-50 focus-within:ring-4 focus-within:ring-emerald-600/15">
                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 20h16"/></svg>
                                {{ $logoSrc ? 'Ganti logo' : 'Unggah logo' }}
                                <input type="file" wire:model="logoUpload" accept="image/png,image/jpeg,image/webp" class="sr-only">
                            </label>
                            @if ($logoSrc)
                                <button type="button" wire:click="removeLogo" class="ml-2 text-sm font-medium text-red-700 hover:underline">Hapus</button>
                            @endif
                            <p class="text-xs text-stone-500" wire:loading.remove wire:target="logoUpload">PNG/JPG/WEBP, persegi, min. 64×64 px, maks. 512 KB.</p>
                            <p class="text-xs text-emerald-700" wire:loading wire:target="logoUpload">Mengunggah…</p>
                            @error('logoUpload') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
            </section>

            {{-- Kontak --}}
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-contact">
                <h2 id="sec-contact" class="font-semibold">Alamat & kontak</h2>
                <label class="block"><span class="{{ $label }}">Alamat</span>
                    <textarea wire:model.live.debounce.400ms="address" rows="2" class="{{ $input }}" placeholder="Jalan, kelurahan, kota"></textarea>
                    @error('address') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block"><span class="{{ $label }}">Telepon / WhatsApp</span>
                        <input type="tel" inputmode="tel" wire:model.live.debounce.400ms="phone" class="{{ $input }}" placeholder="08xx-xxxx-xxxx">
                        @error('phone') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block"><span class="{{ $label }}">Email <span class="font-normal text-stone-500">(opsional)</span></span>
                        <input type="email" wire:model="email" class="{{ $input }}" placeholder="koperasi@madrasah.sch.id">
                        @error('email') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

            {{-- Jam buka --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sec-hours">
                <h2 id="sec-hours" class="font-semibold">Jam buka</h2>
                <p class="text-xs text-stone-500">Dipakai untuk status "Sedang buka/tutup" di beranda publik.</p>
                <ul class="mt-3 divide-y divide-stone-100">
                    @foreach (\App\Support\OpeningHours::DAYS as $d => $dayName)
                        <li wire:key="day-{{ $d }}" class="flex flex-wrap items-center gap-3 py-2">
                            <label class="flex w-32 items-center gap-2 text-sm font-medium">
                                <input type="checkbox" wire:model.live="schedule.{{ $d }}.open" class="size-4 accent-emerald-700"> {{ $dayName }}
                            </label>
                            @if ($schedule[$d]['open'])
                                <input type="time" wire:model.live.debounce.500ms="schedule.{{ $d }}.from" aria-label="Jam buka {{ $dayName }}" class="rounded-lg border border-stone-300 px-2 py-1 text-sm">
                                <span class="text-stone-400">–</span>
                                <input type="time" wire:model.live.debounce.500ms="schedule.{{ $d }}.to" aria-label="Jam tutup {{ $dayName }}" class="rounded-lg border border-stone-300 px-2 py-1 text-sm">
                                @error("schedule.$d.to") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                @error("schedule.$d.from") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            @else
                                <span class="text-sm text-stone-400">Tutup</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            <div class="sticky bottom-0 -mx-4 flex items-center justify-end gap-3 border-t border-stone-200 bg-stone-100/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
                <span x-show="dirty" x-cloak class="mr-auto text-xs font-medium text-amber-700">Ada perubahan yang belum disimpan</span>
                <a href="{{ route('pengaturan') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save,logoUpload"
                        class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan profil</button>
            </div>
        </div>

        {{-- Pratinjau --}}
        <aside class="h-fit space-y-4 xl:sticky xl:top-4" aria-label="Pratinjau">
            <div class="rounded-2xl border border-stone-200 bg-white p-4">
                <p class="mb-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">Kepala struk</p>
                <div class="mx-auto w-[58mm] max-w-full rounded-sm border border-dashed border-stone-300 bg-stone-50 p-3 text-center font-mono text-[11px] leading-snug">
                    <p class="font-bold uppercase">{{ $name ?: 'Nama koperasi' }}</p>
                    <p>{{ $school ?: 'Nama madrasah' }}</p>
                    @if ($address)<p>{{ $address }}</p>@endif
                    @if ($phone)<p>Telp. {{ $phone }}</p>@endif
                </div>
            </div>
            <div class="relative isolate overflow-hidden rounded-2xl bg-emerald-950 p-4 text-emerald-50">
                <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.1]" aria-hidden="true"></div>
                <p class="mb-3 text-xs font-semibold tracking-wide text-emerald-300 uppercase">Beranda publik</p>
                <div class="flex items-center gap-3">
                    @if ($logoSrc)<img src="{{ $logoSrc }}" alt="" class="size-10 rounded-lg object-cover">@else<x-brand.mark class="size-10" />@endif
                    <div class="min-w-0 leading-tight">
                        <p class="line-clamp-2 font-display text-lg leading-tight font-bold text-white">{{ $app_name ?: ($short_name ?: 'Nama aplikasi') }}</p>
                        <p class="truncate text-xs text-amber-300">{{ $school ?: 'Nama madrasah' }}</p>
                    </div>
                </div>
                @if ($motto)<p class="mt-3 text-sm text-emerald-100 italic">"{{ $motto }}"</p>@endif
                <dl class="mt-3 space-y-0.5 text-xs">
                    @forelse ($this->previewHours() as $h)
                        <div class="flex justify-between gap-2"><dt class="text-emerald-200">{{ $h['days'] }}</dt><dd class="text-white tabular-nums">{{ $h['time'] }}</dd></div>
                    @empty
                        <p class="text-amber-300">Belum ada hari buka.</p>
                    @endforelse
                </dl>
            </div>
        </aside>
        </fieldset>
    </form>
</div>
