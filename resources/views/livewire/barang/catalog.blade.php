@use('App\Livewire\Barang\Catalog')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $s = $this->summary;
    $maxValue = max(1, collect($this->categories)->max('stock_value'));
    $importUrl = Catalog::link('barang.impor');
    $soon = 'cursor-not-allowed opacity-60';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Barang & Stok</h1>
                <p class="text-xs text-stone-500">Kelompokkan barang per kategori dan tambahkan banyak barang sekaligus dari berkas.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
                @if ($importUrl)
                    <a href="{{ $importUrl }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 20h16"/></svg>
                        Impor barang
                    </a>
                @else
                    <span class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white {{ $soon }}" title="Segera hadir">Impor barang</span>
                @endif
            </div>
        </div>
        <x-barang.nav active="barang.kategori" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Kategori</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['categories'] }}</p>
                <p class="text-xs text-stone-500">{{ $s['empty'] ? $s['empty'].' belum berisi barang' : 'semua berisi barang' }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Barang terkelompok</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['products'] }}</p>
                <p class="text-xs text-stone-500">nilai stok {{ $rp($s['stock_value']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Impor 30 hari</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['imports_30d'] }}</p>
                <p class="text-xs text-stone-500">{{ $s['imported_rows_30d'] }} baris tersimpan</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $s['failed_rows_30d'] > 0, 'border-stone-200 bg-white' => $s['failed_rows_30d'] === 0])>
                <p class="text-xs text-stone-500">Baris gagal diimpor</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['failed_rows_30d'] }}</p>
                <p class="text-xs text-stone-500">30 hari terakhir</p>
            </div>
        </div>

        @if ($p = $s['pending'])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950" role="status">
                <p><span class="font-semibold">{{ $p['file_name'] }}</span> diunggah {{ $p['user'] }} dan menunggu diperiksa: {{ $p['total_rows'] }} baris, {{ $p['failed'] }} perlu diperbaiki.</p>
                @if ($url = Catalog::link('barang.impor.pratinjau', ['import' => $p['id']]))
                    <a href="{{ $url }}" wire:navigate class="font-semibold text-sky-800 hover:underline">Lanjutkan pemeriksaan →</a>
                @else
                    <span class="text-xs text-sky-700">Pratinjau segera hadir</span>
                @endif
            </div>
        @endif

        <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_24rem]">
            {{-- Kategori --}}
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="cat-title">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                    <div>
                        <h2 id="cat-title" class="font-semibold">Kategori barang</h2>
                        <p class="text-xs text-stone-500">Urutan ini dipakai di layar kasir dan laporan.</p>
                    </div>
                    @if ($this->canManage)
                        <div class="flex gap-2">
                            @if ($reordering)
                                <button type="button" wire:click="cancelReorder" class="rounded-xl px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                                <button type="button" wire:click="saveOrder" class="rounded-xl bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Simpan urutan</button>
                            @else
                                <button type="button" wire:click="startReorder" class="rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Atur urutan</button>
                                <button type="button" wire:click="create" class="rounded-xl border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Tambah kategori</button>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr>
                                <th class="w-10 px-4 py-2 font-medium">#</th>
                                <th class="px-2 py-2 font-medium">Kategori</th>
                                <th class="px-3 py-2 text-right font-medium">Barang</th>
                                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">Stok menipis</th>
                                <th class="px-4 py-2 font-medium">Nilai stok</th>
                                @if ($this->canManage)<th class="px-4 py-2"><span class="sr-only">Aksi</span></th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($this->rows as $c)
                                <tr wire:key="cat-{{ $c['id'] }}" @class(['bg-amber-50/40' => $reordering])>
                                    <td class="px-4 py-2.5 text-stone-400 tabular-nums">{{ $c['position'] }}</td>
                                    <td class="px-2 py-2.5">
                                        <span class="flex items-center gap-2 font-medium">
                                            <span class="size-2.5 shrink-0 rounded-full {{ Catalog::COLORS[$c['color']] ?? 'bg-stone-400' }}" aria-hidden="true"></span>
                                            <a href="{{ route('barang', ['category' => $c['name']]) }}" wire:navigate class="hover:text-emerald-800 hover:underline">{{ $c['name'] }}</a>
                                        </span>
                                        <span class="block pl-4.5 text-xs text-stone-500">diubah {{ \Illuminate\Support\Carbon::parse($c['updated_at'])->diffForHumans() }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">
                                        @if ($c['products'] === 0)<span class="text-stone-400">kosong</span>@else{{ $c['products'] }}@endif
                                    </td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">
                                        @if ($c['low_stock'])<span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-900">{{ $c['low_stock'] }}</span>@else<span class="text-stone-400">—</span>@endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="flex items-center gap-2">
                                            <span class="h-1.5 w-24 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                                                <span class="block h-full rounded-full bg-emerald-600" style="width: {{ round($c['stock_value'] / $maxValue * 100) }}%"></span>
                                            </span>
                                            <span class="text-xs text-stone-600 tabular-nums">{{ $rp($c['stock_value']) }}</span>
                                        </span>
                                    </td>
                                    @if ($this->canManage)
                                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                            @if ($reordering)
                                                <button type="button" wire:click="move({{ $c['id'] }}, -1)" @disabled($loop->first) aria-label="Naikkan {{ $c['name'] }}"
                                                        class="rounded-lg border border-stone-300 p-1.5 text-stone-600 hover:bg-white disabled:opacity-30"><svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
                                                <button type="button" wire:click="move({{ $c['id'] }}, 1)" @disabled($loop->last) aria-label="Turunkan {{ $c['name'] }}"
                                                        class="rounded-lg border border-stone-300 p-1.5 text-stone-600 hover:bg-white disabled:opacity-30"><svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                                            @else
                                                <button type="button" wire:click="edit({{ $c['id'] }})" class="rounded-lg px-2 py-1 text-sm font-medium text-emerald-800 hover:bg-emerald-50">Ubah</button>
                                                <button type="button" wire:click="confirmDelete({{ $c['id'] }})" class="rounded-lg px-2 py-1 text-sm font-medium text-red-700 hover:bg-red-50">Hapus</button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Impor --}}
            <aside class="grid content-start gap-4 lg:grid-cols-2 2xl:grid-cols-1" aria-labelledby="imp-title">
                <section class="relative isolate overflow-hidden rounded-xl bg-emerald-950 p-5 text-emerald-50">
                    <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
                    <h2 id="imp-title" class="font-display text-xl font-bold text-white">Impor barang dari berkas</h2>
                    <p class="mt-1 text-sm text-emerald-200">Tambah atau perbarui banyak barang sekaligus dari CSV atau Excel.</p>
                    <ol class="mt-4 space-y-2.5 text-sm">
                        @foreach (['Unduh templat, isi kode, nama, kategori, harga, dan stok.', 'Unggah berkasnya — setiap baris diperiksa dulu.', 'Perbaiki baris bermasalah, lalu simpan.'] as $i => $step)
                            <li class="flex gap-2.5">
                                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-amber-400 text-xs font-bold text-emerald-950">{{ $i + 1 }}</span>
                                <span class="text-emerald-100">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (['csv' => 'Templat CSV', 'xlsx' => 'Templat Excel'] as $fmt => $label)
                            @if ($url = Catalog::link('barang.impor.templat', ['format' => $fmt]))
                                <a href="{{ $url }}" class="rounded-lg bg-white/10 px-3 py-1.5 text-sm font-medium text-white ring-1 ring-white/20 hover:bg-white/20">{{ $label }}</a>
                            @else
                                <span class="rounded-lg bg-white/5 px-3 py-1.5 text-sm text-emerald-300 ring-1 ring-white/10" title="Segera hadir">{{ $label }}</span>
                            @endif
                        @endforeach
                    </div>
                </section>

                <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="hist-title">
                    <div class="flex items-center justify-between px-4 pt-4">
                        <h2 id="hist-title" class="font-semibold">Impor terbaru</h2>
                        @if ($url = Catalog::link('barang.impor.riwayat'))
                            <a href="{{ $url }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">Semua riwayat →</a>
                        @endif
                    </div>
                    <ul class="mt-2 divide-y divide-stone-100">
                        @forelse ($this->imports as $imp)
                            @php [$label, $tone] = Catalog::IMPORT_STATUSES[$imp['status']]; @endphp
                            <li class="px-4 py-3" wire:key="imp-{{ $imp['id'] }}">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="min-w-0 truncate text-sm font-medium" title="{{ $imp['file_name'] }}">{{ $imp['file_name'] }}</p>
                                    <span class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $tone }}">{{ $label }}</span>
                                </div>
                                <p class="text-xs text-stone-500">
                                    {{ $imp['user'] }} · {{ \Illuminate\Support\Carbon::parse($imp['created_at'])->translatedFormat('d M, H:i') }}
                                    @if ($imp['status'] === 'gagal') · berkas tidak terbaca
                                    @else · {{ $imp['total_rows'] }} baris
                                        @if ($imp['created']) · <span class="text-emerald-800">{{ $imp['created'] }} baru</span>@endif
                                        @if ($imp['updated']) · <span class="text-sky-800">{{ $imp['updated'] }} diperbarui</span>@endif
                                        @if ($imp['failed']) · <span class="text-red-700">{{ $imp['failed'] }} bermasalah</span>@endif
                                    @endif
                                </p>
                            </li>
                        @empty
                            <li class="px-4 py-8 text-center text-sm text-stone-400">Belum pernah mengimpor barang.</li>
                        @endforelse
                    </ul>
                </section>
            </aside>
        </div>
    </div>
    {{-- Form kategori --}}
    @if ($formOpen)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="cat-form-title"
             x-data x-on:keydown.escape.window="$wire.closeForm()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeForm"></div>
            <form wire:submit="save" class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" novalidate>
                <h2 id="cat-form-title" class="font-semibold">{{ $editId ? 'Ubah kategori' : 'Kategori baru' }}</h2>
                <label class="mt-4 block text-sm">
                    <span class="font-medium">Nama kategori</span>
                    <input type="text" wire:model="name" maxlength="50" autofocus placeholder="mis. Perlengkapan Ibadah"
                           @class(['mt-1 w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-4', 'border-red-400 focus:ring-red-500/15' => $errors->has('name'), 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15' => ! $errors->has('name')])>
                    @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <fieldset class="mt-4">
                    <legend class="text-sm font-medium">Warna penanda</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (Catalog::COLORS as $key => $class)
                            <label class="cursor-pointer rounded-full p-1 ring-2 ring-transparent has-checked:ring-emerald-700">
                                <input type="radio" wire:model="color" value="{{ $key }}" class="sr-only" @checked($color === $key)>
                                <span class="block size-6 rounded-full {{ $class }}" title="{{ $key }}"></span>
                                <span class="sr-only">{{ $key }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="rounded-xl px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Simpan</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Hapus kategori --}}
    @if ($d = $this->deleting)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="cat-del-title"
             x-data x-on:keydown.escape.window="$wire.cancelDelete()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="cancelDelete"></div>
            <form wire:submit="delete" class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" novalidate>
                <h2 id="cat-del-title" class="font-semibold">Hapus kategori {{ $d['name'] }}?</h2>
                @if ($d['products'] > 0)
                    <p class="mt-1 text-sm text-stone-600">Kategori ini berisi <span class="font-semibold">{{ $d['products'] }} barang</span>. Pindahkan barangnya ke:</p>
                    <select wire:model="moveTo" aria-label="Kategori tujuan"
                            @class(['mt-2 w-full rounded-lg border bg-white px-3 py-2 text-sm', 'border-red-400' => $errors->has('moveTo'), 'border-stone-300' => ! $errors->has('moveTo')])>
                        <option value="">Pilih kategori tujuan…</option>
                        @foreach ($this->categories as $c)
                            @if ($c['id'] !== $d['id'])<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endif
                        @endforeach
                    </select>
                    @error('moveTo') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                @else
                    <p class="mt-1 text-sm text-stone-600">Kategori ini kosong.</p>
                @endif
                <p class="mt-3 text-xs text-stone-500">Kategori yang dihapus dapat dipulihkan Admin dari Audit → Data terhapus selama masa simpan.</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelDelete" class="rounded-xl px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="delete" class="rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Hapus kategori</button>
                </div>
            </form>
        </div>
    @endif
</div>
