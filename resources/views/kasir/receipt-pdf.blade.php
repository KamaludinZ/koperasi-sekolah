@php $n = fn ($v) => number_format($v, 0, ',', '.'); @endphp
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
        <div class="b" style="font-size: 8pt; text-transform: uppercase;">{{ $profile['name'] }}</div>
        <div>{{ $profile['school'] }}</div>
        <div>{{ $profile['address'] }}</div>
    </div>

    <div class="hr"></div>

    <table>
        <tr><td>No</td><td class="r">{{ $tx['invoice_number'] }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ \Illuminate\Support\Carbon::parse($tx['created_at'])->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Kasir</td><td class="r">{{ $tx['cashier'] }}</td></tr>
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
        @if ($tx['tax'] > 0)
            <tr><td>Pajak</td><td class="r">Rp{{ $n($tx['tax']) }}</td></tr>
        @endif
        <tr class="b"><td>TOTAL</td><td class="r">Rp{{ $n($tx['total']) }}</td></tr>
        <tr><td>Bayar ({{ $tx['payment_method_label'] }})</td><td class="r">Rp{{ $n($tx['paid']) }}</td></tr>
        <tr><td>Kembali</td><td class="r">Rp{{ $n($tx['change']) }}</td></tr>
    </table>

    <div class="hr"></div>

    <div class="c">{{ $profile['receipt_footer'] }}</div>
</body>
</html>
