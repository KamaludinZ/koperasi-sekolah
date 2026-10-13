@use('App\Support\ProductImportFormat')
@use('App\Livewire\Pengaturan\Security')
@php
    $l = $this->limits;
    $types = collect($l['types'])->map(fn ($t) => $t === 'xlsx' ? 'Excel (.xlsx)' : strtoupper($t))->join(', ', ' atau ');
    $accept = collect($l['types'])->map(fn ($t) => '.'.$t)->join(',');
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('barang.kategori') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Kategori & impor</a> / Impor barang</p>
                <h1 class="text-lg font-semibold">Impor Barang dari Berkas</h1>
            </div>
        </div>
        <x-barang.nav active="barang.kategori" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
        <form wire:submit="upload" class="min-w-0 space-y-5" novalidate>
            {{-- Langkah --}}
            <ol class="flex flex-wrap items-center gap-2 text-sm" aria-label="Langkah impor">
                @foreach (['Unggah berkas', 'Periksa baris', 'Simpan'] as $i => $step)
                    <li class="flex items-center gap-2">
                        <span @class(['grid size-6 place-items-center rounded-full text-xs font-bold', 'bg-emerald-700 text-white' => $i === 0, 'bg-stone-200 text-stone-500' => $i > 0])>{{ $i + 1 }}</span>
                        <span @class(['font-medium', 'text-emerald-900' => $i === 0, 'text-stone-500' => $i > 0])>{{ $step }}</span>
                        @if ($i < 2)<span class="mx-1 h-px w-6 bg-stone-300" aria-hidden="true"></span>@endif
                    </li>
                @endforeach
            </ol>

            {{-- Berkas --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="up-file">
                <h2 id="up-file" class="font-semibold">Berkas barang</h2>
                <p class="text-xs text-stone-500">{{ $types }}, maks. {{ Security::size($l['max_kb']) }} dan {{ number_format(ProductImportFormat::MAX_ROWS, 0, ',', '.') }} baris. Baris pertama berisi judul kolom.</p>

                @if (! $file)
                    <label x-data="{ over: false }"
                           x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
                           x-on:drop.prevent="over = false; if ($event.dataTransfer.files[0]) $wire.upload('file', $event.dataTransfer.files[0])"
                           x-bind:class="over && '!border-emerald-600 !bg-emerald-50'"
                           class="mt-4 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-stone-300 bg-stone-50 px-6 py-10 text-center transition hover:border-emerald-500">
                        <svg class="size-9 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                        <span class="text-sm font-semibold text-stone-800">Tarik berkas ke sini atau <span class="text-emerald-800 underline">pilih dari komputer</span></span>
                        <span class="text-xs text-stone-500">{{ $types }}</span>
                        <input type="file" wire:model="file" accept="{{ $accept }}" class="sr-only">
                        <span wire:loading wire:target="file" class="text-xs font-medium text-emerald-800">Mengunggah…</span>
                    </label>
                @else
                    <div class="mt-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-white text-xs font-bold text-emerald-800 uppercase ring-1 ring-emerald-200">{{ $file->getClientOriginalExtension() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $file->getClientOriginalName() }}</span>
                            <span class="block text-xs text-stone-600">{{ Security::size((int) ceil($file->getSize() / 1024)) }}</span>
                        </span>
                        <button type="button" wire:click="removeFile" class="rounded-lg px-2 py-1 text-sm font-medium text-stone-600 hover:bg-white">Ganti</button>
                    </div>
                @endif
                @error('file')
                    <p class="mt-2 flex items-start gap-1.5 text-sm text-red-600" role="alert">
                        <svg class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
                        {{ $message }}
                    </p>
                @enderror
            </section>

            {{-- Cara menyimpan --}}
            <section class="space-y-3 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="up-mode">
                <h2 id="up-mode" class="font-semibold">Cara menyimpan</h2>
                <fieldset class="grid gap-2 sm:grid-cols-3">
                    <legend class="sr-only">Cara menyimpan baris</legend>
                    @foreach (ProductImportFormat::MODES as $key => [$label, $hint])
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-stone-300 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" wire:model.live="mode" value="{{ $key }}" class="mt-0.5 accent-emerald-700" @checked($mode === $key)>
                            <span><span class="block font-medium">{{ $label }}</span><span class="block text-xs text-stone-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
                @if ($mode !== 'tambah')
                    <label class="flex items-start gap-2.5 text-sm">
                        <input type="checkbox" wire:model.live="updateStock" class="mt-0.5 size-4 accent-emerald-700" @checked($updateStock)>
                        <span><span class="font-medium">Perbarui juga stok barang yang sudah ada</span>
                            <span class="block text-xs text-stone-500">Selisihnya dicatat sebagai penyesuaian stok. Bila tidak dicentang, kolom stok hanya dipakai untuk barang baru.</span></span>
                    </label>
                @endif
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ route('barang.kategori') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="upload,file" @disabled(! $file)
                        class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Unggah & periksa</button>
            </div>
        </form>

        {{-- Format kolom --}}
        <aside class="h-fit space-y-4" aria-labelledby="fmt-title">
            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white">
                <div class="flex items-center justify-between gap-2 px-4 pt-4">
                    <h2 id="fmt-title" class="font-semibold">Kolom berkas</h2>
                    <div class="flex gap-1.5 text-xs">
                        @foreach (['csv' => 'CSV', 'xlsx' => 'Excel'] as $fmt => $label)
                            <a href="{{ route('barang.impor.templat', $fmt) }}" class="rounded-lg border border-emerald-700 px-2 py-1 font-semibold text-emerald-800 hover:bg-emerald-50">Templat {{ $label }}</a>
                        @endforeach
                    </div>
                </div>
                <dl class="mt-3 divide-y divide-stone-100 text-sm">
                    @foreach (ProductImportFormat::COLUMNS as $col => [$required, $example, $hint])
                        <div class="px-4 py-2">
                            <dt class="flex items-center gap-2">
                                <code class="font-mono text-xs font-semibold text-stone-800">{{ $col }}</code>
                                @if ($required)<span class="rounded bg-red-50 px-1.5 text-[10px] font-semibold text-red-700 uppercase">wajib</span>@endif
                                <span class="ml-auto font-mono text-xs text-stone-500">{{ $example }}</span>
                            </dt>
                            <dd class="text-xs text-stone-500">{{ $hint }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
            <p class="text-xs text-stone-500">Berkas Excel bermakro (.xlsm), berkas berisi skrip, atau melebihi batas ukuran ditolak otomatis. Batas dapat diubah Admin di Pengaturan → Keamanan & retensi.</p>
        </aside>
    </div>
</div>
