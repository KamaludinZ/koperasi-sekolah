{{-- Panel bayar & kembalian. Kembalian dihitung instan di klien; server memvalidasi ulang saat konfirmasi. --}}
@php
    $total = $summary['total'];
    $quick = collect([5000, 10000, 20000, 50000, 100000])
        ->map(fn ($step) => (int) (ceil($total / $step) * $step))
        ->prepend($total)
        ->filter(fn ($n) => $n >= $total && $n > 0)
        ->unique()
        ->take(5)
        ->values();
@endphp

@if ($paying)
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-950/50 p-0 sm:items-center sm:p-4"
         x-data="{ paid: $wire.entangle('paid'), method: $wire.entangle('paymentMethod'), total: {{ $total }} }"
         x-init="$nextTick(() => $refs.paid?.focus())"
         x-on:keydown.escape.window="$wire.closePayment()"
         role="dialog" aria-modal="true" aria-labelledby="payment-title">
        <div class="absolute inset-0" wire:click="closePayment"></div>

        <form wire:submit="pay" class="relative w-full max-w-md rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="payment-title" class="font-semibold">Pembayaran</h2>
                <button type="button" wire:click="closePayment" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="space-y-5 px-5 py-5">
                <div class="rounded-xl bg-emerald-50 px-4 py-3">
                    <p class="text-xs font-medium text-emerald-800">Total belanja · {{ $summary['count'] }} barang</p>
                    <p class="text-3xl font-bold text-emerald-900 tabular-nums">{{ $rp($total) }}</p>
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Metode bayar</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Livewire\Kasir\Cart::PAYMENT_METHODS as $value => $label)
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2.5 text-sm font-medium transition has-checked:border-emerald-600 has-checked:bg-emerald-50 has-checked:text-emerald-900 border-stone-300 text-stone-700">
                                <input type="radio" value="{{ $value }}" x-model="method" class="sr-only">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div x-show="method === 'tunai'" class="space-y-3">
                    <label class="block">
                        <span class="mb-2 block text-sm font-medium">Uang dibayar</span>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                            <input type="number" inputmode="numeric" min="0" step="500" x-ref="paid" x-model.number="paid"
                                   class="w-full [appearance:textfield] rounded-xl border border-stone-300 py-3 pr-4 pl-11 text-xl font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 [&::-webkit-inner-spin-button]:appearance-none">
                        </div>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($quick as $amount)
                            <button type="button" x-on:click="paid = {{ $amount }}"
                                    class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium tabular-nums hover:border-emerald-600 hover:bg-emerald-50">
                                {{ $loop->first ? 'Uang pas' : $rp($amount) }}
                            </button>
                        @endforeach
                    </div>
                    @error('paid') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <div class="flex items-baseline justify-between rounded-xl border border-dashed px-4 py-3"
                         :class="(paid || 0) >= total ? 'border-emerald-300' : 'border-stone-300'">
                        <span class="text-sm font-medium" x-text="(paid || 0) >= total ? 'Kembalian' : 'Kurang'"></span>
                        <span class="text-2xl font-bold tabular-nums"
                              :class="(paid || 0) >= total ? 'text-emerald-800' : 'text-red-600'"
                              x-text="'Rp' + Math.abs((paid || 0) - total).toLocaleString('id-ID')"></span>
                    </div>
                </div>

                <p x-show="method !== 'tunai'" x-cloak class="rounded-lg bg-stone-50 px-4 py-3 text-sm text-stone-600">
                    Pastikan pembayaran non-tunai sebesar <strong class="tabular-nums">{{ $rp($total) }}</strong> sudah diterima sebelum menyelesaikan transaksi.
                </p>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="closePayment"
                        class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" :disabled="method === 'tunai' && (paid || 0) < total"
                        wire:loading.attr="disabled" wire:target="pay"
                        class="flex-1 rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">
                    Selesaikan transaksi
                </button>
            </div>
        </form>
    </div>
@endif

@if ($lastTransaction)
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-950/50 sm:items-center sm:p-4"
         x-data x-on:keydown.enter.window.prevent="$wire.newTransaction()"
         role="dialog" aria-modal="true" aria-labelledby="success-title">
        <div class="relative w-full max-w-sm rounded-t-2xl bg-white px-6 py-7 text-center shadow-xl sm:rounded-2xl">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg>
            </span>
            <h2 id="success-title" class="mt-3 font-semibold">Transaksi berhasil</h2>
            <p class="font-mono text-xs text-stone-500">{{ $lastTransaction['invoice_number'] }}</p>

            <dl class="mt-5 space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-stone-500">Total</dt><dd class="tabular-nums">{{ $rp($lastTransaction['total']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">Dibayar ({{ \App\Livewire\Kasir\Cart::PAYMENT_METHODS[$lastTransaction['payment_method']] }})</dt><dd class="tabular-nums">{{ $rp($lastTransaction['paid']) }}</dd></div>
            </dl>
            <div class="mt-4 rounded-xl bg-emerald-50 px-4 py-3">
                <p class="text-xs font-medium text-emerald-800">Kembalian</p>
                <p class="text-3xl font-bold text-emerald-900 tabular-nums">{{ $rp($lastTransaction['change']) }}</p>
            </div>

            <button type="button" wire:click="newTransaction"
                    class="mt-6 w-full rounded-xl bg-emerald-700 py-3 font-semibold text-white hover:bg-emerald-800">
                Transaksi baru <span class="ml-1 text-xs font-normal opacity-70">(Enter)</span>
            </button>
        </div>
    </div>
@endif
