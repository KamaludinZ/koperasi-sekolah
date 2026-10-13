@use('App\Livewire\Transaksi\Returns')
@use('App\Livewire\Transaksi\ReturnForm')
@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $t = $this->tx;
    $sum = $this->summary;
    $field = fn ($f) => 'rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <p class="text-xs text-stone-500"><a href="{{ route('transaksi.retur') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Retur & pembatalan</a> / Retur barang</p>
        <h1 class="text-lg font-semibold">Retur barang <span class="font-mono text-base">{{ $t->invoice_number }}</span></h1>
        <p class="pb-3 text-xs text-stone-500">{{ $t->created_at->translatedFormat('l, d F Y · H:i') }} · kasir {{ $t->user?->name ?? 'Kasir' }} · {{ $rp((int) $t->total) }} · <x-transaksi.method-badge :method="$t->payment_method" />@if ($t->member) · {{ $t->member->display_name }}@endif</p>
    </header>

    @if ($this->expired)
        <div class="px-4 py-5 sm:px-6">
            <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">Transaksi ini sudah lewat batas retur {{ Returns::LIMIT_DAYS }} hari, jadi tidak bisa diretur.
                <a href="{{ route('transaksi.show', $t->id) }}" wire:navigate class="font-semibold underline">Kembali ke transaksi</a></p>
        </div>
    @else
        <form wire:submit="submit" class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="min-w-0 space-y-5">
                <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="rf-items">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                        <h2 id="rf-items" class="font-semibold">Barang yang dikembalikan</h2>
                        <button type="button" wire:click="returnAll" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Retur semua</button>
                    </div>
                    @error('qty') <p class="px-5 pt-2 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-sm">
                            <thead class="bg-stone-50 text-left text-xs text-stone-500">
                                <tr><th class="px-5 py-2 font-medium">Barang</th><th class="px-3 py-2 text-right font-medium">Dibeli</th><th class="px-3 py-2 font-medium">Jumlah retur</th><th class="px-5 py-2 text-right font-medium">Nilai</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($this->lines as $l)
                                    @php $q = max(0, min($l['left'], (int) ($qty[$l['id']] ?? 0))); @endphp
                                    <tr wire:key="rl-{{ $l['id'] }}" @class(['opacity-60' => $l['left'] === 0])>
                                        <td class="px-5 py-2">{{ $l['name'] }}
                                            <span class="block text-xs text-stone-500">{{ $rp($l['net']) }}/satuan{{ $l['net'] < $l['price'] ? ' setelah diskon' : '' }}</span></td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $l['bought'] }}@if ($l['returned'])<span class="block text-xs text-stone-500">{{ $l['returned'] }} sudah diretur</span>@endif</td>
                                        <td class="px-3 py-2">
                                            @if ($l['left'] > 0)
                                                <input type="number" min="0" max="{{ $l['left'] }}" wire:model.live.debounce.300ms="qty.{{ $l['id'] }}" value="{{ $qty[$l['id']] ?? 0 }}"
                                                       aria-label="Jumlah retur {{ $l['name'] }}" class="{{ $field('qty.'.$l['id']) }} w-20 tabular-nums">
                                                <span class="text-xs text-stone-500">maks. {{ $l['left'] }}</span>
                                                @error('qty.'.$l['id']) <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                            @else
                                                <span class="text-xs font-medium text-stone-500">Sudah diretur semua</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ $q ? $rp($q * $l['net']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="grid gap-4 rounded-2xl border border-stone-200 bg-white p-5 sm:grid-cols-2" aria-label="Alasan & kondisi">
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Alasan retur</span>
                        <select wire:model.live="reason" class="{{ $field('reason') }} w-full">
                            <option value="">Pilih alasan…</option>
                            @foreach (\App\Support\MockData::RETURN_REASONS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('reason') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <fieldset class="text-sm">
                        <legend class="mb-1 font-medium text-stone-700">Kondisi barang</legend>
                        @foreach (ReturnForm::CONDITIONS as $k => $label)
                            <label class="flex cursor-pointer items-start gap-2 py-0.5"><input type="radio" wire:model.live="condition" value="{{ $k }}" class="mt-0.5 accent-emerald-700" @checked($condition === $k)> {{ $label }}</label>
                        @endforeach
                    </fieldset>
                    <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-stone-700">Catatan @if ($reason !== 'lainnya')<span class="font-normal text-stone-500">(opsional)</span>@endif</span>
                        <input type="text" wire:model="note" maxlength="200" placeholder="mis. Tutup botol bocor saat dibuka" class="{{ $field('note') }} w-full">
                        @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </section>
            </div>

            <aside class="h-fit space-y-3 xl:sticky xl:top-4">
                <div class="rounded-2xl border border-stone-200 bg-white p-5">
                    <h2 class="font-semibold">Ringkasan retur</h2>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-stone-500">Barang diretur</dt><dd class="tabular-nums">{{ $sum['units'] }} unit</dd></div>
                        <div class="flex justify-between"><dt class="text-stone-500">Stok</dt><dd>{{ $condition === 'layak' ? '+'.$sum['units'].' kembali' : 'tidak berubah' }}</dd></div>
                        <div class="flex justify-between border-t border-stone-100 pt-2 text-base font-semibold"><dt>Dikembalikan</dt><dd class="tabular-nums">{{ $rp($sum['amount']) }}</dd></div>
                    </dl>
                    <fieldset class="mt-4 text-sm">
                        <legend class="mb-1.5 font-medium text-stone-700">Cara pengembalian dana</legend>
                        @foreach ($this->refundOptions as $k => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 px-3 py-2 has-checked:border-emerald-600 has-checked:bg-emerald-50 [&+&]:mt-1.5">
                                <input type="radio" wire:model="refundMethod" value="{{ $k }}" class="accent-emerald-700" @checked($refundMethod === $k)> {{ $label }}
                            </label>
                        @endforeach
                        @error('refundMethod') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </fieldset>
                    <p class="mt-3 text-xs text-stone-500">Pengajuan perlu disetujui Admin/Pengurus. Stok dan uang baru berubah setelah disetujui.</p>
                    <div class="mt-4 space-y-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="submit" @disabled($sum['units'] === 0)
                                class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">Ajukan retur</button>
                        <a href="{{ route('transaksi.show', $t->id) }}" wire:navigate class="block text-center text-sm font-medium text-stone-500 hover:text-stone-800">Batal</a>
                    </div>
                </div>
            </aside>
        </form>
    @endif
</div>
