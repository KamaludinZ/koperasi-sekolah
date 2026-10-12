<div class="flex h-full min-h-0 flex-col">
    {{-- Header --}}
    <header class="flex items-center justify-between gap-4 border-b border-stone-200 bg-white px-4 py-3 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Layar Kasir</h1>
            <p class="text-xs text-stone-500">{{ now()->translatedFormat('l, d F Y') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right leading-tight">
                <p class="text-sm font-medium">{{ $cashier }}</p>
                <p class="text-xs text-stone-500">Kasir</p>
            </div>
            <span class="grid size-9 place-items-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-800">
                {{ str($cashier)->explode(' ')->map(fn ($w) => $w[0])->take(2)->join('') }}
            </span>
        </div>
    </header>

    <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[1fr_380px]">
        {{-- Area barang --}}
        <livewire:kasir.product-picker />

        {{-- Keranjang --}}
        <aside class="flex min-h-0 flex-col border-t border-stone-200 bg-white lg:border-t-0 lg:border-l">
            <div class="flex items-center justify-between px-5 py-4">
                <h2 class="font-semibold">Keranjang</h2>
                <span class="text-xs text-stone-500">0 barang</span>
            </div>

            <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center">
                <svg class="size-10 text-stone-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 4h2l2.4 11.2a1 1 0 001 .8h9.2a1 1 0 001-.8L20 8H6.2"/><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/></svg>
                <p class="text-sm font-medium text-stone-600">Keranjang masih kosong</p>
                <p class="text-xs text-stone-400">Pilih barang di sebelah kiri untuk mulai transaksi.</p>
            </div>

            <div class="space-y-2 border-t border-stone-200 px-5 py-4 text-sm">
                <div class="flex justify-between text-stone-600"><span>Subtotal</span><span class="tabular-nums">Rp0</span></div>
                <div class="flex justify-between text-stone-600"><span>Pajak</span><span class="tabular-nums">Rp0</span></div>
                <div class="flex items-baseline justify-between pt-1">
                    <span class="font-semibold">Total</span>
                    <span class="text-2xl font-bold text-emerald-900 tabular-nums">Rp0</span>
                </div>
            </div>

            <div class="px-5 pb-5">
                <button type="button" disabled
                        class="w-full rounded-xl bg-emerald-700 py-3.5 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">
                    Bayar
                </button>
            </div>
        </aside>
    </div>
</div>
