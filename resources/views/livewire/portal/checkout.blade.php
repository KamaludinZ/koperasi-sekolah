@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $cart = $this->cart;
    $b = $this->balances;
    $total = $cart['total'];
    $option = 'flex cursor-pointer items-start gap-3 rounded-xl border border-stone-300 bg-white px-4 py-3 has-checked:border-emerald-600 has-checked:bg-emerald-50 has-disabled:cursor-not-allowed has-disabled:opacity-50';
@endphp
<form wire:submit="place" class="space-y-5">
    <div>
        <a href="{{ route('portal.belanja') }}" wire:navigate class="text-sm text-stone-500 hover:text-emerald-800">← Kembali belanja</a>
        <h1 class="mt-1 text-xl font-semibold">Checkout</h1>
    </div>

    <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="co-items">
        <h2 id="co-items" class="px-4 pt-4 font-semibold">Pesanan ({{ $cart['count'] }} barang)</h2>
        <ul class="mt-2 divide-y divide-stone-100 px-4">
            @foreach ($cart['lines'] as $l)
                <li class="flex justify-between gap-3 py-2 text-sm" wire:key="co-{{ $l['id'] }}"><span>{{ $l['name'] }} <span class="text-stone-500">× {{ $l['qty'] }}</span></span><span class="tabular-nums">{{ $rp($l['subtotal']) }}</span></li>
            @endforeach
        </ul>
        <div class="flex justify-between border-t border-stone-200 px-4 py-3 text-base font-semibold"><span>Total</span><span class="tabular-nums" data-total>{{ $rp($total) }}</span></div>
        @error('cart') <p class="px-4 pb-3 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    </section>

    <fieldset class="space-y-2">
        <legend class="mb-1 font-semibold">Pengambilan</legend>
        @foreach ($fulfillments as $k => $label)
            <label class="{{ $option }}"><input type="radio" wire:model.live="fulfillment" value="{{ $k }}" class="mt-1 accent-emerald-700">
                <span><span class="block font-medium">{{ $label }}</span><span class="text-sm text-stone-500">{{ $k === 'ambil' ? 'Ambil di koperasi setelah pesanan siap.' : 'Diantar ke kelas atau ruangan Anda.' }}</span></span></label>
        @endforeach
        @if ($fulfillment === 'antar')
            <label class="block"><span class="mb-1 block text-sm font-medium">Antar ke</span>
                <input type="text" wire:model="deliveryTo" maxlength="100" placeholder="mis. Kelas 7A atau Ruang Guru" class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                @error('deliveryTo') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
        @endif
        @error('fulfillment') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
    </fieldset>

    <fieldset class="space-y-2">
        <legend class="mb-1 font-semibold">Pembayaran</legend>
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="tabungan" @disabled($b['tabungan'] < $total) class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['tabungan'] }}</span><span class="text-sm text-stone-500">Saldo {{ $rp($b['tabungan']) }}{{ $b['tabungan'] < $total ? ' — tidak cukup' : ' · langsung lunas' }}</span></span></label>
        @php $enough = collect($b['simpanan'])->filter(fn ($t) => $t['balance'] >= $total); @endphp
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="simpanan" @disabled($enough->isEmpty()) class="mt-1 accent-emerald-700">
            <span class="flex-1"><span class="block font-medium">{{ $payments['simpanan'] }}</span>
                <span class="text-sm text-stone-500">{{ $b['simpanan'] ? collect($b['simpanan'])->map(fn ($t) => $t['name'].' '.$rp($t['balance']))->join(' · ') : 'Tidak ada simpanan yang bisa dipakai' }}</span>
                @if ($payment === 'simpanan')
                    <select wire:model="savingTypeId" aria-label="Simpanan yang dipotong" class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                        @foreach ($enough as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }} · saldo {{ $rp($t['balance']) }}</option>@endforeach
                    </select>
                    @error('savingTypeId') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                @endif
            </span></label>
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="tunai" class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['tunai'] }}</span><span class="text-sm text-stone-500">Bayar saat {{ $fulfillment === 'antar' ? 'barang diantar' : 'mengambil barang' }}.</span></span></label>
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="qris" class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['qris'] }}</span><span class="text-sm text-stone-500">Pindai QRIS lalu tunjukkan bukti bayar ke petugas.</span></span></label>
        @if ($payment === 'qris' && $this->qrisUrl)
            <figure class="rounded-xl border border-stone-200 bg-white p-4 text-center">
                <img src="{{ $this->qrisUrl }}" alt="Kode QRIS koperasi" class="mx-auto max-h-64">
                <figcaption class="mt-2 text-sm text-stone-600">Bayar tepat <strong class="tabular-nums">{{ $rp($total) }}</strong></figcaption>
            </figure>
        @endif
        @error('payment') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    </fieldset>

    <label class="block"><span class="mb-1 block font-semibold">Catatan <span class="text-sm font-normal text-stone-500">(opsional)</span></span>
        <input type="text" wire:model="note" maxlength="200" placeholder="mis. Titip ke wali kelas" class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
        @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>

    <button type="submit" wire:loading.attr="disabled" wire:target="place" class="w-full rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
        <span wire:loading.remove wire:target="place">Kirim pesanan · {{ $rp($total) }}</span><span wire:loading wire:target="place">Mengirim…</span>
    </button>
</form>
