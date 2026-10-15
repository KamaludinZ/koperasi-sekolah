{{-- PDF rekap pinjaman (LoanRecapExport::pdf): ringkasan periode, posisi saat ini, per metode, per bulan, rincian (dibatasi). --}}
@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $c = $r['collected']; $p = $r['portfolio']; @endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 13px; margin: 0; } h2 { font-size: 10px; margin: 12px 0 0; } .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ddd; padding: 3px 4px; } th { background: #f4f4f4; text-align: left; }
        .r { text-align: right; } .no { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Rekap Pinjaman · {{ $label }}</h1>
    <p class="muted">{{ $profile['name'] }} · periode {{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }} · dicetak {{ now()->translatedFormat('d F Y H:i') }}</p>

    <h2>Ringkasan periode</h2>
    <table>
        <tr><th>Pengajuan masuk</th><td class="r">{{ $r['applications']['count'] }} ({{ $rp($r['applications']['amount']) }})</td><th>Disetujui / ditolak</th><td class="r">{{ $r['applications']['approved'] }} / {{ $r['applications']['rejected'] }}</td></tr>
        <tr><th>Dana dicairkan</th><td class="r">{{ $rp($r['disbursed']['amount']) }} · {{ $r['disbursed']['count'] }} pinjaman</td><th>Angsuran diterima</th><td class="r">{{ $rp($c['amount']) }} · {{ $c['count'] }} pembayaran</td></tr>
        <tr><th>Porsi pokok</th><td class="r">{{ $rp($c['principal']) }}</td><th>Porsi bunga</th><td class="r">{{ $rp($c['interest']) }}</td></tr>
    </table>

    <h2>Posisi saat ini</h2>
    <table>
        <tr><th>Pinjaman aktif</th><td class="r">{{ $p['active'] }} · sisa {{ $rp($p['outstanding']) }}</td><th>Menunggak</th><td class="r {{ $p['overdue'] ? 'no' : '' }}">{{ $p['overdue'] }} · {{ $rp($p['overdue_outstanding']) }}</td></tr>
        <tr><th>Dana tercairkan (seluruhnya)</th><td class="r">{{ $rp($p['disbursed_total']) }}</td><th>Angsuran kembali</th><td class="r">{{ $rp($p['collected_total']) }} ({{ str_replace('.', ',', (string) $p['collection_rate']) }}%)</td></tr>
    </table>

    <h2>Angsuran per metode bayar</h2>
    <table>
        <thead><tr><th>Metode</th><th class="r">Pembayaran</th><th class="r">Diterima</th><th class="r">Pokok</th><th class="r">Bunga</th><th class="r">Bagian</th><th class="r">Aktif · sisa</th></tr></thead>
        <tbody>
            @foreach ($c['by_method'] as $m)
                <tr><td>{{ $m['label'] }}</td><td class="r">{{ $m['count'] }}</td><td class="r">{{ $rp($m['amount']) }}</td><td class="r">{{ $rp($m['principal']) }}</td>
                    <td class="r">{{ $rp($m['interest']) }}</td><td class="r">{{ str_replace('.', ',', (string) $m['share']) }}%</td><td class="r">{{ $m['active_loans'] }} · {{ $rp($m['active_outstanding']) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th>Total</th><th class="r">{{ $c['count'] }}</th><th class="r">{{ $rp($c['amount']) }}</th><th class="r">{{ $rp($c['principal']) }}</th><th class="r">{{ $rp($c['interest']) }}</th><th></th><th class="r">{{ $p['active'] }} · {{ $rp($p['outstanding']) }}</th></tr></tfoot>
    </table>

    @if ($r['months'])
        <h2>Per bulan</h2>
        <table>
            <thead><tr><th>Bulan</th><th class="r">Dicairkan</th><th class="r">Diterima</th><th class="r">Pembayaran</th></tr></thead>
            <tbody>@foreach ($r['months'] as $m)<tr><td>{{ $m['label'] }}</td><td class="r">{{ $rp($m['disbursed']) }}</td><td class="r">{{ $rp($m['collected']) }}</td><td class="r">{{ $m['count'] }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    <h2>Rincian pembayaran angsuran{{ $payments_truncated ? ' (ditampilkan '.count($payments).' terbaru; selengkapnya di ekspor CSV)' : '' }}</h2>
    <table>
        <thead><tr><th>Tanggal</th><th>Pinjaman</th><th>Anggota</th><th class="r">Nominal</th><th>Metode</th><th>Rujukan</th><th>Angsuran ke-</th></tr></thead>
        <tbody>
            @forelse ($payments as $x)
                <tr><td>{{ $x['date'] }}</td><td>{{ $x['code'] }}</td><td>{{ $x['member'] }}</td><td class="r">{{ $rp($x['amount']) }}</td><td>{{ $x['method'] }}</td><td>{{ $x['reference'] }}</td><td>{{ $x['sequences'] }}</td></tr>
            @empty
                <tr><td colspan="7" class="muted">Tidak ada pembayaran pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
