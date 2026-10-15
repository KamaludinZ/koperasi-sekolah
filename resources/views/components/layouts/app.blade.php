<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#022c22">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ \App\Support\MockData::profile()['app_name'] }}</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|amiri:400,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-stone-100 text-stone-900 antialiased">
@php
    $user = \App\Support\Petugas::current();
    $menu = \App\Support\Menu::forUser($user);
    // petugas multi-peran: tandai menu & halaman yang hanya terbuka lewat sebagian perannya
    $multiRole = $user && count($user['roles'] ?? []) > 1;
    $menuSources = $user ? \App\Support\Menu::sourcesFor($user) : [];
    $heldRoles = $user ? ($user['roles'] ?? array_filter([$user['role']])) : [];
    $pageScope = $multiRole ? \App\Support\Menu::pageScope(request()->route()?->getName(), $user) : null;
    $roleList = fn (array $names) => collect($names)->map(fn ($r) => \App\Models\Role::LABELS[$r] ?? $r)
        ->pipe(fn ($c) => $c->count() > 1 ? $c->slice(0, -1)->join(', ').' dan '.$c->last() : $c->join(''));
@endphp
<div class="flex h-full" x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
    {{-- Latar drawer navigasi (layar kecil) --}}
    <div x-show="nav" x-cloak x-transition.opacity x-on:click="nav = false" class="fixed inset-0 z-40 bg-stone-950/50 lg:hidden"></div>

    <aside class="fixed inset-y-0 left-0 z-50 isolate flex w-64 shrink-0 flex-col overflow-hidden bg-emerald-950 text-emerald-50 transition-transform duration-200 max-lg:-translate-x-full lg:relative lg:w-60"
           x-bind:class="nav && 'max-lg:translate-x-0!'">
        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.07]" aria-hidden="true"></div>
        <div class="mx-3 mt-4 mb-2 flex items-center gap-1">
            <a href="{{ route('beranda') }}" class="min-w-0 flex-1 rounded-lg px-2 py-2 outline-none hover:bg-emerald-900/60 focus-visible:ring-2 focus-visible:ring-amber-300" title="Beranda koperasi">
                <x-brand.logo size="sm" />
            </a>
            @if ($user)
                <livewire:notifikasi.bell />
            @endif
        </div>
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
                                    @elseif ($multiRole && count($menuSources[$key] ?? []) < count($user['roles']))
                                        <span class="text-[10px] font-medium text-emerald-300/70" data-menu-source="{{ $key }}" title="Terbuka lewat peran {{ $roleList($menuSources[$key] ?? []) }}">{{ collect($menuSources[$key] ?? [])->map(fn ($r) => \App\Models\Role::LABELS[$r] ?? $r)->join('/') }}</span>
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
        <div class="relative m-3 rounded-xl border border-emerald-800/80 bg-emerald-900/60 p-3">
            @if ($user)
                <div class="flex items-center gap-1.5">
                    <span class="mr-1 grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 text-sm font-bold text-emerald-950">{{ mb_substr(preg_replace('/^(Pak|Bu)\s+/', '', $user['name']), 0, 1) }}</span>
                    {{-- Indikator peran aktif: klik untuk melihat semua peran, peran utama, dan menu dari tiap peran --}}
                    <div class="min-w-0 flex-1" x-data="{ roles: false }" x-on:click.outside="roles = false" x-on:keydown.escape="roles = false">
                        <button type="button" x-on:click="roles = !roles" x-bind:aria-expanded="roles" aria-controls="panel-peran"
                                class="block w-full min-w-0 rounded-lg px-1 py-0.5 text-left leading-tight outline-none hover:bg-emerald-800/60 focus-visible:ring-2 focus-visible:ring-amber-300"
                                title="Peran aktif Anda">
                            <span class="block truncate text-sm font-semibold text-white">{{ $user['name'] }}</span>
                            <span class="flex items-center gap-1 text-xs text-amber-300">
                                <span class="truncate" data-user-roles>{{ collect($heldRoles)->map(fn ($r) => \App\Models\Role::LABELS[$r] ?? $r)->join(' · ') }}</span>
                                @if (count($heldRoles) > 1)
                                    <span class="shrink-0 rounded-full bg-amber-400/20 px-1.5 text-[10px] font-semibold tabular-nums" data-active-role-count>{{ count($heldRoles) }} peran</span>
                                @endif
                                <svg class="size-3 shrink-0 transition" x-bind:class="roles && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
                            </span>
                        </button>
                        <div id="panel-peran" x-show="roles" x-cloak x-transition.origin.bottom
                             class="absolute inset-x-0 bottom-full z-10 mb-2 rounded-xl border border-emerald-700 bg-emerald-950 p-3 text-xs shadow-xl" data-role-panel>
                            <p class="truncate text-sm font-semibold text-white">{{ $user['name'] }}</p>
                            <p class="mt-2 font-semibold tracking-wider text-amber-300/90 uppercase">Peran aktif</p>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($heldRoles as $r)
                                    <li class="flex items-center gap-2" data-active-role="{{ $r }}">
                                        <span class="size-2 shrink-0 rounded-full bg-amber-400" aria-hidden="true"></span>
                                        <span class="flex-1 font-medium text-white">{{ \App\Models\Role::LABELS[$r] ?? $r }}
                                            @if ($r === $user['role'])<span class="ml-1 rounded bg-emerald-800 px-1 py-px text-[10px] font-normal text-emerald-200">utama</span>@endif
                                        </span>
                                        <span class="text-emerald-300 tabular-nums">{{ collect($menuSources)->filter(fn ($src) => in_array($r, $src, true))->count() }} menu</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-3 border-t border-emerald-800 pt-2 text-emerald-200">
                                Hak akses = gabungan <strong class="text-white tabular-nums">{{ count($user['permissions'] ?? []) }}</strong> menu dari {{ count($heldRoles) > 1 ? 'semua peran' : 'peran' }} Anda.
                            </p>
                            @if (\App\Support\Menu::canReach('pengguna.akses', $user))
                                <a href="{{ route('pengguna.akses', ['peran' => implode(',', $heldRoles)]) }}" wire:navigate class="mt-2 inline-block font-semibold text-amber-300 hover:underline">Lihat rincian hak akses →</a>
                            @endif
                        </div>
                    </div>
                    <a href="{{ route('akun.password') }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-emerald-200 hover:bg-emerald-800 hover:text-white" aria-label="Ganti kata sandi" title="Ganti kata sandi">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3m-3 3 2 2m-5 1 2 2"/></svg>
                    </a>
@if (\App\Support\ImpersonationSession::active())
                        <form method="POST" action="{{ route('impersonasi.selesai') }}">
                            @csrf
                            <button type="submit" class="grid size-9 place-items-center rounded-lg bg-red-700 text-white hover:bg-red-600" aria-label="Kembali ke akun saya" title="Kembali ke akun saya" data-impersonation-exit-sidebar>
                                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14 4 9l5-5M4 9h11a5 5 0 010 10h-3"/></svg>
                            </button>
                        </form>
                    @else
                                        <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="grid size-9 place-items-center rounded-lg text-emerald-200 hover:bg-emerald-800 hover:text-white" aria-label="Keluar" title="Keluar">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 17l5-5-5-5M15 12H3"/></svg>
                        </button>
                    </form>
                    @endif
                </div>
            @else
                <a href="{{ route('login') }}" class="flex items-center justify-center gap-2 rounded-lg bg-amber-400 px-3 py-2 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Masuk petugas</a>
            @endif
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <x-impersonation-banner />

        {{-- Bar atas (layar kecil) --}}
        <div class="flex items-center gap-3 bg-emerald-950 px-3 py-2.5 text-white lg:hidden">
            <button type="button" x-on:click="nav = true" aria-label="Buka menu"
                    class="grid size-10 place-items-center rounded-lg hover:bg-emerald-900">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            @if ($topLogo = \App\Support\MockData::profile()['logo'] ?? null)
                <img src="{{ $topLogo }}" alt="" class="size-7 shrink-0 rounded-md object-cover">
            @else
                <x-brand.mark class="size-7 shrink-0" />
            @endif
            <span class="flex-1 truncate text-sm font-semibold">{{ $title ?? \App\Support\MockData::profile()['app_name'] }}</span>
            @if ($user)
                <livewire:notifikasi.bell :compact="true" />
            @endif
        </div>

        @if ($user)
            {{-- Hak akses berubah selagi halaman terbuka (peran/hak menu diubah admin) → minta muat ulang --}}
            <div x-data="menuWatch(@js(\App\Support\Menu::versionFor($user)), @js(route('akun.menu', absolute: false)))" data-menu-version="{{ \App\Support\Menu::versionFor($user) }}">
                <div x-show="changed" x-cloak role="status" aria-live="polite"
                     class="flex flex-wrap items-center gap-2 border-b border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-900 sm:px-6" data-access-changed>
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                    <span class="flex-1">Hak akses Anda baru saja diubah admin. Muat ulang agar menu dan halaman mengikuti peran terbaru.</span>
                    <button type="button" x-on:click="reload()" class="rounded-lg bg-sky-700 px-3 py-1 text-xs font-semibold text-white hover:bg-sky-800">Muat ulang</button>
                </div>
            </div>
        @endif

        @if ($pageScope)
            <p class="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-xs text-amber-900 sm:px-6" data-page-scope>
                <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 4v5c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V7z"/></svg>
                <span>
                    @if ($pageScope['required'])Halaman khusus peran {{ $roleList($pageScope['required']) }} — @endif
                    terbuka lewat peran <strong>{{ $roleList($pageScope['via']) }}</strong> Anda; peran {{ $roleList(array_diff($user['roles'], $pageScope['via'])) }} saja tidak bisa membukanya.
                </span>
            </p>
        @endif

        {{ $slot }}
    </div>
</div>
<x-toasts />
@auth
    <x-idle-warning :minutes="\App\Support\SessionPolicy::idleMinutes()" />
@endauth
@livewireScripts
</body>
</html>
