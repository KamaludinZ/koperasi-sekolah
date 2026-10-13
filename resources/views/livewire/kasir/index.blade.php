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

    @if (\Illuminate\Support\Facades\Route::has('transaksi.retur') && auth()->user()->canOpen('transaksi'))
        <livewire:transaksi.return-status-notice compact />
    @endif

    <div class="grid min-h-0 flex-1 grid-cols-1 grid-rows-[minmax(0,1fr)] md:grid-cols-[1fr_320px] xl:grid-cols-[1fr_380px]">
        {{-- Area barang --}}
        <livewire:kasir.product-picker />

        {{-- Keranjang --}}
        <livewire:kasir.cart />
    </div>
</div>
