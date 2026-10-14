<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label barcode · {{ \App\Support\MockData::profile()['app_name'] }}</title>
    <x-brand.favicon />
    @vite(['resources/css/app.css'])
    <style>
        @page { size: {{ $f['page_w'] }}mm {{ $f['page_h'] }}mm; margin: 0; }
        @media print { .label-sheet { break-after: page; } .label-sheet:last-child { break-after: auto; } }
    </style>
</head>
<body class="min-h-screen bg-stone-100 py-6 print:bg-white print:py-0">
    <div class="mx-auto mb-4 flex max-w-xl flex-wrap items-center justify-center gap-2 px-4 text-sm print:hidden">
        <p class="w-full text-center text-stone-600">{{ $f['label'] }} · {{ $total }} label · {{ count($pages) }} lembar.
            Atur skala cetak ke <strong>100%</strong> dan matikan header/footer peramban.</p>
        @if ($truncated)<p class="w-full text-center text-amber-800">Hanya {{ \App\Support\LabelFormats::MAX_LABELS }} label pertama yang dicetak.</p>@endif
        <button type="button" onclick="window.print()" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">Cetak</button>
        <a href="{{ $back }}" class="rounded-lg border border-stone-300 bg-white px-4 py-2 font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
    </div>
    <div class="flex flex-col items-center gap-4 print:block">
        @forelse ($pages as $page)
            <div class="shadow-sm ring-1 ring-stone-200 print:shadow-none print:ring-0">
                @include('barang.partials.label-sheet', ['f' => $f, 'page' => $page, 'showName' => $showName, 'showPrice' => $showPrice])
            </div>
        @empty
            <p class="text-sm text-stone-500">Tidak ada label untuk dicetak.</p>
        @endforelse
    </div>
    @if ($autoPrint && $pages)
        <script>window.addEventListener('load', () => window.print())</script>
    @endif
</body>
</html>
