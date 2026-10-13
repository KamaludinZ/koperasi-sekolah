@use('App\Livewire\Barang\ImportPreview')
@use('App\Support\ProductImportFormat')
@php
    $imp = $this->import;
    $c = $this->counts;
    $rp = fn ($v) => is_numeric($v) ? 'Rp'.number_format((int) $v, 0, ',', '.') : $v;
    $money = ['harga_jual', 'harga_modal'];
    $tone = [
        'baru' => ['Baru', 'bg-emerald-100 text-emerald-800'],
        'perbarui' => ['Diperbarui', 'bg-sky-100 text-sky-800'],
        'lewati' => ['Dilewati', 'bg-stone-200 text-stone-600'],
        'masalah' => ['Bermasalah', 'bg-red-100 text-red-800'],
    ];
    $labels = ['kode' => 'Kode', 'nama' => 'Nama', 'kategori' => 'Kategori', 'harga_jual' => 'Harga jual', 'harga_modal' => 'Harga modal',
        'stok' => 'Stok', 'batas_minimum' => 'Batas min.', 'satuan' => 'Satuan', 'aktif' => 'Aktif'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-stone-500"><a href="{{ route('barang.kategori') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Kategori & impor</a> / <a href="{{ route('barang.impor') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Impor barang</a> / Pratinjau</p>
                <h1 class="truncate text-lg font-semibold">{{ $imp['file_name'] }}</h1>
                <p class="text-xs text-stone-500">{{ ProductImportFormat::MODES[$imp['mode'] ?? 'semua'][0] }}@if ($imp['update_stock'] ?? false) · stok barang lama ikut diperbarui @endif · diunggah {{ $imp['user'] }}, {{ \Illuminate\Support\Carbon::parse($imp['created_at'])->translatedFormat('d M Y H:i') }}</p>
            </div>
        </div>
        <x-barang.nav active="barang.kategori" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        {{-- Langkah --}}
        <ol class="flex flex-wrap items-center gap-2 text-sm" aria-label="Langkah impor">
            @foreach (['Unggah berkas', 'Periksa baris', 'Simpan'] as $i => $step)
                <li class="flex items-center gap-2">
                    <span @class(['grid size-6 place-items-center rounded-full text-xs font-bold', 'bg-emerald-700 text-white' => $i <= 1, 'bg-stone-200 text-stone-500' => $i > 1])>{{ $i === 0 ? '✓' : $i + 1 }}</span>
                    <span @class(['font-medium', 'text-emerald-900' => $i <= 1, 'text-stone-500' => $i > 1])>{{ $step }}</span>
                    @if ($i < 2)<span class="mx-1 h-px w-6 bg-stone-300" aria-hidden="true"></span>@endif
                </li>
            @endforeach
        </ol>

        @foreach ($imp['warnings'] as $w)
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $w }}</p>
        @endforeach

        @if ($this->finished)
            <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">Impor ini sudah disimpan: {{ $imp['created'] }} baru, {{ $imp['updated'] }} diperbarui, {{ $imp['skipped'] }} dilewati.</p>
        @endif

        {{-- Ringkasan + saringan --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5" role="tablist" aria-label="Saring baris">
            @foreach (ImportPreview::FILTERS as $key => $label)
                <button type="button" role="tab" wire:click="setFilter('{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                        @class(['rounded-xl border px-4 py-3 text-left transition',
                                'border-emerald-600 bg-white ring-2 ring-emerald-600/20' => $filter === $key,
                                'border-red-200 bg-red-50 hover:border-red-400' => $filter !== $key && $key === 'masalah' && $c['masalah'] > 0,
                                'border-stone-200 bg-white hover:border-stone-300' => $filter !== $key && ! ($key === 'masalah' && $c['masalah'] > 0)])>
                    <span class="block text-xs text-stone-500">{{ $key === 'semua' ? 'Semua baris' : $label }}</span>
                    <span @class(['block text-2xl font-bold tabular-nums', 'text-red-700' => $key === 'masalah' && $c['masalah'] > 0])>{{ $c[$key] }}</span>
                </button>
            @endforeach
        </div>

        @if ($c['masalah'] > 0 && ! $this->finished)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-900" role="alert">
                <p>{{ $c['masalah'] }} baris perlu diperbaiki sebelum disimpan. Klik <span class="font-semibold">Perbaiki</span> pada baris, atau lewati semuanya.</p>
                <button type="button" wire:click="skipProblems" class="font-semibold text-red-800 hover:underline">Lewati baris bermasalah</button>
            </div>
        @endif

        {{-- Baris --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Baris berkas">
            <ul class="divide-y divide-stone-100">
                @forelse ($this->visible as $r)
                    @php [$label, $badge] = $tone[$r['action']]; $v = $r['values']; @endphp
                    <li wire:key="row-{{ $r['line'] }}" @class(['px-4 py-3', 'bg-red-50/40' => $r['action'] === 'masalah', 'opacity-70' => $r['action'] === 'lewati'])>
                        @if ($editing === $r['line'])
                            <form wire:submit="saveRow" class="space-y-3">
                                <p class="text-sm font-semibold">Perbaiki baris {{ $r['line'] }}</p>
                                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                    @foreach ($labels as $col => $text)
                                        <label class="block text-xs">
                                            <span class="font-medium text-stone-600">{{ $text }}</span>
                                            <input type="text" wire:model="draft.{{ $col }}" value="{{ $draft[$col] ?? '' }}"
                                                   @class(['mt-1 w-full rounded-lg border px-2.5 py-1.5 text-sm', 'border-red-400 bg-red-50' => isset($r['errors'][$col]), 'border-stone-300' => ! isset($r['errors'][$col])])>
                                            @isset($r['errors'][$col])<span class="mt-0.5 block text-red-600">{{ $r['errors'][$col] }}</span>@endisset
                                        </label>
                                    @endforeach
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" wire:click="cancelEdit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                                    <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">Periksa ulang</button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-start gap-3">
                                <span class="w-14 shrink-0 pt-0.5 text-xs text-stone-400 tabular-nums">Baris {{ $r['line'] }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                        <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $badge }}">{{ $label }}</span>
                                        <span class="font-mono text-xs font-semibold">{{ $v['kode'] ?: '—' }}</span>
                                        <span class="font-medium">{{ $v['nama'] ?: '(tanpa nama)' }}</span>
                                        <span class="text-xs text-stone-500">{{ $v['kategori'] }} · {{ $rp($v['harga_jual']) }}@if ($v['stok'] !== '') · stok {{ $v['stok'] }} {{ $v['satuan'] ?: 'pcs' }}@endif</span>
                                    </p>
                                    @if ($r['errors'])
                                        <ul class="mt-1 space-y-0.5 text-xs text-red-700">
                                            @foreach ($r['errors'] as $col => $msg)<li><span class="font-medium">{{ $labels[$col] }}:</span> {{ $msg }}</li>@endforeach
                                        </ul>
                                    @elseif ($r['changes'])
                                        <p class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                            @foreach ($r['changes'] as $col => [$from, $to])
                                                <span class="text-stone-600">{{ $labels[$col] }}:
                                                    <span class="text-red-700 line-through decoration-red-300">{{ in_array($col, $money, true) ? $rp($from) : $from }}</span>
                                                    → <span class="font-semibold text-emerald-800">{{ in_array($col, $money, true) ? $rp($to) : $to }}</span></span>
                                            @endforeach
                                        </p>
                                    @elseif ($r['reason'])
                                        <p class="mt-1 text-xs text-stone-500">{{ $r['reason'] }}</p>
                                    @endif
                                </div>
                                @unless ($this->finished)
                                    <div class="flex shrink-0 gap-1">
                                        @if (! $r['skipped'])
                                            <button type="button" wire:click="edit({{ $r['line'] }})" class="rounded-lg px-2 py-1 text-sm font-medium text-emerald-800 hover:bg-emerald-50">{{ $r['errors'] ? 'Perbaiki' : 'Ubah' }}</button>
                                        @endif
                                        <button type="button" wire:click="toggleSkip({{ $r['line'] }})" class="rounded-lg px-2 py-1 text-sm font-medium text-stone-600 hover:bg-stone-100">{{ $r['skipped'] ? 'Sertakan' : 'Lewati' }}</button>
                                    </div>
                                @endunless
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-stone-400">Tidak ada baris pada saringan ini.</li>
                @endforelse
            </ul>
        </section>

        @unless ($this->finished)
            <div class="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
                <p class="text-sm text-stone-600"><span class="font-semibold text-emerald-800">{{ $c['baru'] }} baru</span> · <span class="font-semibold text-sky-800">{{ $c['perbarui'] }} diperbarui</span> · {{ $c['lewati'] }} dilewati
                    @if ($c['masalah']) · <span class="font-semibold text-red-700">{{ $c['masalah'] }} bermasalah</span>@endif</p>
                <div class="flex gap-2">
                    <a href="{{ route('barang.impor') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Unggah ulang</a>
                    <button type="button" wire:click="commit" wire:loading.attr="disabled" wire:target="commit" @disabled($c['masalah'] > 0 || $c['baru'] + $c['perbarui'] === 0)
                            class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Simpan {{ $c['baru'] + $c['perbarui'] }} barang</button>
                </div>
            </div>
        @endunless
    </div>
</div>
