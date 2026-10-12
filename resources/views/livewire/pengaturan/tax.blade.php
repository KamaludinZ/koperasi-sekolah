@php
    $input = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $rp = fn ($n) => ($n < 0 ? '−' : '').'Rp'.number_format(abs($n), 0, ',', '.');
    $pv = $this->preview();
    $cur = $this->current();
    $option = 'flex cursor-pointer items-start gap-2.5 rounded-lg border border-stone-300 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
        <p class="text-xs text-stone-500">Atur pajak dan cara pembulatan total belanja.</p>
        <x-pengaturan.nav />
    </header>

    <form wire:submit="save" class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" novalidate>
        <div class="min-w-0 space-y-5">

            {{-- Pajak --}}
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="tx-tax">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="tx-tax" class="font-semibold">Pajak penjualan</h2>
                        <p class="text-xs text-stone-500">Koperasi sekolah umumnya tidak memungut pajak. Aktifkan hanya bila diwajibkan.</p>
                    </div>
                    <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                        <input type="checkbox" wire:model.live="enabled" class="peer sr-only" role="switch" aria-label="Pungut pajak">
                        <span class="h-6 w-11 rounded-full bg-stone-300 transition peer-checked:bg-emerald-700 peer-focus-visible:ring-4 peer-focus-visible:ring-emerald-600/20"></span>
                        <span class="absolute left-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </label>
                </div>

                @if ($enabled)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">Nama di struk</span>
                            <input type="text" wire:model.live.debounce.300ms="label" maxlength="20" class="{{ $input }} w-full" placeholder="mis. PPN">
                            @error('label') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">Tarif</span>
                            <span class="relative block">
                                <input type="text" inputmode="decimal" wire:model.live.debounce.300ms="rate" class="{{ $input }} w-full pr-8 tabular-nums">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">%</span>
                            </span>
                            @error('rate') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <fieldset class="grid gap-2 sm:grid-cols-2">
                        <legend class="sr-only">Cara menghitung pajak</legend>
                        <label class="{{ $option }}"><input type="radio" wire:model.live="included" value="0" class="mt-0.5 accent-emerald-700">
                            <span><span class="block font-medium">Ditambahkan ke harga</span><span class="block text-xs text-stone-500">Rp10.000 + {{ $cur['rate'] + 0 }}% = {{ $rp(10000 + round(10000 * $cur['rate'] / 100)) }}</span></span></label>
                        <label class="{{ $option }}"><input type="radio" wire:model.live="included" value="1" class="mt-0.5 accent-emerald-700">
                            <span><span class="block font-medium">Sudah termasuk harga</span><span class="block text-xs text-stone-500">Harga tetap, pajak hanya dirinci di struk</span></span></label>
                    </fieldset>
                @endif
            </section>

            {{-- Pembulatan --}}
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="tx-round">
                <div>
                    <h2 id="tx-round" class="font-semibold">Pembulatan total</h2>
                    <p class="text-xs text-stone-500">Memudahkan kembalian bila koin kecil jarang tersedia.</p>
                </div>
                <fieldset class="grid gap-2 sm:grid-cols-2">
                    <legend class="sr-only">Cara pembulatan</legend>
                    @foreach (\App\Support\PriceCalculator::ROUNDING as $value => $text)
                        <label class="{{ $option }}"><input type="radio" wire:model.live="rounding" value="{{ $value }}" class="mt-0.5 accent-emerald-700"> <span class="font-medium">{{ $text }}</span></label>
                    @endforeach
                </fieldset>
                @if ($rounding !== 'none')
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-stone-700">Kelipatan</span>
                        @foreach (\App\Support\PriceCalculator::UNITS as $u)
                            <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white">
                                <input type="radio" wire:model.live="rounding_unit" value="{{ $u }}" class="sr-only"> Rp{{ number_format($u, 0, ',', '.') }}
                            </label>
                        @endforeach
                    </div>
                    <label class="flex items-center gap-2.5 text-sm"><input type="checkbox" wire:model.live="cash_only" class="size-4 accent-emerald-700"> Hanya untuk pembayaran tunai <span class="text-xs text-stone-500">(QRIS/transfer dibayar pas)</span></label>
                    @if ($rounding === 'down')
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Pembulatan ke bawah mengurangi pemasukan koperasi di setiap transaksi.</p>
                    @endif
                @endif
                @error('rounding') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                @error('rounding_unit') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ route('pengaturan') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan</button>
            </div>
        </div>

        {{-- Kalkulator --}}
        <aside class="h-fit space-y-3 lg:sticky lg:top-4" aria-label="Contoh perhitungan">
            <div class="relative isolate overflow-hidden rounded-2xl bg-emerald-950 p-5 text-emerald-50">
                <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
                <h2 class="font-display text-xl font-bold text-white">Contoh perhitungan</h2>
                <label class="mt-3 block text-xs text-emerald-200">Total harga barang
                    <span class="relative mt-1 block">
                        <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-stone-500">Rp</span>
                        <input type="number" min="0" step="10" wire:model.live.debounce.300ms="sample" class="w-full rounded-lg border-0 bg-white py-2 pr-3 pl-9 text-sm text-stone-900 tabular-nums">
                    </span>
                </label>
                @foreach (['tunai' => 'Tunai', 'qris' => 'QRIS / Transfer'] as $method => $title)
                    @php $c = $pv[$method]; @endphp
                    <div class="mt-4 rounded-xl bg-emerald-900/70 p-3 text-sm">
                        <p class="mb-1.5 text-xs font-semibold tracking-wide text-amber-300 uppercase">{{ $title }}</p>
                        <div class="flex justify-between"><span class="text-emerald-200">Harga barang</span><span class="tabular-nums">{{ $rp($c['subtotal']) }}</span></div>
                        @if ($c['tax'])<div class="flex justify-between"><span class="text-emerald-200">{{ $cur['label'] }} {{ $cur['rate'] + 0 }}%</span><span class="tabular-nums">{{ $rp($c['tax']) }}</span></div>@endif
                        @if ($c['tax_included'])<div class="flex justify-between text-xs"><span class="text-emerald-300">termasuk {{ $cur['label'] }}</span><span class="tabular-nums">{{ $rp($c['tax_included']) }}</span></div>@endif
                        @if ($c['rounding'])<div class="flex justify-between"><span class="text-emerald-200">Pembulatan</span><span class="tabular-nums">{{ $rp($c['rounding']) }}</span></div>@endif
                        <div class="mt-1 flex justify-between border-t border-emerald-700 pt-1 font-semibold text-white"><span>Dibayar</span><span class="tabular-nums">{{ $rp($c['payable']) }}</span></div>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-stone-500">Perhitungan ini sama dengan yang dipakai layar kasir dan struk.</p>
        </aside>
    </form>
</div>
