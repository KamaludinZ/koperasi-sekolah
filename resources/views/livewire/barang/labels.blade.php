@use('App\Support\LabelFormats')
@php
    $f = LabelFormats::get($format);
    $sheet = $this->sheet;
    $zoom = $f['page_w'] > 100 ? 0.55 : 2.2; // pratinjau: A4 diperkecil, label kecil diperbesar
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('barang.barcode') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Barcode & label</a> / Cetak label</p>
                <h1 class="text-lg font-semibold">Cetak label barcode</h1>
            </div>
        </div>
        <x-barang.nav active="barang.barcode" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div class="min-w-0 space-y-5">
            <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="lbl-items">
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                    <h2 id="lbl-items" class="font-semibold">Barang</h2>
                    @if ($rows)
                        <div class="flex gap-2">
                            <button type="button" wire:click="fillSheet" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Penuhi 1 lembar</button>
                            <button type="button" wire:click="clear" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Kosongkan</button>
                        </div>
                    @endif
                </div>
                <div class="relative px-5 pt-3">
                    <label class="sr-only" for="lbl-search">Cari barang</label>
                    <input id="lbl-search" type="search" wire:model.live.debounce.250ms="search" autocomplete="off" placeholder="Cari kode, barcode, atau nama barang untuk ditambahkan…"
                           class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    @if ($this->results)
                        <ul class="absolute inset-x-5 top-full z-10 mt-1 max-h-72 overflow-y-auto rounded-lg border border-stone-200 bg-white shadow-lg">
                            @foreach ($this->results as $p)
                                <li><button type="button" wire:click="add({{ $p['id'] }})" class="w-full px-3 py-2 text-left text-sm hover:bg-emerald-50"><span class="font-mono text-xs">{{ $p['code'] }}</span> {{ $p['name'] }}</button></li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <p class="mt-1 text-xs text-stone-500">Barang tidak ditemukan.</p>
                    @endif
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500"><tr><th class="px-5 py-2 font-medium">Barang</th><th class="px-3 py-2 font-medium">Barcode</th><th class="px-3 py-2 font-medium">Salinan</th><th class="px-5 py-2"><span class="sr-only">Hapus</span></th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($rows as $i => $r)
                                @php $p = $this->products[$r['id']] ?? null; @endphp
                                @if ($p)
                                    <tr wire:key="lbl-{{ $r['id'] }}">
                                        <td class="px-5 py-2"><span class="font-mono text-xs">{{ $p['code'] }}</span> {{ $p['name'] }} <span class="block text-xs text-stone-500">@rupiah($p['price'])</span></td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $p['barcode'] }}</td>
                                        <td class="px-3 py-2"><input type="number" min="1" max="{{ LabelFormats::MAX_COPIES }}" wire:model.live.debounce.400ms="rows.{{ $i }}.copies" aria-label="Salinan {{ $p['name'] }}"
                                                                     class="w-20 rounded-lg border border-stone-300 px-2 py-1 text-sm tabular-nums"></td>
                                        <td class="px-5 py-2 text-right"><button type="button" wire:click="remove({{ $i }})" class="text-sm font-medium text-red-700 hover:underline" aria-label="Hapus {{ $p['name'] }}">Hapus</button></td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="4" class="px-5 py-8 text-center text-stone-400">Belum ada barang. Cari di atas, atau pilih barang di halaman <a href="{{ route('barang.barcode') }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Barcode & label</a>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-3 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="lbl-format">
                <h2 id="lbl-format" class="font-semibold">Kertas & isi label</h2>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (LabelFormats::FORMATS as $key => $def)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 px-3 py-2 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" wire:model.live="format" value="{{ $key }}" class="accent-emerald-700"> {{ $def[0] }}
                            @if ($key === LabelFormats::thermalForReceipt())<span class="ml-auto rounded bg-stone-100 px-1.5 text-[11px] text-stone-600">sesuai printer struk</span>@endif
                        </label>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="showName" class="accent-emerald-700"> Tampilkan nama barang</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="showPrice" class="accent-emerald-700"> Tampilkan harga</label>
                </div>
            </section>
        </div>

        <aside class="h-fit space-y-3 xl:sticky xl:top-4" aria-labelledby="lbl-preview">
            <div class="rounded-2xl border border-stone-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="lbl-preview" class="font-semibold">Pratinjau</h2>
                    <p class="text-sm text-stone-600"><strong class="tabular-nums">{{ $sheet['total'] }}</strong> label · <strong class="tabular-nums">{{ count($sheet['pages']) }}</strong> lembar</p>
                </div>
                @if ($sheet['truncated'])<p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Maksimal {{ LabelFormats::MAX_LABELS }} label sekali cetak; sisanya cetak terpisah.</p>@endif
                <div class="mt-3 flex justify-center overflow-hidden rounded-lg bg-stone-100 p-3">
                    @if ($sheet['pages'])
                        <div class="shadow ring-1 ring-stone-300" style="zoom: {{ $zoom }}">
                            @include('barang.partials.label-sheet', ['f' => $f, 'page' => $sheet['pages'][$previewPage] ?? $sheet['pages'][0], 'showName' => $showName, 'showPrice' => $showPrice])
                        </div>
                    @else
                        <p class="py-16 text-sm text-stone-400">Pratinjau muncul setelah barang ditambahkan.</p>
                    @endif
                </div>
                @if (($pages = count($sheet['pages'])) > 1)
                    <div class="mt-2 flex items-center justify-center gap-3 text-xs text-stone-500">
                        <button type="button" wire:click="previewTo({{ $previewPage - 1 }})" @disabled($previewPage === 0) class="rounded-md border border-stone-300 px-2 py-1 disabled:opacity-40" aria-label="Lembar sebelumnya">‹</button>
                        <span>Menampilkan lembar {{ min($previewPage, $pages - 1) + 1 }} dari {{ $pages }}.</span>
                        <button type="button" wire:click="previewTo({{ $previewPage + 1 }})" @disabled($previewPage >= $pages - 1) class="rounded-md border border-stone-300 px-2 py-1 disabled:opacity-40" aria-label="Lembar berikutnya">›</button>
                    </div>
                @endif
                @if ($url = $this->printUrl)
                    <a href="{{ $url }}" target="_blank" rel="noopener" data-print-labels class="mt-4 block rounded-xl bg-emerald-700 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-emerald-800">Cetak label</a>
                    <a href="{{ $this->pdfUrl }}" data-pdf-labels class="mt-2 block rounded-xl border border-stone-300 px-4 py-2.5 text-center text-sm font-medium text-stone-700 hover:bg-stone-50">Unduh PDF</a>
                @else
                    <span class="mt-4 block cursor-not-allowed rounded-xl bg-emerald-700 px-4 py-2.5 text-center text-sm font-semibold text-white opacity-50">Cetak label</span>
                @endif
                <p class="mt-2 text-xs text-stone-500">Saat mencetak, pilih ukuran kertas yang sama, skala 100%, tanpa header/footer.</p>
            </div>
        </aside>
    </div>
</div>
