@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $summary = $this->summary;
@endphp
<aside class="fixed inset-x-0 bottom-0 z-30 flex max-h-[85dvh] min-h-0 flex-col md:bg-white md:static md:max-h-none md:rounded-none md:border-t-0 md:border-l md:shadow-none"
       x-data="{ sheet: false }"
       x-on:keydown.window.f9.prevent="$wire.openPayment()"
       x-on:keydown.escape.window="sheet = false">
    {{-- Latar saat keranjang dibuka di ponsel --}}
    <div x-show="sheet" x-cloak x-transition.opacity x-on:click="sheet = false" class="fixed inset-0 -z-10 bg-stone-950/40 md:hidden"></div>

    {{-- Detail keranjang: selalu tampil di md+, bisa dibuka/tutup di ponsel --}}
    <div class="hidden min-h-0 flex-1 flex-col rounded-t-2xl border-t border-stone-200 bg-white md:flex md:rounded-none md:border-t-0" x-bind:class="sheet && 'flex!'">
    <div class="flex items-center justify-between px-5 py-4">
        <h2 class="font-semibold">Keranjang</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs text-stone-500">{{ $summary['count'] }} barang</span>
            @if ($summary['count'] > 0)
                <button type="button" wire:click="clear" wire:confirm="Kosongkan keranjang?"
                        class="text-xs font-medium text-red-600 hover:text-red-700">Kosongkan</button>
            @endif
        </div>
    </div>

    @if ($notice)
        <p class="mx-5 mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" role="status">{{ $notice }}</p>
    @endif

    @if ($lastRemoved)
        <div class="mx-5 mb-2 flex items-center justify-between gap-3 rounded-lg bg-stone-800 px-3 py-2 text-xs text-white" role="status">
            <span class="truncate">{{ $lastRemoved['name'] }} dihapus</span>
            <button type="button" wire:click="undoRemove" class="shrink-0 font-semibold text-amber-300 hover:text-amber-200">Urungkan</button>
        </div>
    @endif

    @if (count($this->lines) === 0)
        <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center">
            <svg class="size-10 text-stone-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 4h2l2.4 11.2a1 1 0 001 .8h9.2a1 1 0 001-.8L20 8H6.2"/><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/></svg>
            <p class="text-sm font-medium text-stone-600">Keranjang masih kosong</p>
            <p class="text-xs text-stone-400">Pilih barang di sebelah kiri untuk mulai transaksi.</p>
        </div>
    @else
        <ul class="min-h-0 flex-1 divide-y divide-stone-100 overflow-y-auto px-5">
            @foreach ($this->lines as $line)
                <li wire:key="line-{{ $line['id'] }}" class="flex gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $line['name'] }}</p>
                        <p class="text-xs text-stone-500 tabular-nums">{{ $rp($line['price']) }} / {{ $line['unit'] }}</p>
                        <div class="mt-2 inline-flex items-center rounded-lg border border-stone-300">
                            <button type="button" wire:click="decrement({{ $line['id'] }})" aria-label="Kurangi"
                                    class="grid size-8 place-items-center text-stone-600 hover:bg-stone-100">−</button>
                            <input type="number" min="0" max="{{ $line['stock'] }}" value="{{ $line['quantity'] }}"
                                   wire:change="setQty({{ $line['id'] }}, $event.target.value || 0)"
                                   aria-label="Jumlah {{ $line['name'] }}"
                                   class="w-11 [appearance:textfield] border-x border-stone-300 py-1 text-center text-sm tabular-nums outline-none [&::-webkit-inner-spin-button]:appearance-none">
                            <button type="button" wire:click="increment({{ $line['id'] }})" aria-label="Tambah" @disabled($line['quantity'] >= $line['stock'])
                                    class="grid size-8 place-items-center text-stone-600 hover:bg-stone-100 disabled:cursor-not-allowed disabled:text-stone-300 disabled:hover:bg-transparent">+</button>
                        </div>
                        @php $left = $line['stock'] - $line['quantity']; @endphp
                        @if ($left <= 0)
                            <p class="mt-1 text-[11px] font-medium text-red-700" data-stock-note="max">Stok habis setelah ini ({{ $line['stock'] }} {{ $line['unit'] }})</p>
                        @elseif ($left <= ($line['min_stock'] ?? 0))
                            <p class="mt-1 text-[11px] font-medium text-amber-800" data-stock-note="low">Sisa {{ $left }} {{ $line['unit'] }} — stok menipis</p>
                        @endif
                    </div>
                    <div class="flex flex-col items-end justify-between">
                        <button type="button" wire:click="remove({{ $line['id'] }})" aria-label="Hapus {{ $line['name'] }}"
                                class="text-stone-400 hover:text-red-600">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                        </button>
                        <span class="text-sm font-semibold tabular-nums">{{ $rp($line['subtotal']) }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @include('livewire.kasir.partials.promo-panel')

    <div class="space-y-2 border-t border-stone-200 px-5 py-4 text-sm">
        <div class="flex justify-between text-stone-600"><span>Subtotal</span><span class="tabular-nums">{{ $rp($summary['subtotal']) }}</span></div>
        @if ($summary['promo'] > 0)
            <div class="flex justify-between text-emerald-800"><span>Promo otomatis</span><span class="font-medium tabular-nums">−{{ $rp($summary['promo']) }}</span></div>
        @endif
        @if ($summary['coupon'] > 0)
            <div class="flex justify-between text-emerald-800"><span>Kupon {{ $appliedCoupon }}</span><span class="font-medium tabular-nums">−{{ $rp($summary['coupon']) }}</span></div>
        @endif
        @include('livewire.kasir.partials.discount')
        @include('livewire.kasir.partials.coupon')
        @php $rate = str_replace('.', ',', (string) ($summary['tax_rate'] + 0)); @endphp
        @if ($summary['tax'] > 0)
            <div class="flex justify-between text-stone-600"><span>{{ $summary['tax_label'] }} ({{ $rate }}%)</span><span class="tabular-nums">{{ $rp($summary['tax']) }}</span></div>
        @endif
        @if ($summary['rounding'] !== 0)
            <div class="flex justify-between text-stone-600"><span>Pembulatan</span><span class="tabular-nums">{{ $summary['rounding'] > 0 ? '+' : '−' }}{{ $rp(abs($summary['rounding'])) }}</span></div>
        @endif
        <div class="flex items-baseline justify-between pt-1">
            <span class="font-semibold">Total</span>
            <span class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($summary['total']) }}</span>
        </div>
        @if ($summary['tax_included'] > 0)
            <p class="text-right text-xs text-stone-500">Sudah termasuk {{ $summary['tax_label'] }} {{ $rate }}%: {{ $rp($summary['tax_included']) }}</p>
        @endif
    </div>
    </div>

    {{-- Ringkasan ringkas (ponsel): ketuk untuk buka/tutup keranjang --}}
    <button type="button" x-on:click="sheet = !sheet" x-bind:aria-expanded="sheet"
            class="flex items-center justify-between gap-3 rounded-t-2xl border-t border-stone-200 bg-white px-5 pt-3 pb-2 text-left shadow-[0_-8px_30px_rgb(0_0_0/0.12)] md:hidden" x-bind:class="sheet && 'rounded-none! shadow-none!'">
        <span>
            <span class="block text-xs text-stone-500">{{ $summary['count'] }} barang · <span x-text="sheet ? 'Tutup keranjang' : 'Lihat keranjang'"></span></span>
            <span class="text-xl font-bold text-emerald-900 tabular-nums">{{ $rp($summary['total']) }}</span>
        </span>
        <svg class="size-5 text-stone-500 transition" x-bind:class="sheet && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg>
    </button>

    <div class="bg-white px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
        <button type="button" @disabled($summary['count'] === 0) wire:click="openPayment" x-on:click="sheet = false"
                class="w-full rounded-xl bg-emerald-700 py-3.5 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">
            Bayar <span class="ml-1 text-xs font-normal opacity-70">(F9)</span>
        </button>
    </div>

    @include('livewire.kasir.partials.payment-panel')
</aside>
