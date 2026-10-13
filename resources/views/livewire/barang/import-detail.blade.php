@use('App\Livewire\Barang\Catalog')
@use('App\Livewire\Barang\ImportHistory')
@php
    $imp = $this->import;
    $d = $this->detail;
    [$label, $tone] = Catalog::IMPORT_STATUSES[$imp['status']];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-stone-500"><a href="{{ route('barang.kategori') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Kategori & impor</a> / <a href="{{ route('barang.impor.riwayat') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Riwayat impor</a> / Rincian</p>
                <h1 class="flex flex-wrap items-center gap-2 text-lg font-semibold"><span class="truncate">{{ $imp['file_name'] }}</span>
                    <span class="rounded-md px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span></h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-data x-on:click="$copy(@js($this->summaryText), 'Ringkasan impor disalin.')"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>
                    Salin ringkasan
                </button>
                <div class="flex overflow-hidden rounded-xl border border-stone-300 bg-white text-sm font-medium text-stone-700" role="group" aria-label="Unduh laporan hasil">
                    <button type="button" wire:click="download('csv')" class="px-3 py-2 hover:bg-stone-50">Unduh CSV</button>
                    <button type="button" wire:click="download('xlsx')" class="border-l border-stone-300 px-3 py-2 hover:bg-stone-50">Excel</button>
                </div>
            </div>
        </div>
        <x-barang.nav active="barang.kategori" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        @if ($imp['status'] === 'pratinjau')
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                <p>Impor ini belum disimpan dan menunggu diperiksa.</p>
                <a href="{{ route('barang.impor.pratinjau', $imp['id']) }}" wire:navigate class="font-semibold text-sky-800 hover:underline">Buka pratinjau →</a>
            </div>
        @endif
        @if ($d['file_error'])
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                <p class="font-semibold">Berkas tidak dapat dibaca</p>
                <p class="mt-0.5">{{ $d['file_error'] }}</p>
                <a href="{{ route('barang.impor.templat', 'csv') }}" class="mt-2 inline-block font-semibold text-red-800 hover:underline">Unduh templat CSV</a>
            </div>
        @endif

        @foreach ($imp['warnings'] as $w)
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $w }}</p>
        @endforeach

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            @foreach ([['Baris dibaca', $imp['total_rows'], ''], ['Baru', $imp['created'], 'text-emerald-800'], ['Diperbarui', $imp['updated'], 'text-sky-800'], ['Dilewati', $imp['skipped'], 'text-stone-600'], ['Gagal', $imp['failed'], $imp['failed'] ? 'text-red-700' : '']] as [$l, $n, $c])
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">{{ $l }}</p>
                    <p class="text-2xl font-bold tabular-nums {{ $c }}">{{ $n }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-5 xl:grid-cols-[20rem_minmax(0,1fr)]">
            <dl class="h-fit space-y-2 rounded-xl border border-stone-200 bg-white p-4 text-sm">
                <div><dt class="text-xs text-stone-500">Cara menyimpan</dt><dd>{{ $modes[$imp['mode'] ?? 'semua'][0] }}@if ($imp['update_stock'] ?? false) · stok ikut diperbarui @endif</dd></div>
                <div><dt class="text-xs text-stone-500">Diunggah</dt><dd>{{ $imp['user'] }} · {{ \Illuminate\Support\Carbon::parse($imp['created_at'])->translatedFormat('l, d F Y H:i') }}</dd></div>
                @if ($imp['committed_by'])<div><dt class="text-xs text-stone-500">Disimpan oleh</dt><dd>{{ $imp['committed_by'] }}</dd></div>@endif
                <div><dt class="text-xs text-stone-500">Selesai</dt><dd>{{ $imp['finished_at'] ? \Illuminate\Support\Carbon::parse($imp['finished_at'])->translatedFormat('H:i').' ('.ImportHistory::duration($imp).')' : '—' }}</dd></div>
                <div><dt class="text-xs text-stone-500">Format</dt><dd class="uppercase">{{ $imp['format'] }}</dd></div>
            </dl>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="prob-title">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                    <div>
                        <h2 id="prob-title" class="font-semibold">Baris tidak tersimpan</h2>
                        <p class="text-xs text-stone-500">Perbaiki di berkas aslinya, lalu impor ulang baris-baris ini.</p>
                    </div>
                    @if ($d['problems'])
                        <div class="flex gap-2">
                            <button type="button" x-data x-on:click="$copy(@js($this->problemsTsv), 'Baris bermasalah disalin — tempel ke Excel.')"
                                    class="rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Salin baris</button>
                            <button type="button" wire:click="downloadProblems" class="rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Unduh baris bermasalah</button>
                        </div>
                    @endif
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr><th class="px-4 py-2 font-medium">Baris</th><th class="px-3 py-2 font-medium">Kode</th><th class="px-3 py-2 font-medium">Nama</th><th class="px-3 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">Alasan</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($d['problems'] as $p)
                                <tr>
                                    <td class="px-4 py-2 text-stone-500 tabular-nums">{{ $p['line'] }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $p['kode'] ?: '—' }}</td>
                                    <td class="px-3 py-2">{{ $p['nama'] ?: '—' }}</td>
                                    <td class="px-3 py-2"><span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold', 'bg-red-100 text-red-800' => $p['status'] === 'gagal', 'bg-stone-200 text-stone-600' => $p['status'] !== 'gagal'])>{{ ucfirst($p['status']) }}</span></td>
                                    <td class="px-4 py-2 text-xs text-stone-600">{{ $p['message'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-stone-400">{{ $d['file_error'] ? 'Tidak ada baris yang terbaca.' : 'Semua baris berhasil disimpan.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
