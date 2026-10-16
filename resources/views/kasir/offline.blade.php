<!DOCTYPE html>
{{-- Kasir offline: halaman mandiri yang disimpan service worker sehingga bisa dibuka tanpa internet. Semua data dari
     salinan katalog di perangkat (IndexedDB); penjualan masuk antrean lalu disinkronkan saat server bisa dihubungi. --}}
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#022c22">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kasir Offline · {{ \App\Support\MockData::profile()['app_name'] }}</title>
    <x-brand.favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        @media print { body * { visibility: hidden; } #struk-offline, #struk-offline * { visibility: visible; } #struk-offline { position: absolute; inset: 0 auto auto 0; width: 58mm; } }
    </style>
</head>
<body class="h-full bg-stone-100 text-stone-900 antialiased">
<div class="flex h-full flex-col" x-data="offlinePos({{ (int) $userId }})" data-offline-pos>
    <header class="flex flex-wrap items-center justify-between gap-3 bg-emerald-950 px-4 py-3 text-emerald-50 print:hidden">
        <div class="min-w-0">
            <p class="text-sm font-semibold">Kasir offline</p>
            <p class="text-xs text-emerald-200">{{ $userName }} · <span x-text="catalog ? 'katalog ' + new Date(catalog.downloaded_at).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : 'katalog belum ada'"></span></p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold" x-bind:class="online ? 'bg-emerald-700 text-white' : 'bg-amber-400 text-amber-950'" data-connection>
                <span class="size-2 rounded-full" x-bind:class="online ? 'bg-emerald-200' : 'bg-amber-900'"></span>
                <span x-text="online ? 'Server tersambung' : 'Tanpa koneksi'"></span>
            </span>
            <button type="button" x-on:click="showQueue = ! showQueue" class="rounded-full bg-white/10 px-2.5 py-1 font-semibold hover:bg-white/20" data-queue-toggle>
                Antrean: <span x-text="pendingCount"></span> belum terkirim<span x-show="problemCount" x-cloak x-text="' · ' + problemCount + ' bermasalah'"></span>
            </button>
            <button type="button" x-on:click="sync()" x-bind:disabled="syncing || ! pendingCount || ! online" class="rounded-full bg-white px-2.5 py-1 font-semibold text-emerald-900 disabled:opacity-50" data-sync-now>
                <span x-text="syncing ? 'Mengirim…' : 'Kirim sekarang'"></span>
            </button>
            <a href="{{ route('kasir') }}" x-show="online" class="rounded-full bg-white/10 px-2.5 py-1 font-semibold hover:bg-white/20">← Kasir online</a>
        </div>
    </header>

    <div x-show="message" x-cloak class="px-4 py-2 text-sm print:hidden" role="status"
         x-bind:class="{ 'bg-red-50 text-red-800': message?.type === 'error', 'bg-emerald-50 text-emerald-900': message?.type === 'success', 'bg-sky-50 text-sky-900': message?.type === 'info' }"
         x-text="message?.text"></div>

    {{-- Belum siap: tidak ada salinan katalog milik petugas ini, atau kedaluwarsa --}}
    <template x-if="! ready">
        <div class="m-4 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950" data-offline-not-ready>
            <p class="font-semibold" x-text="! catalog ? 'Salinan katalog belum ada di perangkat ini.' : (! mine ? 'Salinan katalog di perangkat ini milik petugas lain.' : 'Salinan katalog sudah kedaluwarsa.')"></p>
            <p class="mt-1">Buka halaman Kasir saat online sekali agar katalog terunduh otomatis, lalu kasir offline bisa dipakai saat sinyal hilang.</p>
            <button type="button" x-show="online" x-on:click="tick().then(() => catalog = catalog)" class="mt-3 rounded-xl bg-emerald-700 px-4 py-2 font-semibold text-white">Unduh katalog sekarang</button>
        </div>
    </template>

    <div class="grid min-h-0 flex-1 gap-0 md:grid-cols-[minmax(0,1fr)_22rem] print:hidden" x-show="ready && ! showQueue">
        {{-- Barang --}}
        <section class="flex min-h-0 flex-col border-r border-stone-200" aria-label="Barang">
            <div class="space-y-2 border-b border-stone-200 bg-white/60 p-4">
                <input type="search" x-ref="search" x-model="search" x-on:keydown.enter.prevent="pickFromSearch()" placeholder="Cari nama/kode atau scan barcode…"
                       class="w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-base" data-offline-search>
                <div class="flex gap-1.5 overflow-x-auto text-xs">
                    <button type="button" x-on:click="category = ''" class="shrink-0 rounded-full px-3 py-1 font-medium" x-bind:class="category === '' ? 'bg-emerald-700 text-white' : 'bg-white ring-1 ring-stone-200'">Semua</button>
                    <template x-for="c in categories" :key="c">
                        <button type="button" x-on:click="category = category === c ? '' : c" class="shrink-0 rounded-full px-3 py-1 font-medium" x-bind:class="category === c ? 'bg-emerald-700 text-white' : 'bg-white ring-1 ring-stone-200'" x-text="c"></button>
                    </template>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-4">
                <p class="mb-2 text-xs text-stone-500"><span x-text="products.length"></span> barang · urut paling laku · promo, kupon & diskon tidak berlaku offline</p>
                <ul class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <template x-for="p in products" :key="p.id">
                        <li>
                            <button type="button" x-on:click="add(p)" x-bind:disabled="p.stock - inCart(p.id) < 1" data-offline-product
                                    class="flex h-full w-full flex-col rounded-xl border border-stone-200 bg-white p-3 text-left shadow-sm hover:border-emerald-500 disabled:opacity-50">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[11px] text-stone-400" x-text="p.code"></span>
                                    <span x-show="isBest(p.id)" class="rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold text-orange-800">Terlaris</span>
                                </span>
                                <span class="mt-1 line-clamp-2 text-sm font-medium" x-text="p.name"></span>
                                <span class="mt-auto flex items-end justify-between pt-2">
                                    <span class="font-semibold text-emerald-800" x-text="rupiah(p.price)"></span>
                                    <span class="rounded bg-stone-100 px-1.5 py-0.5 text-[11px]" x-text="(p.stock - inCart(p.id)) < 1 ? 'Habis' : (p.stock - inCart(p.id)) + ' ' + p.unit"></span>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </section>

        {{-- Keranjang & bayar --}}
        <section class="flex min-h-0 flex-col bg-white" aria-label="Keranjang">
            <template x-if="lastSale">
                <div class="space-y-3 p-4" data-offline-done>
                    <p class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900"><strong>Tersimpan di perangkat</strong> — <span x-text="lastSale.local_number"></span>. Akan dikirim otomatis saat server tersambung.</p>
                    <p class="text-2xl font-semibold" x-show="lastSale.payment_method === 'tunai'">Kembalian <span x-text="rupiah(lastSale.paid - lastSale.total)"></span></p>
                    <div class="flex gap-2">
                        <button type="button" x-on:click="printReceipt()" class="flex-1 rounded-xl border border-stone-300 px-4 py-2.5 font-semibold">Cetak struk</button>
                        <button type="button" x-on:click="newSale()" class="flex-1 rounded-xl bg-emerald-700 px-4 py-2.5 font-semibold text-white" data-offline-new>Transaksi baru</button>
                    </div>
                </div>
            </template>
            <div class="min-h-0 flex-1 overflow-y-auto p-4" x-show="! lastSale">
                <p x-show="! cart.length" class="py-10 text-center text-sm text-stone-500">Keranjang kosong.</p>
                <ul class="divide-y divide-stone-100">
                    <template x-for="l in cart" :key="l.id">
                        <li class="flex items-center gap-2 py-2 text-sm">
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium" x-text="l.name"></span><span class="text-xs text-stone-500" x-text="rupiah(l.price)"></span></span>
                            <input type="number" min="0" max="999" x-bind:value="l.qty" x-on:change="setQty(l, $event.target.value)" class="w-16 rounded-lg border border-stone-300 px-2 py-1 text-right">
                            <span class="w-24 text-right font-semibold tabular-nums" x-text="rupiah(l.price * l.qty)"></span>
                        </li>
                    </template>
                </ul>
            </div>
            <div class="space-y-3 border-t border-stone-200 p-4" x-show="cart.length && ! lastSale">
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums" x-text="rupiah(totals.subtotal)"></dd></div>
                    <div class="flex justify-between" x-show="totals.tax"><dt x-text="catalog?.tax?.label ?? 'Pajak'"></dt><dd class="tabular-nums" x-text="rupiah(totals.tax)"></dd></div>
                    <div class="flex justify-between" x-show="totals.rounding"><dt>Pembulatan</dt><dd class="tabular-nums" x-text="rupiah(totals.rounding, true)"></dd></div>
                    <div class="flex justify-between text-lg font-semibold"><dt>Total</dt><dd class="tabular-nums" x-text="rupiah(totals.payable)" data-offline-total></dd></div>
                </dl>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <template x-for="(label, key) in catalog?.payment_methods ?? {}" :key="key">
                        <button type="button" x-on:click="method = key" class="rounded-xl border px-3 py-2 font-semibold" x-bind:class="method === key ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-stone-300'" x-text="label"></button>
                    </template>
                </div>
                <label x-show="method === 'tunai'" class="block text-sm">Uang diterima
                    <input type="number" min="0" x-model="paid" x-on:keydown.enter.prevent="pay()" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 text-lg tabular-nums" data-offline-paid>
                </label>
                <p x-show="method === 'tunai' && paid !== ''" class="text-sm" x-bind:class="change < 0 ? 'text-red-700' : 'text-stone-700'" x-text="change < 0 ? 'Kurang ' + rupiah(-change) : 'Kembalian ' + rupiah(change)"></p>
                <p x-show="method === 'qris'" class="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900">Pastikan dana QRIS/transfer sudah masuk sebelum menyimpan.</p>
                <button type="button" x-on:click="pay()" class="w-full rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white hover:bg-emerald-800" data-offline-pay>Bayar & simpan offline</button>
            </div>
        </section>
    </div>

    {{-- Antrean penjualan di perangkat --}}
    <section x-show="showQueue" x-cloak class="min-h-0 flex-1 overflow-y-auto p-4 print:hidden" data-offline-queue>
        <h2 class="mb-2 font-semibold">Penjualan offline di perangkat ini</h2>
        <p x-show="! sales.length" class="text-sm text-stone-500">Belum ada.</p>
        <ul class="space-y-2">
            <template x-for="s in sales" :key="s.client_ref">
                <li class="rounded-xl border border-stone-200 bg-white p-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-2">
                        <span class="font-mono text-xs" x-text="s.local_number"></span>
                        <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold" x-text="statusLabel(s.status)"
                              x-bind:class="{ 'bg-amber-100 text-amber-900': s.status === 'antre' || s.status === 'perlu_dicek', 'bg-emerald-100 text-emerald-800': s.status === 'terkirim', 'bg-red-100 text-red-800': s.status === 'ditolak' || s.status === 'tidak_sah' }"></span>
                    </div>
                    <p class="mt-1"><span x-text="new Date(s.sold_at).toLocaleString('id-ID')"></span> · <span x-text="rupiah(s.total)"></span> · <span x-text="s.items.length + ' jenis barang'"></span></p>
                    <p x-show="s.result?.invoice_number" class="text-xs text-stone-600">Nomor resmi: <span class="font-mono" x-text="s.result?.invoice_number"></span></p>
                    <p x-show="s.result?.reason" class="text-xs text-red-700" x-text="s.result?.reason"></p>
                </li>
            </template>
        </ul>
    </section>

    {{-- Struk sementara (dicetak) --}}
    <div id="struk-offline" class="hidden print:block" aria-hidden="true">
        <template x-if="lastSale">
            <div class="font-mono text-[11px] leading-tight">
                <p class="text-center font-bold" x-text="catalog?.receipt?.title"></p>
                <p class="text-center" x-text="catalog?.receipt?.subtitle"></p>
                <p class="text-center" x-show="catalog?.receipt?.address" x-text="catalog?.receipt?.address"></p>
                <p class="mt-1 text-center">STRUK SEMENTARA (OFFLINE)</p>
                <p x-text="lastSale.local_number"></p>
                <p x-text="new Date(lastSale.sold_at).toLocaleString('id-ID')"></p>
                <p>Kasir: {{ $userName }}</p>
                <hr class="my-1 border-dashed">
                <template x-for="i in lastSale.items" :key="i.product_id">
                    <div><p x-text="i.name"></p><p class="flex justify-between"><span x-text="i.quantity + ' x ' + rupiah(i.price)"></span><span x-text="rupiah(i.quantity * i.price)"></span></p></div>
                </template>
                <hr class="my-1 border-dashed">
                <p class="flex justify-between"><span>Subtotal</span><span x-text="rupiah(lastSale.totals.subtotal)"></span></p>
                <p class="flex justify-between" x-show="lastSale.totals.tax"><span>Pajak</span><span x-text="rupiah(lastSale.totals.tax)"></span></p>
                <p class="flex justify-between" x-show="lastSale.totals.rounding"><span>Pembulatan</span><span x-text="rupiah(lastSale.totals.rounding, true)"></span></p>
                <p class="flex justify-between font-bold"><span>Total</span><span x-text="rupiah(lastSale.total)"></span></p>
                <p class="flex justify-between"><span x-text="lastSale.payment_method === 'tunai' ? 'Tunai' : 'QRIS/Transfer'"></span><span x-text="rupiah(lastSale.paid)"></span></p>
                <p class="flex justify-between" x-show="lastSale.payment_method === 'tunai'"><span>Kembali</span><span x-text="rupiah(lastSale.paid - lastSale.total)"></span></p>
                <hr class="my-1 border-dashed">
                <p class="text-center" x-text="catalog?.receipt?.footer"></p>
                <p class="text-center">Nomor resmi terbit setelah sinkron.</p>
            </div>
        </template>
    </div>
</div>
@livewireScripts
</body>
</html>
