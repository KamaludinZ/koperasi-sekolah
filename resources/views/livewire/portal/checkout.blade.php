@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $cart = $this->cart;
    $b = $this->balances;
    $price = $this->price;
    $total = $price['payable'];
    $balanceTotal = $this->balanceTotal;
    $option = 'flex cursor-pointer items-start gap-3 rounded-xl border border-stone-300 bg-white px-4 py-3 has-checked:border-emerald-600 has-checked:bg-emerald-50 has-disabled:cursor-not-allowed has-disabled:opacity-50';
@endphp
<form wire:submit="review" class="space-y-5">
    <div>
        <a href="{{ route('portal.belanja') }}" wire:navigate class="text-sm text-stone-500 hover:text-emerald-800">← Kembali belanja</a>
        <h1 class="mt-1 text-xl font-semibold">Checkout</h1>
    </div>

    @php $open = \App\Support\OpeningHours::status(\App\Support\MockData::profile()['schedule'], now()); @endphp
    @unless ($open['open'])
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900" data-closed-notice>Koperasi sedang tutup{{ $open['detail'] ? ' — '.mb_strtolower($open['detail']) : '' }}. Pesanan tetap bisa dikirim dan akan diproses saat koperasi buka.</p>
    @endunless

    <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="co-items">
        <h2 id="co-items" class="px-4 pt-4 font-semibold">Pesanan ({{ $cart['count'] }} barang)</h2>
        <ul class="mt-2 divide-y divide-stone-100 px-4">
            @foreach ($cart['lines'] as $l)
                <li class="flex justify-between gap-3 py-2 text-sm" wire:key="co-{{ $l['id'] }}"><span>{{ $l['name'] }} <span class="text-stone-500">× {{ $l['qty'] }}</span></span><span class="tabular-nums">{{ $rp($l['subtotal']) }}</span></li>
            @endforeach
        </ul>
        <dl class="space-y-1 border-t border-stone-200 px-4 py-3 text-sm">
            @if ($price['tax'] || $price['rounding'] || $price['tax_included'])
                <div class="flex justify-between"><dt class="text-stone-500">Subtotal</dt><dd class="tabular-nums">{{ $rp($price['subtotal']) }}</dd></div>
            @endif
            @if ($price['tax'])<div class="flex justify-between"><dt class="text-stone-500">{{ $price['tax_label'] }}</dt><dd class="tabular-nums">{{ $rp($price['tax']) }}</dd></div>@endif
            @if ($price['rounding'])<div class="flex justify-between"><dt class="text-stone-500">Pembulatan</dt><dd class="tabular-nums">{{ $rp($price['rounding']) }}</dd></div>@endif
            <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd class="tabular-nums" data-total>{{ $rp($total) }}</dd></div>
            @if ($price['tax_included'])<p class="text-xs text-stone-500">Sudah termasuk {{ $price['tax_label'] }} {{ $rp($price['tax_included']) }}.</p>@endif
        </dl>
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
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="tabungan" @disabled($b['tabungan'] < $balanceTotal) class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['tabungan'] }}</span><span class="text-sm text-stone-500">Saldo {{ $rp($b['tabungan']) }}{{ $b['tabungan'] < $balanceTotal ? ' — tidak cukup' : ' · langsung lunas' }}</span></span></label>
        @php $enough = collect($b['simpanan'])->filter(fn ($t) => $t['balance'] >= $balanceTotal); @endphp
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
        @if ($d = $this->deduction)
            <div @class(['rounded-xl border px-4 py-3 text-sm', 'border-emerald-200 bg-emerald-50' => ! $d['short'], 'border-red-200 bg-red-50' => $d['short']]) data-deduction>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-stone-600">Saldo {{ $d['source'] }}</dt><dd class="tabular-nums">{{ $rp($d['before']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Dipotong</dt><dd class="tabular-nums">−{{ $rp($d['amount']) }}</dd></div>
                    <div class="flex justify-between border-t border-stone-200 pt-1 font-semibold">
                        <dt>{{ $d['short'] ? 'Kurang' : 'Saldo setelah bayar' }}</dt>
                        <dd @class(['tabular-nums', 'text-red-700' => $d['short']]) data-after>{{ $rp($d['short'] ?: $d['after']) }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs {{ $d['short'] ? 'text-red-800' : 'text-emerald-900' }}">
                    {{ $d['short'] ? 'Saldo tidak cukup — pilih cara bayar lain.' : 'Langsung diproses tanpa menunggu persetujuan pengurus; pesanan otomatis lunas.' }}</p>
            </div>
        @endif
        @error('payment') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    </fieldset>

    <label class="block"><span class="mb-1 block font-semibold">Catatan <span class="text-sm font-normal text-stone-500">(opsional)</span></span>
        <input type="text" wire:model="note" maxlength="200" placeholder="mis. Titip ke wali kelas" class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
        @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>

    @php $hasPin = auth('member')->user()->hasPin(); @endphp
    @unless ($hasPin)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900" data-no-pin>Setiap transaksi diverifikasi dengan PIN, tetapi PIN Anda belum diatur. Minta pengurus koperasi mengaturnya.</p>
    @endunless
    <button type="submit" @disabled(! $hasPin) class="w-full rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
        Kirim pesanan · {{ $rp($total) }}
    </button>

    @if ($confirming)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-stone-950/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="pin-title"
             x-data x-on:keydown.escape.window="$wire.set('confirming', false)" wire:click.self="$set('confirming', false)">
            <div class="w-full max-w-sm space-y-4 rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl" data-pin-dialog>
                <div>
                    <h2 id="pin-title" class="text-lg font-semibold">Verifikasi PIN</h2>
                    <p class="text-sm text-stone-500">{{ $cart['count'] }} barang · {{ $payments[$payment] }} · {{ $fulfillment === 'antar' ? 'diantar' : 'ambil sendiri' }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ $rp($total) }}</p>
                </div>
                @php $att = $this->pinAttempts; @endphp
                @if ($att['locked'])
                    {{-- Terkunci: hitung mundur mm:ss, lalu segarkan dari server begitu waktunya habis. --}}
                    <div class="space-y-2 rounded-xl bg-red-50 px-4 py-4 text-center" data-pin-locked
                         x-data="{ s: {{ $att['seconds'] }}, t: null, get label() { const m = Math.floor(this.s / 60), r = this.s % 60; return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0') } }"
                         x-init="t = setInterval(() => { if (--s <= 0) { clearInterval(t); $wire.$refresh() } }, 1000)" x-on:remove="clearInterval(t)">
                        <svg class="mx-auto size-8 text-red-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <p class="text-sm font-semibold text-red-800">PIN salah terlalu sering</p>
                        <p class="text-3xl font-bold text-red-800 tabular-nums" x-text="label" data-countdown>{{ sprintf('%02d:%02d', intdiv($att['seconds'], 60), $att['seconds'] % 60) }}</p>
                        <p class="text-xs text-red-700">Transaksi terkunci sementara demi keamanan. Coba lagi setelah hitungan selesai.</p>
                    </div>
                @else
                    <x-pin-input model="pin" submit="place">
                        @error('pin') <span class="block text-center text-sm text-red-700" role="alert">{{ $message }}</span> @enderror
                        @if ($att['left'] < \App\Services\PortalPinService::MAX_ATTEMPTS)
                            <p class="flex items-center justify-center gap-1 text-xs text-amber-800" data-pin-left="{{ $att['left'] }}">
                                @for ($i = 0; $i < \App\Services\PortalPinService::MAX_ATTEMPTS; $i++)<span @class(['size-1.5 rounded-full', 'bg-amber-500' => $i < $att['left'], 'bg-stone-300' => $i >= $att['left']])></span>@endfor
                                <span class="ml-1">Sisa {{ $att['left'] }} percobaan</span>
                            </p>
                        @endif
                    </x-pin-input>
                @endif
                <div class="flex gap-2">
                    <button type="button" wire:click="$set('confirming', false)" class="flex-1 rounded-xl border border-stone-300 py-2.5 text-sm font-medium text-stone-700">Batal</button>
                    <button type="button" wire:click="place" wire:loading.attr="disabled" wire:target="place" x-data :disabled="{{ $this->pinAttempts['locked'] ? 'true' : 'false' }} || ($wire.pin ?? '').length !== 6" class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                        <span wire:loading.remove wire:target="place">Konfirmasi</span><span wire:loading wire:target="place">Memproses…</span></button>
                </div>
            </div>
        </div>
    @endif
</form>
