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
    $user = \App\Support\Petugas::current();
    $menu = \App\Support\Menu::forUser($user);
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
        <nav class="flex-1 space-y-4 overflow-y-auto px-3 pb-2" aria-label="Menu utama">
            @foreach ($menu as $groupKey => $group)
                <div>
                    <p class="ornament-divider mx-2 mb-1.5 text-[10px] font-semibold tracking-[0.18em] text-amber-400/80 uppercase">{{ $group['label'] }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($group['items'] as $key => $item)
                            @php $active = $item['route'] && request()->routeIs($item['route'], $item['route'].'.*'); @endphp
                            <li>
                                @if ($item['route'])
                                    <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                                       @class(['relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                                               'bg-emerald-800 text-white shadow-inner before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-amber-400' => $active,
                                               'text-emerald-200 hover:bg-emerald-900 hover:text-white' => ! $active])>
                                @else
                                    <span class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-emerald-200/50" title="Segera hadir">
                                @endif
                                    <svg class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                                    <span class="flex-1">{{ $item['label'] }}</span>
                                    @if ($key === 'barang' && ($alerts = app(\App\Services\ProductListing::class)->lowStockCount()) > 0)
                                        <span class="rounded-full bg-amber-400 px-1.5 text-[11px] font-semibold text-emerald-950 tabular-nums" title="{{ $alerts }} barang perlu ditambah stoknya">{{ $alerts }}</span>
                                    @elseif (! $item['route'])
                                        <span class="rounded bg-emerald-900 px-1.5 py-0.5 text-[10px] text-emerald-300/70">segera</span>
                                    @endif
                                @if ($item['route'])
                                    </a>
                                @else
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
        <div class="m-3 rounded-xl border border-emerald-800/80 bg-emerald-900/60 p-3">
            @if ($user)
                <div class="flex items-center gap-1.5">
                    <span class="mr-1 grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 text-sm font-bold text-emerald-950">{{ mb_substr(preg_replace('/^(Pak|Bu)\s+/', '', $user['name']), 0, 1) }}</span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="truncate text-sm font-semibold text-white">{{ $user['name'] }}</p>
                        <p class="text-xs text-amber-300">{{ \App\Models\Role::LABELS[$user['role']] ?? $user['role'] }}</p>
                    </div>
                    <a href="{{ route('akun.password') }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-emerald-200 hover:bg-emerald-800 hover:text-white" aria-label="Ganti kata sandi" title="Ganti kata sandi">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3m-3 3 2 2m-5 1 2 2"/></svg>
                    </a>
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
<x-toasts />
@livewireScripts
</body>
</html>
