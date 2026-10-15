@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $cart = $this->cart;
    $inCart = collect($cart['lines'])->pluck('qty', 'id');
@endphp
<div class="space-y-4">
    <h1 class="text-xl font-semibold">Belanja</h1>
    <label class="relative block">
        <span class="sr-only">Cari barang</span>
        <svg class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari barang…"
               class="w-full rounded-xl border border-stone-300 bg-white py-2.5 pr-3 pl-10 text-[15px] outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
    </label>
    <div class="flex gap-2 overflow-x-auto pb-1">
        <button type="button" wire:click="$set('category', '')" @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium', 'bg-emerald-900 text-white' => $category === '', 'border border-stone-300 bg-white text-stone-700' => $category !== ''])>Semua</button>
        @foreach ($this->categories as $cat)
            <button type="button" wire:click="setCategory(@js($cat))" @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium', 'bg-emerald-900 text-white' => $category === $cat, 'border border-stone-300 bg-white text-stone-700' => $category !== $cat])>{{ $cat }}</button>
        @endforeach
    </div>

    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @forelse ($this->products as $p)
            @php $out = $p['stock'] < 1; $qty = $inCart[$p['id']] ?? 0; @endphp
            <li wire:key="pp-{{ $p['id'] }}" @class(['flex flex-col rounded-xl border bg-white p-3.5', 'border-emerald-500 ring-2 ring-emerald-500/20' => $qty, 'border-stone-200' => ! $qty])>
                <span class="text-[11px] text-stone-400">{{ $p['category'] }}</span>
                <span class="mt-0.5 line-clamp-2 text-sm leading-snug font-medium">{{ $p['name'] }}</span>
                <span class="mt-auto pt-2 font-semibold text-emerald-800 tabular-nums">{{ $rp($p['price']) }}</span>
                <span @class(['text-xs', 'text-red-700' => $out, 'text-stone-500' => ! $out])>{{ $out ? 'Stok habis' : ($p['stock'] <= 5 ? 'Sisa '.$p['stock'].' '.$p['unit'] : 'Tersedia') }}</span>
                @if ($qty)
                    <div class="mt-2 flex items-center justify-between rounded-lg bg-emerald-50 p-1">
                        <button type="button" wire:click="setQty({{ $p['id'] }}, {{ $qty - 1 }})" class="grid size-8 place-items-center rounded-md bg-white font-semibold text-emerald-900 shadow-sm" aria-label="Kurangi {{ $p['name'] }}">−</button>
                        <span class="text-sm font-semibold tabular-nums">{{ $qty }}</span>
                        <button type="button" wire:click="setQty({{ $p['id'] }}, {{ $qty + 1 }})" @disabled($qty >= $p['stock']) class="grid size-8 place-items-center rounded-md bg-white font-semibold text-emerald-900 shadow-sm disabled:opacity-40" aria-label="Tambah {{ $p['name'] }}">+</button>
                    </div>
                @else
                    <button type="button" wire:click="add({{ $p['id'] }})" @disabled($out) class="mt-2 rounded-lg bg-emerald-700 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">Tambah</button>
                @endif
            </li>
        @empty
            <li class="col-span-full py-12 text-center text-sm text-stone-500">Barang tidak ditemukan.</li>
        @endforelse
    </ul>

    {{-- Bilah keranjang --}}
    @if ($cart['count'])
        <div class="fixed inset-x-0 bottom-16 z-20 px-4 sm:bottom-4">
            <button type="button" wire:click="$set('cartOpen', true)" data-cart-bar
                    class="mx-auto flex w-full max-w-md items-center justify-between rounded-2xl bg-emerald-900 px-4 py-3 text-white shadow-lg">
                <span class="text-sm"><strong class="tabular-nums">{{ $cart['count'] }}</strong> barang di keranjang</span>
                <span class="font-semibold tabular-nums">{{ $rp($cart['total']) }} →</span>
            </button>
        </div>
    @endif

    @if ($cartOpen)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-950/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="cart-title"
             x-data x-on:keydown.escape.window="$wire.set('cartOpen', false)" wire:click.self="$set('cartOpen', false)">
            <div class="flex max-h-[85dvh] w-full max-w-md flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                    <h2 id="cart-title" class="font-semibold">Keranjang</h2>
                    <button type="button" wire:click="$set('cartOpen', false)" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <ul class="min-h-0 flex-1 divide-y divide-stone-100 overflow-y-auto px-5">
                    @forelse ($cart['lines'] as $line)
                        <li class="flex items-center gap-3 py-3" wire:key="cl-{{ $line['id'] }}">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $line['name'] }}</p>
                                <p class="text-xs text-stone-500 tabular-nums">{{ $rp($line['price']) }} · {{ $rp($line['subtotal']) }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="setQty({{ $line['id'] }}, {{ $line['qty'] - 1 }})" class="grid size-8 place-items-center rounded-md border border-stone-300" aria-label="Kurangi {{ $line['name'] }}">−</button>
                                <span class="w-8 text-center text-sm font-semibold tabular-nums">{{ $line['qty'] }}</span>
                                <button type="button" wire:click="setQty({{ $line['id'] }}, {{ $line['qty'] + 1 }})" @disabled($line['qty'] >= $line['stock']) class="grid size-8 place-items-center rounded-md border border-stone-300 disabled:opacity-40" aria-label="Tambah {{ $line['name'] }}">+</button>
                            </div>
                        </li>
                    @empty
                        <li class="py-10 text-center text-sm text-stone-500">Keranjang kosong.</li>
                    @endforelse
                </ul>
                <div class="space-y-2 border-t border-stone-200 px-5 py-4">
                    <div class="flex justify-between text-base font-semibold"><span>Total</span><span class="tabular-nums">{{ $rp($cart['total']) }}</span></div>
                    <p class="text-xs text-stone-500">Harga & stok bisa berubah sampai pesanan dikirim.</p>
                    @if ($cart['count'] && \App\Support\ImpersonationSession::impersonatingMember())
                        <p class="rounded-xl bg-stone-100 px-3 py-2.5 text-center text-xs text-stone-600" data-read-only>{{ \App\Support\ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE }}</p>
                    @elseif (\Illuminate\Support\Facades\Route::has('portal.checkout') && $cart['count'])
                        <a href="{{ route('portal.checkout') }}" wire:navigate class="block rounded-xl bg-emerald-700 py-2.5 text-center text-sm font-semibold text-white hover:bg-emerald-800">Lanjut ke pembayaran</a>
                    @endif
                    @if ($cart['count'])<button type="button" wire:click="clearCart" wire:confirm="Kosongkan keranjang?" class="w-full text-sm font-medium text-red-700 hover:underline">Kosongkan keranjang</button>@endif
                </div>
            </div>
        </div>
    @endif
</div>
