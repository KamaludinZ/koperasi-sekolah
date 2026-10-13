@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $field = fn ($f) => 'rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
    $sup = $this->supplier;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('pengadaan.pesanan') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Pesanan pembelian</a> / Buat</p>
                <h1 class="text-lg font-semibold">Pesanan pembelian baru</h1>
            </div>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
        </div>
        <x-pengadaan.nav active="pengadaan.pesanan" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-5">
            {{-- Supplier --}}
            <section class="grid gap-4 rounded-2xl border border-stone-200 bg-white p-5 sm:grid-cols-2" aria-label="Supplier & jadwal">
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Supplier</span>
                    <select wire:model.live="supplierId" class="{{ $field('supplierId') }} w-full">
                        <option value="">Pilih supplier…</option>
                        @foreach ($this->suppliers as $s)<option value="{{ $s['id'] }}" @selected((string) $s['id'] === $supplierId)>{{ $s['name'] }}</option>@endforeach
                    </select>
                    @error('supplierId') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    @if ($sup)<span class="mt-1 block text-xs text-stone-500">{{ implode(', ', $sup['categories']) }} · {{ $sup['terms_days'] ? 'tempo '.$sup['terms_days'].' hari' : 'tunai' }}@if ($sup['note']) · {{ $sup['note'] }}@endif</span>@endif
                </label>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Perkiraan tiba</span>
                    <input type="date" wire:model="expectedAt" value="{{ $expectedAt }}" min="{{ now()->toDateString() }}" class="{{ $field('expectedAt') }} w-full">
                    @error('expectedAt') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-stone-700">Catatan untuk supplier <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="note" value="{{ $note }}" maxlength="200" placeholder="mis. Antar sebelum jam istirahat" class="{{ $field('note') }} w-full">
                </label>
            </section>

            {{-- Barang --}}
            <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="ol-title">
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                    <h2 id="ol-title" class="font-semibold">Barang dipesan</h2>
                    <button type="button" wire:click="fillLowStock" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100">Isi dari stok menipis</button>
                </div>
                <div class="relative px-5 pt-3">
                    <label class="sr-only" for="po-search">Cari barang</label>
                    <input id="po-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Cari kode atau nama barang untuk ditambahkan…" autocomplete="off"
                           class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    @if ($this->results)
                        <ul class="absolute inset-x-5 top-full z-10 mt-1 max-h-72 overflow-y-auto rounded-lg border border-stone-200 bg-white shadow-lg">
                            @foreach ($this->results as $p)
                                <li><button type="button" wire:click="addProduct({{ $p['id'] }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-emerald-50">
                                    <span><span class="font-mono text-xs">{{ $p['code'] }}</span> {{ $p['name'] }}</span>
                                    <span class="text-xs text-stone-500">stok {{ $p['stock'] }} · {{ $rp($p['cost_price']) }}</span>
                                </button></li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <p class="mt-1 text-xs text-stone-500">Barang tidak ditemukan.</p>
                    @endif
                </div>
                @error('lines') <p class="px-5 pt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr><th class="px-5 py-2 font-medium">Barang</th><th class="px-3 py-2 font-medium">Jumlah</th><th class="px-3 py-2 font-medium">Harga beli / satuan</th><th class="px-3 py-2 text-right font-medium">Subtotal</th><th class="px-5 py-2"><span class="sr-only">Hapus</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($lines as $i => $l)
                                <tr wire:key="line-{{ $l['product_id'] }}">
                                    <td class="px-5 py-2"><span class="font-mono text-xs">{{ $l['code'] }}</span> {{ $l['name'] }}</td>
                                    <td class="px-3 py-2">
                                        <span class="flex items-center gap-1.5"><input type="number" min="1" wire:model.live.debounce.400ms="lines.{{ $i }}.qty" value="{{ $l['qty'] }}" aria-label="Jumlah {{ $l['name'] }}" class="{{ $field("lines.$i.qty") }} w-20 tabular-nums"><span class="text-xs text-stone-500">{{ $l['unit'] }}</span></span>
                                        @error("lines.$i.qty") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" min="0" step="100" wire:model.live.debounce.400ms="lines.{{ $i }}.cost" value="{{ $l['cost'] }}" aria-label="Harga beli {{ $l['name'] }}" class="{{ $field("lines.$i.cost") }} w-28 tabular-nums">
                                        @error("lines.$i.cost") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $rp(max(0, (int) $l['qty']) * max(0, (int) $l['cost'])) }}</td>
                                    <td class="px-5 py-2 text-right"><button type="button" wire:click="removeLine({{ $i }})" class="text-sm font-medium text-red-700 hover:underline" aria-label="Hapus {{ $l['name'] }}">Hapus</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-8 text-center text-stone-400">Belum ada barang. Cari barang di atas atau isi dari stok menipis.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Ringkasan --}}
        <aside class="h-fit space-y-3 xl:sticky xl:top-4">
            <div class="rounded-2xl border border-stone-200 bg-white p-5">
                <h2 class="font-semibold">Ringkasan</h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-500">Jenis barang</dt><dd class="tabular-nums">{{ count($lines) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-500">Total unit</dt><dd class="tabular-nums">{{ array_sum(array_map(fn ($l) => max(0, (int) $l['qty']), $lines)) }}</dd></div>
                    <div class="flex justify-between border-t border-stone-100 pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ $rp($this->total) }}</dd></div>
                </dl>
                @if ($sup && $sup['terms_days'])
                    <p class="mt-2 text-xs text-stone-500">Dibayar paling lambat {{ $sup['terms_days'] }} hari setelah barang diterima.</p>
                @endif
                <div class="mt-4 space-y-2">
                    <button type="button" wire:click="save('dipesan')" wire:loading.attr="disabled" wire:target="save" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Simpan & tandai dipesan</button>
                    <button type="button" wire:click="save('draf')" wire:loading.attr="disabled" wire:target="save" class="w-full rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Simpan sebagai draf</button>
                    <a href="{{ route('pengadaan.pesanan') }}" wire:navigate class="block text-center text-sm font-medium text-stone-500 hover:text-stone-800">Batal</a>
                </div>
            </div>
        </aside>
    </div>
</div>
