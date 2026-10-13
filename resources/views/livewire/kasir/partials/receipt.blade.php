{{-- Struk pembelian (kertas thermal 58/80 mm). Butuh: $tx, $profile (ReceiptPresenter::profile), $rp. --}}
@php $rc = $profile['receipt'] ?? ['header' => $profile['name'], 'subheader' => $profile['school'], 'show_address' => true, 'show_phone' => false, 'show_cashier' => true, 'show_member' => false, 'note' => null, 'footer' => $profile['receipt_footer'], 'paper' => '58mm']; @endphp
<div class="print-area font-mono text-[11px] leading-snug text-black" data-paper="{{ $rc['paper'] }}">
    <div class="text-center">
        @if (($rc['show_logo'] ?? false) && ($profile['logo'] ?? null))
            <img src="{{ $profile['logo'] }}" alt="" class="mx-auto mb-1 size-12 object-contain grayscale">
        @endif
        <p class="text-xs font-bold uppercase">{{ $rc['header'] }}</p>
        @if ($rc['subheader'])<p>{{ $rc['subheader'] }}</p>@endif
        @if ($rc['show_address'] && $profile['address'])<p>{{ $profile['address'] }}</p>@endif
        @if ($rc['show_phone'] && ($profile['phone'] ?? null))<p>Telp. {{ $profile['phone'] }}</p>@endif
    </div>

    <div class="my-2 border-t border-dashed border-black"></div>

    <div class="space-y-0.5">
        <div class="flex justify-between"><span>No</span><span>{{ $tx['invoice_number'] }}</span></div>
        <div class="flex justify-between"><span>Tgl</span><span>{{ \Illuminate\Support\Carbon::parse($tx['created_at'])->format('d/m/Y H:i') }}</span></div>
        @php $memberPay = in_array($tx['payment_method'] ?? null, ['tabungan', 'bon'], true); @endphp
        {{-- Tabungan & bon selalu mencantumkan petugas & anggota sebagai bukti transaksi atas nama anggota. --}}
        @if ($rc['show_cashier'] || $memberPay)<div class="flex justify-between gap-2"><span>Petugas</span><span class="text-right">{{ $tx['cashier'] }}</span></div>@endif
        @if (($rc['show_member'] || $memberPay) && ($tx['member'] ?? null))<div class="flex justify-between gap-2"><span>Anggota</span><span class="text-right">{{ $tx['member'] }}@if ($tx['member_code'] ?? null) ({{ $tx['member_code'] }})@endif</span></div>@endif
    </div>

    <div class="my-2 border-t border-dashed border-black"></div>

    <ul class="space-y-1">
        @foreach ($tx['items'] as $item)
            <li>
                <p>{{ $item['product_name'] }}</p>
                <div class="flex justify-between">
                    <span>{{ $item['quantity'] }} x {{ \App\Support\Rupiah::number($item['price']) }}</span>
                    <span>{{ \App\Support\Rupiah::number($item['subtotal']) }}</span>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="my-2 border-t border-dashed border-black"></div>

    <div class="space-y-0.5">
        <div class="flex justify-between"><span>Subtotal</span><span>{{ $rp($tx['subtotal']) }}</span></div>
        @if (($tx['discount'] ?? 0) > 0)
            @forelse ($tx['discounts'] ?? [] as $d)
                <div class="flex justify-between gap-2"><span class="min-w-0">{{ $d['label'] }}{{ $d['source'] === 'manual' && $d['reason'] ? ' ('.$d['reason'].')' : '' }}</span><span class="shrink-0">-{{ $rp($d['amount']) }}</span></div>
            @empty
                <div class="flex justify-between gap-2"><span class="min-w-0">Diskon{{ ($tx['discount_reason'] ?? null) ? ' ('.$tx['discount_reason'].')' : '' }}</span><span class="shrink-0">-{{ $rp($tx['discount']) }}</span></div>
            @endforelse
        @endif
        @php $taxName = ($tx['tax_label'] ?? null ?: 'Pajak').(($tx['tax_rate'] ?? 0) > 0 ? ' '.str_replace('.', ',', (string) ($tx['tax_rate'] + 0)).'%' : ''); @endphp
        @if ($tx['tax'] > 0)
            <div class="flex justify-between"><span>{{ $taxName }}</span><span>{{ $rp($tx['tax']) }}</span></div>
        @endif
        @if (($tx['rounding'] ?? 0) !== 0)
            <div class="flex justify-between"><span>Pembulatan</span><span>{{ $tx['rounding'] > 0 ? '' : '-' }}{{ $rp(abs($tx['rounding'])) }}</span></div>
        @endif
        <div class="flex justify-between font-bold"><span>TOTAL</span><span>{{ $rp($tx['total']) }}</span></div>
        @if (($tx['tax_included'] ?? 0) > 0)
            <div class="flex justify-between text-[0.9em]"><span>Termasuk {{ $taxName }}</span><span>{{ $rp($tx['tax_included']) }}</span></div>
        @endif
        <div class="flex justify-between gap-2"><span>Metode</span><span class="text-right">{{ $tx['payment_method_label'] }}</span></div>
        @switch ($tx['payment_method'] ?? 'tunai')
            @case ('tabungan')
                @isset ($tx['balance_before'])<div class="flex justify-between"><span>Saldo awal</span><span>{{ $rp($tx['balance_before']) }}</span></div>@endisset
                <div class="flex justify-between"><span>Dipotong</span><span>-{{ $rp($tx['total']) }}</span></div>
                @isset ($tx['balance_after'])<div class="flex justify-between"><span>Sisa saldo</span><span>{{ $rp($tx['balance_after']) }}</span></div>@endisset
                @break
            @case ('bon')
                <div class="flex justify-between"><span>Dicatat bon</span><span>{{ $rp($tx['total']) }}</span></div>
                @isset ($tx['credit_number'])<div class="flex justify-between"><span>No. bon</span><span>{{ $tx['credit_number'] }}</span></div>@endisset
                @isset ($tx['credit_due_at'])<div class="flex justify-between"><span>Jatuh tempo</span><span>{{ \Illuminate\Support\Carbon::parse($tx['credit_due_at'])->format('d/m/Y') }}</span></div>@endisset
                @break
            @case ('tunai')
                <div class="flex justify-between"><span>Bayar</span><span>{{ $rp($tx['paid']) }}</span></div>
                <div class="flex justify-between"><span>Kembali</span><span>{{ $rp($tx['change']) }}</span></div>
                @break
            @default
                <div class="flex justify-between"><span>Dibayar</span><span>{{ $rp($tx['paid']) }}</span></div>
        @endswitch
        @if (($tx['discount'] ?? 0) > 0)
            <p class="pt-1 text-center font-bold">Anda hemat {{ $rp($tx['discount']) }}</p>
        @endif
    </div>

    <div class="my-2 border-t border-dashed border-black"></div>

    @if (($tx['payment_method'] ?? null) === 'bon')
        <div class="mb-2 grid grid-cols-2 gap-2 text-center">
            <div><p>Anggota</p><p class="mt-6 border-t border-black pt-0.5">{{ \Illuminate\Support\Str::limit($tx['member'] ?? '', 18) }}</p></div>
            <div><p>Petugas</p><p class="mt-6 border-t border-black pt-0.5">{{ \Illuminate\Support\Str::limit($tx['cashier'], 18) }}</p></div>
        </div>
        <div class="my-2 border-t border-dashed border-black"></div>
    @endif
    @if ($rc['note'])<p class="mb-1.5 text-center whitespace-pre-line">{{ $rc['note'] }}</p>@endif
    <p class="text-center whitespace-pre-line">{{ $rc['footer'] }}</p>
</div>
