<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 14px; margin: 0; } .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #ddd; padding: 4px 6px; text-align: left; } th { background: #f4f4f4; }
        .r { text-align: right; } .ok { color: #065f46; } .no { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Setoran bulanan {{ $type->name }} · {{ $month }}</h1>
    <p class="muted">{{ $profile['name'] }} · dicetak {{ now()->translatedFormat('d F Y H:i') }}</p>
    <p>Sudah disetor <strong>{{ $summary['posted'] }} / {{ $summary['members'] }}</strong> anggota ·
        total <strong>{{ \App\Support\Rupiah::format($summary['posted_amount']) }}</strong> · belum disetor <strong>{{ $summary['missing'] }}</strong></p>
    <table>
        <thead><tr><th>Kode</th><th>Nama</th><th class="r">Nominal</th><th>Status</th><th>Diposting</th></tr></thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['code'] }}</td><td>{{ $r['name'] }}</td>
                    <td class="r">{{ $r['status'] === 'kosong' ? '—' : \App\Support\Rupiah::format($r['amount']) }}</td>
                    <td class="{{ $r['status'] === 'disetor' ? 'ok' : 'no' }}">{{ $status[$r['status']] }}</td>
                    <td>{{ $r['posted_at'] ? \Illuminate\Support\Carbon::parse($r['posted_at'])->format('d/m/Y H:i') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Tidak ada anggota guru/karyawan aktif.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
