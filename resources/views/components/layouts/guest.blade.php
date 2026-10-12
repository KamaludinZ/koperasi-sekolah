{{-- Kerangka halaman tanpa sidebar (beranda publik, login). --}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#022c22">
    <title>{{ $title ? $title.' · ' : '' }}{{ \App\Support\MockData::profile()['app_name'] }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|amiri:400,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-stone-50 text-stone-900 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
