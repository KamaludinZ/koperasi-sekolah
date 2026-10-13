@use('App\Livewire\Barang\Catalog')
@use('App\Livewire\Barang\ImportHistory')
@php $t = $this->totals; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('barang.kategori') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Kategori & impor</a> / Riwayat impor</p>
                <h1 class="text-lg font-semibold">Riwayat Impor Barang</h1>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
                <a href="{{ route('barang.impor') }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Impor baru</a>
            </div>
        </div>
        <x-barang.nav active="barang.kategori" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([['Proses impor', $t['imports'], ''], ['Barang baru', $t['created'], 'text-emerald-800'], ['Barang diperbarui', $t['updated'], 'text-sky-800'], ['Baris gagal', $t['failed'], $t['failed'] ? 'text-red-700' : '']] as [$label, $n, $tone])
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">{{ $label }}</p>
                    <p class="text-2xl font-bold tabular-nums {{ $tone }}">{{ $n }}</p>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="flex flex-wrap gap-1" role="group" aria-label="Status">
                @foreach (Catalog::IMPORT_STATUSES as $key => [$label])
                    <button type="button" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}"
                            @class(['rounded-full border px-3 py-1 text-sm font-medium', 'border-emerald-700 bg-emerald-800 text-white' => $status === $key, 'border-stone-300 bg-white text-stone-600 hover:border-stone-400' => $status !== $key])>
                        {{ $label }} <span class="tabular-nums opacity-70">{{ $t['by_status'][$key] }}</span>
                    </button>
                @endforeach
            </div>
            <label class="relative lg:ml-auto lg:w-72">
                <span class="sr-only">Cari impor</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama berkas atau pengunggah…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar impor">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Berkas</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Baris</th>
                            <th class="px-3 py-2 text-right font-medium">Baru</th>
                            <th class="px-3 py-2 text-right font-medium">Diperbarui</th>
                            <th class="px-3 py-2 text-right font-medium">Dilewati</th>
                            <th class="px-3 py-2 text-right font-medium">Gagal</th>
                            <th class="px-4 py-2 font-medium">Diunggah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->imports as $imp)
                            @php [$label, $tone] = Catalog::IMPORT_STATUSES[$imp['status']]; @endphp
                            <tr wire:key="ih-{{ $imp['id'] }}" class="hover:bg-stone-50">
                                <td class="px-4 py-2.5">
                                    <a href="{{ $imp['status'] === 'pratinjau' ? route('barang.impor.pratinjau', $imp['id']) : route('barang.impor.detail', $imp['id']) }}" wire:navigate
                                       class="font-medium text-emerald-800 hover:underline">{{ $imp['file_name'] }}</a>
                                    <span class="block text-xs text-stone-500 uppercase">{{ $imp['format'] }}</span>
                                </td>
                                <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $tone }}">{{ $label }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $imp['total_rows'] ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-right text-emerald-800 tabular-nums">{{ $imp['created'] ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-right text-sky-800 tabular-nums">{{ $imp['updated'] ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-right text-stone-500 tabular-nums">{{ $imp['skipped'] ?: '—' }}</td>
                                <td @class(['px-3 py-2.5 text-right tabular-nums', 'font-semibold text-red-700' => $imp['failed'] > 0, 'text-stone-400' => $imp['failed'] === 0])>{{ $imp['failed'] ?: '—' }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <span class="block">{{ $imp['user'] }}</span>
                                    <span class="text-xs text-stone-500">{{ \Illuminate\Support\Carbon::parse($imp['created_at'])->translatedFormat('d M Y, H:i') }}@if ($d = ImportHistory::duration($imp)) · {{ $d }}@endif</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-stone-400">Tidak ada impor yang cocok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
