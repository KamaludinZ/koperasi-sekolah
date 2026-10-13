@use('App\Livewire\Transaksi\VoidForm')
@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $t = $this->tx;
    $field = fn ($f) => 'rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <p class="text-xs text-stone-500"><a href="{{ route('transaksi.retur') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Retur & pembatalan</a> / Batalkan transaksi</p>
        <h1 class="text-lg font-semibold">Batalkan transaksi <span class="font-mono text-base">{{ $t->invoice_number }}</span></h1>
        <p class="pb-3 text-xs text-stone-500">{{ $t->created_at->translatedFormat('l, d F Y · H:i') }} · kasir {{ $t->user?->name ?? 'Kasir' }} · <x-transaksi.method-badge :method="$t->payment_method" />@if ($t->member) · {{ $t->member->display_name }}@endif</p>
    </header>

    @if ($msg = $this->blocked)
        <div class="px-4 py-5 sm:px-6">
            <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $msg }}
                <a href="{{ route('transaksi.show', $t->id) }}" wire:navigate class="font-semibold underline">Kembali ke transaksi</a></p>
        </div>
    @else
        <form wire:submit="submit" class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-5">
                <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="vf-items">
                    <h2 id="vf-items" class="px-5 pt-5 font-semibold">Isi transaksi</h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[30rem] text-sm">
                            <thead class="bg-stone-50 text-left text-xs text-stone-500">
                                <tr><th class="px-5 py-2 font-medium">Barang</th><th class="px-3 py-2 text-right font-medium">Jumlah</th><th class="px-5 py-2 text-right font-medium">Subtotal</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($t->items->sortBy('id') as $it)
                                    <tr wire:key="vi-{{ $it->id }}"><td class="px-5 py-2">{{ $it->product_name }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $it->quantity }}</td><td class="px-5 py-2 text-right tabular-nums">{{ $rp((int) $it->subtotal) }}</td></tr>
                                @endforeach
                            </tbody>
                            <tfoot class="text-sm">
                                @if ((int) $t->discount > 0)<tr><td colspan="2" class="px-5 pt-2 text-stone-500">Diskon</td><td class="px-5 pt-2 text-right tabular-nums">−{{ $rp((int) $t->discount) }}</td></tr>@endif
                                <tr class="font-semibold"><td colspan="2" class="px-5 py-2">Total</td><td class="px-5 py-2 text-right tabular-nums">{{ $rp((int) $t->total) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-label="Alasan pembatalan">
                    <fieldset class="text-sm">
                        <legend class="mb-2 font-medium text-stone-700">Alasan pembatalan</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Support\MockData::VOID_REASONS as $k => $label)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 px-3 py-2 has-checked:border-red-500 has-checked:bg-red-50">
                                    <input type="radio" wire:model.live="reason" value="{{ $k }}" class="accent-red-600" @checked($reason === $k)> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('reason') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </fieldset>
                    <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Penjelasan @if ($reason !== 'lainnya')<span class="font-normal text-stone-500">(opsional)</span>@endif</span>
                        <textarea wire:model="note" rows="2" maxlength="200" placeholder="mis. Pembeli salah ambil ukuran, belum keluar dari kasir" class="{{ $field('note') }} w-full"></textarea>
                        @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </section>
            </div>

            <aside class="h-fit space-y-3 xl:sticky xl:top-4">
                <div class="rounded-2xl border border-red-200 bg-white p-5">
                    <h2 class="font-semibold text-red-800">Akibat pembatalan</h2>
                    <ul class="mt-3 space-y-2 text-sm text-stone-700">
                        <li class="flex gap-2"><span aria-hidden="true">•</span> Seluruh {{ $t->item_count }} barang kembali ke stok.</li>
                        <li class="flex gap-2"><span aria-hidden="true">•</span> {{ VoidForm::EFFECTS[$t->payment_method] ?? 'Dana dikembalikan ke pembeli.' }} <strong class="tabular-nums">{{ $rp((int) $t->total) }}</strong></li>
                        <li class="flex gap-2"><span aria-hidden="true">•</span> Transaksi tetap tercatat dengan tanda <em>dibatalkan</em> dan tidak dihitung di laporan penjualan.</li>
                    </ul>
                    <label class="mt-4 block text-sm"><span class="mb-1 block font-medium text-stone-700">Ketik <span class="font-mono font-bold">{{ $this->code }}</span> (4 digit terakhir nomor transaksi)</span>
                        <input type="text" inputmode="numeric" maxlength="4" wire:model="confirmCode" autocomplete="off" class="{{ $field('confirmCode') }} w-28 font-mono tracking-widest">
                        @error('confirmCode') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <p class="mt-3 text-xs text-stone-500">Pengajuan perlu disetujui Admin/Pengurus sebelum stok dan uang berubah.</p>
                    <div class="mt-4 space-y-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="w-full rounded-xl bg-red-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-800">Ajukan pembatalan</button>
                        <a href="{{ route('transaksi.show', $t->id) }}" wire:navigate class="block text-center text-sm font-medium text-stone-500 hover:text-stone-800">Batal</a>
                    </div>
                </div>
            </aside>
        </form>
    @endif
</div>
