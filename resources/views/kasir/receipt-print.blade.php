@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $tx['invoice_number'] }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-100 py-6 print:bg-white print:py-0">
    <div class="mx-auto w-[58mm] bg-white p-3 shadow-sm ring-1 ring-stone-200 print:shadow-none print:ring-0">
        @include('livewire.kasir.partials.receipt')
    </div>
    <div class="mt-4 flex justify-center gap-2 print:hidden">
        <button type="button" onclick="window.print()" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Cetak</button>
        <a href="{{ route('kasir') }}" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali ke kasir</a>
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print())</script>
    @endif
</body>
</html>
