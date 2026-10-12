<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#022c22">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ \App\Support\MockData::profile()['short_name'] }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|amiri:400,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-stone-100 text-stone-900 antialiased">
@php
    $nav = [
        ['label' => 'Kasir', 'route' => 'kasir', 'icon' => 'M3 3h18v4H3zM5 7v13h14V7M9 11h6'],
        ['label' => 'Barang & Stok', 'route' => 'barang', 'icon' => 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 14V11'],
        ['label' => 'Transaksi', 'route' => 'transaksi', 'icon' => 'M9 5h10M9 12h10M9 19h10M5 5h.01M5 12h.01M5 19h.01'],
        ['label' => 'Anggota', 'route' => 'anggota', 'icon' => 'M17 20h5v-2a4 4 0 00-5-3.9M9 20H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zM12 7a3 3 0 11-6 0 3 3 0 016 0zm8 2a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z'],
        ['label' => 'Laporan', 'route' => 'laporan', 'icon' => 'M4 20V10m6 10V4m6 16v-7m4 7H2'],
        ['label' => 'Pengaturan', 'route' => null, 'icon' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-2.7 1.1V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-2.7-1.1l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00-1.1-2.7H3a2 2 0 110-4h.1a1.6 1.6 0 001.1-2.7l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 002.7-1.1V3a2 2 0 114 0v.1a1.6 1.6 0 002.7 1.1l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 001.1 2.7h.1a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z'],
    ];
    $user = \App\Support\MockData::currentUser();
@endphp
<div class="flex h-full" x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
    {{-- Latar drawer navigasi (layar kecil) --}}
    <div x-show="nav" x-cloak x-transition.opacity x-on:click="nav = false" class="fixed inset-0 z-40 bg-stone-950/50 lg:hidden"></div>

    <aside class="fixed inset-y-0 left-0 z-50 isolate flex w-64 shrink-0 flex-col overflow-hidden bg-emerald-950 text-emerald-50 transition-transform duration-200 max-lg:-translate-x-full lg:relative lg:w-60"
           x-bind:class="nav && 'max-lg:translate-x-0!'">
        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.07]" aria-hidden="true"></div>
        <a href="{{ route('beranda') }}" class="mx-3 mt-4 mb-2 block rounded-lg px-2 py-2 outline-none hover:bg-emerald-900/60 focus-visible:ring-2 focus-visible:ring-amber-300" title="Beranda koperasi">
            <x-brand.logo size="sm" />
        </a>
        <p class="ornament-divider mx-5 mb-3 text-[10px] font-semibold tracking-[0.2em] text-amber-400/80 uppercase">Menu</p>
        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
            @foreach ($nav as $item)
                @php $active = $item['route'] && request()->routeIs($item['route'], $item['route'].'.*'); @endphp
                @if ($item['route'])
                    <a href="{{ route($item['route']) }}" wire:navigate
                       @class(['relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                               'bg-emerald-800 text-white shadow-inner before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-amber-400' => $active,
                               'text-emerald-200 hover:bg-emerald-900 hover:text-white' => ! $active])>
                @else
                    <span class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-emerald-200/50" title="Segera hadir">
                @endif
                    <svg class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $item['icon'] }}"/></svg>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if ($item['route'] === 'barang' && ($alerts = app(\App\Services\ProductListing::class)->lowStockCount()) > 0)
                        <span class="rounded-full bg-amber-400 px-1.5 text-[11px] font-semibold text-emerald-950 tabular-nums" title="{{ $alerts }} barang perlu ditambah stoknya">{{ $alerts }}</span>
                    @endif
                @if ($item['route'])
                    </a>
                @else
                    </span>
                @endif
            @endforeach
        </nav>
        <div class="m-3 rounded-xl border border-emerald-800/80 bg-emerald-900/60 p-3">
            @if ($user)
                <div class="flex items-center gap-2.5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 text-sm font-bold text-emerald-950">{{ mb_substr(preg_replace('/^(Pak|Bu)\s+/', '', $user['name']), 0, 1) }}</span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="truncate text-sm font-semibold text-white">{{ $user['name'] }}</p>
                        <p class="text-xs text-amber-300">{{ \App\Support\MockData::ROLES[$user['role']] ?? $user['role'] }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="grid size-9 place-items-center rounded-lg text-emerald-200 hover:bg-emerald-800 hover:text-white" aria-label="Keluar" title="Keluar">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 17l5-5-5-5M15 12H3"/></svg>
                        </button>
                    </form>
                </div>
            @else
                <a href="{{ route('login') }}" class="flex items-center justify-center gap-2 rounded-lg bg-amber-400 px-3 py-2 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Masuk petugas</a>
            @endif
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Bar atas (layar kecil) --}}
        <div class="flex items-center gap-3 bg-emerald-950 px-3 py-2.5 text-white lg:hidden">
            <button type="button" x-on:click="nav = true" aria-label="Buka menu"
                    class="grid size-10 place-items-center rounded-lg hover:bg-emerald-900">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <x-brand.mark class="size-7 shrink-0" />
            <span class="truncate text-sm font-semibold">{{ $title ?? \App\Support\MockData::profile()['short_name'] }}</span>
        </div>

        {{ $slot }}
    </div>
</div>
@livewireScripts
</body>
</html>
