{{-- PDF label barcode (dompdf): tabel dengan sel berukuran mm, satu lembar per halaman. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
        .sheet { padding-top: {{ $f['top'] }}mm; padding-left: {{ $f['left'] }}mm; }
        .sheet + .sheet { page-break-before: always; }
        table { border-collapse: collapse; table-layout: fixed; }
        /* dompdf: padding menambah ukuran sel, jadi lebar/tinggi dikurangi padding agar lembar tidak meluap. */
        td { width: {{ $f['label_w'] - 4 }}mm; height: {{ $f['label_h'] - 2.6 }}mm; padding: 1.2mm 2mm; text-align: center; vertical-align: middle; overflow: hidden; }
        .name { font-size: 2.5mm; line-height: 1.15; max-height: 6mm; overflow: hidden; }
        .bars { width: 86%; height: {{ $f['barcode_h'] }}mm; margin: 0.6mm auto 0; }
        .value { font-family: DejaVu Sans Mono, monospace; font-size: 2.3mm; letter-spacing: 0.3mm; }
        .price { font-size: 3.1mm; font-weight: bold; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="sheet">
        <table>
            @foreach (array_chunk($page, $f['cols']) as $row)
                <tr>
                    @foreach ($row as $l)
                        <td>
                            @if ($showName)<div class="name">{{ \Illuminate\Support\Str::limit($l['name'], 48) }}</div>@endif
                            @if ($images[$l['barcode']] ?? null)<img class="bars" src="{{ $images[$l['barcode']] }}" alt="">@endif
                            <div class="value">{{ $l['barcode'] }}</div>
                            @if ($showPrice)<div class="price">{{ \App\Support\Rupiah::format($l['price']) }}</div>@endif
                        </td>
                    @endforeach
                    @for ($i = count($row); $i < $f['cols']; $i++)<td></td>@endfor
                </tr>
            @endforeach
        </table>
    </div>
@endforeach
</body>
</html>
