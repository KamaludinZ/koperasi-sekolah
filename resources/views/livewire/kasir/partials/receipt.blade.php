{{-- Struk pembelian (format kertas thermal). Butuh: $tx (lastTransaction), $profile, $rp. --}}
<div class="print-area font-mono text-[11px] leading-snug text-black">
    <div class="text-center">
        <p class="text-xs font-bold uppercase">{{ $profile['name'] }}</p>
        <p>{{ $profile['school'] }}</p>
        <p>{{ $profile['address'] }}</p>
    </div>

    <div class="my-2 border-t border-dashed border-black"></div>

    <div class="space-y-0.5">
        <div class="flex justify-between"><span>No</span><span>{{ $tx['invoice_number'] }}</span></div>
        <div class="flex justify-between"><span>Tgl</span><span>{{ \Illuminate\Support\Carbon::parse($tx['created_at'])->format('d/m/Y H:i') }}</span></div>
        <div class="flex justify-between"><span>Kasir</span><span>{{ $tx['cashier'] }}</span></div>
    </div>

    <div class="my-2 border-t border-dashed border-black"></div>

    <ul class="space-y-1">
        @foreach ($tx['items'] as $item)
            <li>
                <p>{{ $item['product_name'] }}</p>
                <div class="flex justify-between">
                    <span>{{ $item['quantity'] }} x {{ number_format($item['price'], 0, ',', '.') }}</span>
                    <span>{{ number_format($item['subtotal'], 0, ',', '.') }}</span>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="my-2 border-t border-dashed border-black"></div>

    <div class="space-y-0.5">
        <div class="flex justify-between"><span>Subtotal</span><span>{{ $rp($tx['subtotal']) }}</span></div>
        @if ($tx['tax'] > 0)
            <div class="flex justify-between"><span>Pajak</span><span>{{ $rp($tx['tax']) }}</span></div>
        @endif
        <div class="flex justify-between font-bold"><span>TOTAL</span><span>{{ $rp($tx['total']) }}</span></div>
        <div class="flex justify-between"><span>Bayar ({{ \App\Livewire\Kasir\Cart::PAYMENT_METHODS[$tx['payment_method']] }})</span><span>{{ $rp($tx['paid']) }}</span></div>
        <div class="flex justify-between"><span>Kembali</span><span>{{ $rp($tx['change']) }}</span></div>
    </div>

    <div class="my-2 border-t border-dashed border-black"></div>

    <p class="text-center">{{ $profile['receipt_footer'] }}</p>
</div>
