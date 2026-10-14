<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 13px; margin: 0; } .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ddd; padding: 3px 4px; } th { background: #f4f4f4; }
        .c { text-align: center; } .r { text-align: right; }
        .ok { color: #065f46; font-weight: bold; } .pend { color: #92400e; } .no { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Rekap setoran bulanan {{ $type->name }}</h1>
    <p class="muted">{{ $profile['name'] }} · dicetak {{ now()->translatedFormat('d F Y H:i') }} · Sudah = disetor, Dicatat = belum diposting, Belum = belum dicatat</p>
    <table>
        <thead><tr><th>Kode</th><th>Nama</th>@foreach ($m['periods'] as $p)<th class="c">{{ $p['label'] }}</th>@endforeach<th class="r">Belum</th></tr></thead>
        <tbody>
            @forelse ($m['rows'] as $r)
                <tr><td>{{ $r['code'] }}</td><td>{{ $r['name'] }}</td>
                    @foreach ($m['periods'] as $p)
                        @php $c = $r['cells'][$p['key']]; @endphp
                        <td class="c {{ ['disetor' => 'ok', 'belum' => 'pend', 'kosong' => 'no'][$c] ?? 'muted' }}">{{ $c ? $cell[$c] : '·' }}</td>
                    @endforeach
                    <td class="r">{{ $r['missing'] }}</td></tr>
            @empty
                <tr><td colspan="{{ count($m['periods']) + 3 }}" class="muted">Tidak ada anggota guru/karyawan aktif.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr><th colspan="2">Sudah disetor</th>@foreach ($m['periods'] as $p)<th class="c">{{ $m['totals'][$p['key']]['paid'] }}/{{ $m['totals'][$p['key']]['eligible'] }}</th>@endforeach<th></th></tr>
            <tr><th colspan="2">Total</th>@foreach ($m['periods'] as $p)<th class="c">{{ \App\Support\Rupiah::format($m['totals'][$p['key']]['amount']) }}</th>@endforeach<th></th></tr>
        </tfoot>
    </table>
</body>
</html>
