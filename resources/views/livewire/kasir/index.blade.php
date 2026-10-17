<div class="flex min-h-0 flex-1 flex-col">
    {{-- Header --}}
    <header class="hidden items-center justify-between gap-4 border-b border-stone-200 bg-white px-4 py-3 sm:flex sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Layar Kasir</h1>
            @php $open = \App\Support\OpeningHours::status(\App\Support\MockData::profile()['schedule'], now()); @endphp
            <p class="flex items-center gap-2 text-xs text-stone-500">{{ now()->translatedFormat('l, d F Y') }}
                <span @class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold', 'bg-emerald-100 text-emerald-800' => $open['open'], 'bg-stone-200 text-stone-700' => ! $open['open']]) title="{{ $open['detail'] }}" data-open-status="{{ $open['open'] ? 'buka' : 'tutup' }}">
                    <span @class(['size-1.5 rounded-full', 'bg-emerald-600' => $open['open'], 'bg-stone-500' => ! $open['open']])></span>{{ $open['open'] ? 'Buka' : 'Tutup' }}
                </span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right leading-tight">
                <p class="text-sm font-medium">{{ $cashier }}</p>
                <p class="text-xs text-stone-500">{{ $role }}</p>
            </div>
            <span class="grid size-9 place-items-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-800">
                {{ str($cashier)->explode(' ')->map(fn ($w) => $w[0])->take(2)->join('') }}
            </span>
        </div>
    </header>

    {{-- Mode offline: katalog diunduh di latar, antrean penjualan offline dikirim otomatis, tawaran beralih saat koneksi putus --}}
    <div x-data="offlineWatch({{ (int) auth()->id() }})" wire:ignore class="print:hidden" data-offline-watch>
        <div x-show="offline" x-cloak class="flex flex-wrap items-center justify-between gap-2 bg-amber-400 px-4 py-2 text-sm text-amber-950 sm:px-6" role="alert" data-offline-banner>
            <span><strong>Koneksi ke server terputus.</strong> Lanjutkan berjualan di kasir offline — penjualan dikirim otomatis saat tersambung lagi.</span>
            <a href="{{ route('kasir.offline') }}" class="rounded-lg bg-amber-950 px-3 py-1.5 font-semibold text-white">Buka kasir offline</a>
        </div>
        <div x-show="! offline && pending" x-cloak class="flex flex-wrap items-center justify-between gap-2 bg-sky-50 px-4 py-2 text-sm text-sky-900 sm:px-6" data-offline-pending>
            <span><span x-text="pending"></span> penjualan offline di perangkat ini belum terkirim.</span>
            <button type="button" x-on:click="sync()" x-bind:disabled="syncing" class="rounded-lg bg-sky-700 px-3 py-1.5 font-semibold text-white disabled:opacity-60" x-text="syncing ? 'Mengirim…' : 'Kirim sekarang'"></button>
        </div>
        <p class="hidden px-4 pt-1 text-right text-[11px] text-stone-400 sm:block sm:px-6" data-offline-ready>Siap offline · katalog <span x-text="catalogLabel"></span> · <a href="{{ route('kasir.offline') }}" class="underline hover:text-stone-600">kasir offline</a></p>
    </div>

    @if (auth()->user()->canOpen('transaksi'))
        <livewire:transaksi.return-status-notice compact />
    @endif

    <div class="grid min-h-0 flex-1 grid-cols-1 grid-rows-[minmax(0,1fr)] md:grid-cols-[1fr_320px] xl:grid-cols-[1fr_380px]">
        {{-- Area barang --}}
        <livewire:kasir.product-picker />

        {{-- Keranjang --}}
        <livewire:kasir.cart />
    </div>
</div>
