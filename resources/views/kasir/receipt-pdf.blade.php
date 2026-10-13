@php $n = fn ($v) => \App\Support\Rupiah::number($v); $rc = $profile['receipt']; @endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $tx['invoice_number'] }}</title>
    <style>
        @page { margin: 8pt 7pt; }
        body { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; line-height: 1.35; color: #000; margin: 0; }
        .c { text-align: center; }
        .b { font-weight: bold; }
        .hr { border-top: 1px dashed #000; margin: 5pt 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0; vertical-align: top; }
        td.r { text-align: right; white-space: nowrap; }
        .item { margin-bottom: 2pt; }
    </style>
</head>
<body>
    <div class="c">
        @if (! empty($profile['logo_data']))
            <img src="{{ $profile['logo_data'] }}" alt="" style="width: 34pt; height: 34pt; margin-bottom: 3pt;">
        @endif
        <div class="b" style="font-size: 8pt; text-transform: uppercase;">{{ $rc['header'] }}</div>
        @if ($rc['subheader'])<div>{{ $rc['subheader'] }}</div>@endif
        @if ($rc['show_address'] && $profile['address'])<div>{{ $profile['address'] }}</div>@endif
        @if ($rc['show_phone'] && ($profile['phone'] ?? null))<div>Telp. {{ $profile['phone'] }}</div>@endif
    </div>

    <div class="hr"></div>

    <table>
        <tr><td>No</td><td class="r">{{ $tx['invoice_number'] }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ \Illuminate\Support\Carbon::parse($tx['created_at'])->format('d/m/Y H:i') }}</td></tr>
        @php $memberPay = in_array($tx['payment_method'] ?? null, ['tabungan', 'bon'], true); @endphp
        @if ($rc['show_cashier'] || $memberPay)<tr><td>Petugas</td><td class="r">{{ $tx['cashier'] }}</td></tr>@endif
        @if (($rc['show_member'] || $memberPay) && ($tx['member'] ?? null))<tr><td>Anggota</td><td class="r">{{ $tx['member'] }}@if ($tx['member_code'] ?? null) ({{ $tx['member_code'] }})@endif</td></tr>@endif
    </table>

    <div class="hr"></div>

    @foreach ($tx['items'] as $item)
        <div class="item">
            <div>{{ $item['product_name'] }}</div>
            <table><tr>
                <td>{{ $item['quantity'] }} x {{ $n($item['price']) }}</td>
                <td class="r">{{ $n($item['subtotal']) }}</td>
            </tr></table>
        </div>
    @endforeach

    <div class="hr"></div>

    <table>
        <tr><td>Subtotal</td><td class="r">Rp{{ $n($tx['subtotal']) }}</td></tr>
        @if ($tx['discount'] > 0)
            @forelse ($tx['discounts'] ?? [] as $d)
                <tr><td>{{ $d['label'] }}{{ $d['source'] === 'manual' && $d['reason'] ? ' ('.$d['reason'].')' : '' }}</td><td class="r">-Rp{{ $n($d['amount']) }}</td></tr>
            @empty
                <tr><td>Diskon{{ ($tx['discount_reason'] ?? null) ? ' ('.$tx['discount_reason'].')' : '' }}</td><td class="r">-Rp{{ $n($tx['discount']) }}</td></tr>
            @endforelse
        @endif
        @php $taxName = ($tx['tax_label'] ?? null ?: 'Pajak').(($tx['tax_rate'] ?? 0) > 0 ? ' '.str_replace('.', ',', (string) ($tx['tax_rate'] + 0)).'%' : ''); @endphp
        @if ($tx['tax'] > 0)
            <tr><td>{{ $taxName }}</td><td class="r">Rp{{ $n($tx['tax']) }}</td></tr>
        @endif
        @if (($tx['rounding'] ?? 0) !== 0)
            <tr><td>Pembulatan</td><td class="r">{{ $tx['rounding'] < 0 ? '-' : '' }}Rp{{ $n(abs($tx['rounding'])) }}</td></tr>
        @endif
        <tr class="b"><td>TOTAL</td><td class="r">Rp{{ $n($tx['total']) }}</td></tr>
        @if (($tx['tax_included'] ?? 0) > 0)
            <tr><td>Termasuk {{ $taxName }}</td><td class="r">Rp{{ $n($tx['tax_included']) }}</td></tr>
        @endif
        <tr><td>Metode</td><td class="r">{{ $tx['payment_method_label'] }}</td></tr>
        @switch ($tx['payment_method'] ?? 'tunai')
            @case ('tabungan')
                @isset ($tx['balance_before'])<tr><td>Saldo awal</td><td class="r">Rp{{ $n($tx['balance_before']) }}</td></tr>@endisset
                <tr><td>Dipotong</td><td class="r">-Rp{{ $n($tx['total']) }}</td></tr>
                @isset ($tx['balance_after'])<tr><td>Sisa saldo</td><td class="r">Rp{{ $n($tx['balance_after']) }}</td></tr>@endisset
                @break
            @case ('bon')
                <tr><td>Dicatat bon</td><td class="r">Rp{{ $n($tx['total']) }}</td></tr>
                @isset ($tx['credit_number'])<tr><td>No. bon</td><td class="r">{{ $tx['credit_number'] }}</td></tr>@endisset
                @isset ($tx['credit_due_at'])<tr><td>Jatuh tempo</td><td class="r">{{ \Illuminate\Support\Carbon::parse($tx['credit_due_at'])->format('d/m/Y') }}</td></tr>@endisset
                @break
            @case ('tunai')
                <tr><td>Bayar</td><td class="r">Rp{{ $n($tx['paid']) }}</td></tr>
                <tr><td>Kembali</td><td class="r">Rp{{ $n($tx['change']) }}</td></tr>
                @break
            @default
                <tr><td>Dibayar</td><td class="r">Rp{{ $n($tx['paid']) }}</td></tr>
        @endswitch
    </table>
    @if ($tx['discount'] > 0)
        <div class="c b" style="margin-top: 3pt;">Anda hemat Rp{{ $n($tx['discount']) }}</div>
    @endif

    <div class="hr"></div>

    @if (($tx['payment_method'] ?? null) === 'bon')
        <table style="margin-bottom: 3pt;"><tr>
            <td class="c" style="width: 50%;">Anggota<br><br><br>{{ \Illuminate\Support\Str::limit($tx['member'] ?? '', 18) }}</td>
            <td class="c" style="width: 50%;">Petugas<br><br><br>{{ \Illuminate\Support\Str::limit($tx['cashier'], 18) }}</td>
        </tr></table>
        <div class="hr"></div>
    @endif
    @if ($rc['note'])<div class="c" style="margin-bottom: 3pt;">{!! nl2br(e($rc['note'])) !!}</div>@endif
    <div class="c">{!! nl2br(e($rc['footer'])) !!}</div>
</body>
</html>
