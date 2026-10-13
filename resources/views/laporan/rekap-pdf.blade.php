{{-- Rekap penjualan PDF (A4). Data dari SalesExport::data(). --}}
@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); $num = fn ($n) => number_format($n, 0, ',', '.'); @endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Penjualan {{ $period }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c1917; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #065f46; margin: 18px 0 6px; border-bottom: 1px solid #a7f3d0; padding-bottom: 3px; }
        .muted { color: #78716c; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: bold; background: #f5f5f4; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e7e5e4; }
        .r { text-align: right; }
        .summary td { border: none; padding: 3px 6px; }
        .summary .v { font-weight: bold; text-align: right; }
        tfoot td { font-weight: bold; border-top: 1px solid #a8a29e; }
        .head { border-bottom: 2px solid #065f46; padding-bottom: 8px; }
    </style>
</head>
<body>
    @php $logoData = \App\Services\LogoService::dataUri(app(\App\Services\SettingsService::class)->section('profile')['logo']); @endphp
    <div class="head">
        @if ($logoData)<img src="{{ $logoData }}" alt="" style="float: right; width: 40pt; height: 40pt;">@endif
        <h1>Rekap Penjualan</h1>
        <div>{{ $profile['name'] }} · {{ $profile['school'] }}</div>
        <div class="muted">Periode {{ $period }} · dibuat {{ $generated_at }}</div>
    </div>

    @if ($summary)
        <h2>Ringkasan</h2>
        <table class="summary" style="width: 60%">
            <tr><td>Total penjualan</td><td class="v">{{ $rp($summary['revenue']) }}</td></tr>
            <tr><td>Laba kotor</td><td class="v">{{ $rp($summary['gross_profit']) }} ({{ $summary['margin'] }}%)</td></tr>
            <tr><td>Jumlah transaksi</td><td class="v">{{ $num($summary['count']) }}</td></tr>
            <tr><td>Barang terjual</td><td class="v">{{ $num($summary['items']) }}</td></tr>
            <tr><td>Rata-rata per transaksi</td><td class="v">{{ $rp($summary['average']) }}</td></tr>
        </table>
    @endif

    @if ($daily !== null)
        <h2>Rekap per hari</h2>
        <table>
            <thead><tr><th>Tanggal</th><th class="r">Transaksi</th><th class="r">Barang</th><th class="r">Penjualan</th></tr></thead>
            <tbody>
                @forelse ($daily as $d)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($d['date'])->translatedFormat('l, d M Y') }}</td>
                        <td class="r">{{ $num($d['count']) }}</td><td class="r">{{ $num($d['items']) }}</td><td class="r">{{ $rp($d['revenue']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Tidak ada penjualan.</td></tr>
                @endforelse
            </tbody>
            @if (count($daily) > 1)
                <tfoot><tr>
                    <td>Total</td><td class="r">{{ $num(array_sum(array_column($daily, 'count'))) }}</td>
                    <td class="r">{{ $num(array_sum(array_column($daily, 'items'))) }}</td><td class="r">{{ $rp(array_sum(array_column($daily, 'revenue'))) }}</td>
                </tr></tfoot>
            @endif
        </table>
    @endif

    @if ($products !== null)
        <h2>Barang terjual</h2>
        <table>
            <thead><tr><th>Barang</th><th class="r">Jumlah</th><th class="r">Penjualan</th><th class="r">Laba kotor</th></tr></thead>
            <tbody>
                @forelse ($products as $p)
                    <tr><td>{{ $p['name'] }}</td><td class="r">{{ $num($p['quantity']) }}</td><td class="r">{{ $rp($p['revenue']) }}</td><td class="r">{{ $rp($p['profit']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="muted">Tidak ada barang terjual.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if ($discounts ?? null)
        @php $ds = $discounts['summary']; @endphp
        <h2>Diskon & promo</h2>
        <table>
            <tbody>
                <tr><td>Penjualan kotor (sebelum diskon)</td><td class="r">{{ $rp($ds['gross_sales']) }}</td></tr>
                <tr><td>Total potongan ({{ str_replace('.', ',', (string) $ds['discount_rate']) }}%)</td><td class="r">{{ $rp($ds['total_discount']) }}</td></tr>
                <tr><td>Penjualan bersih</td><td class="r">{{ $rp($ds['net_sales']) }}</td></tr>
                <tr><td>Transaksi berdiskon</td><td class="r">{{ $num($ds['discounted_transactions']) }} ({{ $ds['discounted_share'] }}%)</td></tr>
                <tr><td>Rata-rata belanja berdiskon / tanpa diskon</td><td class="r">{{ $rp($ds['avg_discounted']) }} / {{ $rp($ds['avg_plain']) }}</td></tr>
            </tbody>
        </table>
        <table>
            <thead><tr><th>Sumber</th><th class="r">Pemakaian</th><th class="r">Potongan</th></tr></thead>
            <tbody>
                @foreach ($discounts['by_source'] as $src)
                    <tr><td>{{ $src['label'] }}</td><td class="r">{{ $num($src['count']) }}</td><td class="r">{{ $rp($src['amount']) }}</td></tr>
                @endforeach
                @foreach (['promos' => 'Promo', 'coupons' => 'Kupon'] as $key => $title)
                    @foreach ($discounts[$key] as $r)
                        <tr><td class="muted">{{ $title }}: {{ $r['label'] }}</td><td class="r">{{ $num($r['uses']) }}</td><td class="r">{{ $rp($r['amount']) }}</td></tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($transactions !== null)
        <h2>Rincian transaksi</h2>
        <table>
            <thead><tr><th>Waktu</th><th>No. invoice</th><th>Kasir</th><th>Anggota</th><th>Metode</th><th class="r">Barang</th><th class="r">Total</th></tr></thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($t['created_at'])->format('d/m/Y H:i') }}</td>
                        <td>{{ $t['invoice_number'] }}</td><td>{{ $t['cashier'] }}</td><td>{{ $t['member'] ?? '—' }}</td>
                        <td>{{ $t['payment_method_label'] }}</td><td class="r">{{ $t['item_count'] }}</td><td class="r">{{ $rp($t['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">Tidak ada transaksi.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
