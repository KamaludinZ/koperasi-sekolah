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
        <section class="flex min-h-0 flex-col">
            <div class="space-y-3 border-b border-stone-200 bg-white/60 px-4 py-4 sm:px-6">
                <label class="relative block">
                    <span class="sr-only">Cari barang</span>
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" autofocus placeholder="Cari nama atau kode barang…"
                           class="w-full rounded-xl border border-stone-300 bg-white py-3 pr-4 pl-11 text-base shadow-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                </label>
                <div class="flex gap-2 overflow-x-auto pb-1">
                    <button type="button" class="shrink-0 rounded-full bg-emerald-900 px-3.5 py-1.5 text-sm font-medium text-white">Semua</button>
                    @foreach ($categories as $category)
                        <button type="button" class="shrink-0 rounded-full border border-stone-300 bg-white px-3.5 py-1.5 text-sm font-medium text-stone-700 hover:border-stone-400">{{ $category }}</button>
                    @endforeach
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6">
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                    @foreach ($products as $product)
                        @php
                            $out = $product['stock'] === 0;
                            $low = ! $out && $product['stock'] <= $product['min_stock'];
                        @endphp
                        <li>
                            <button type="button" @disabled($out)
                                    class="group flex h-full w-full flex-col rounded-xl border border-stone-200 bg-white p-3.5 text-left shadow-sm transition hover:border-emerald-500 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-stone-200 disabled:hover:shadow-sm">
                                <span class="font-mono text-[11px] tracking-wide text-stone-400">{{ $product['code'] }}</span>
                                <span class="mt-1 line-clamp-2 text-sm leading-snug font-medium">{{ $product['name'] }}</span>
                                <span class="mt-auto flex items-end justify-between gap-2 pt-3">
                                    <span class="font-semibold text-emerald-800 tabular-nums">Rp{{ number_format($product['price'], 0, ',', '.') }}</span>
                                    <span @class(['rounded px-1.5 py-0.5 text-[11px] font-medium tabular-nums',
                                                  'bg-red-100 text-red-700' => $out,
                                                  'bg-amber-100 text-amber-800' => $low,
                                                  'bg-stone-100 text-stone-600' => ! $out && ! $low])>
                                        {{ $out ? 'Habis' : $product['stock'].' '.$product['unit'] }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

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
