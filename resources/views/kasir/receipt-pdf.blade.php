@php $n = fn ($v) => number_format($v, 0, ',', '.'); $rc = $profile['receipt']; @endphp
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
        <div class="b" style="font-size: 8pt; text-transform: uppercase;">{{ $rc['header'] }}</div>
        @if ($rc['subheader'])<div>{{ $rc['subheader'] }}</div>@endif
        @if ($rc['show_address'] && $profile['address'])<div>{{ $profile['address'] }}</div>@endif
        @if ($rc['show_phone'] && ($profile['phone'] ?? null))<div>Telp. {{ $profile['phone'] }}</div>@endif
    </div>

    <div class="hr"></div>

    <table>
        <tr><td>No</td><td class="r">{{ $tx['invoice_number'] }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ \Illuminate\Support\Carbon::parse($tx['created_at'])->format('d/m/Y H:i') }}</td></tr>
        @if ($rc['show_cashier'])<tr><td>Kasir</td><td class="r">{{ $tx['cashier'] }}</td></tr>@endif
        @if ($rc['show_member'] && ($tx['member'] ?? null))<tr><td>Anggota</td><td class="r">{{ $tx['member'] }}</td></tr>@endif
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
            <tr><td>Diskon</td><td class="r">-Rp{{ $n($tx['discount']) }}</td></tr>
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
        <tr><td>Bayar ({{ $tx['payment_method_label'] }})</td><td class="r">Rp{{ $n($tx['paid']) }}</td></tr>
        <tr><td>Kembali</td><td class="r">Rp{{ $n($tx['change']) }}</td></tr>
    </table>

    <div class="hr"></div>

    @if ($rc['note'])<div class="c" style="margin-bottom: 3pt;">{!! nl2br(e($rc['note'])) !!}</div>@endif
    <div class="c">{!! nl2br(e($rc['footer'])) !!}</div>
</body>
</html>
