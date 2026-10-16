{{-- Kerangka portal anggota: ramah ponsel, navigasi bawah di layar kecil. Menu yang belum ada rutenya disembunyikan. --}}
@props(['title' => null])
@php
    $profile = \App\Support\MockData::profile();
    $member = auth('member')->user();
    $nav = collect([
        'portal' => ['Beranda', 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
        'portal.simpanan' => ['Simpanan', 'M12 3v18M17 7H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6'],
        'portal.pinjaman' => ['Pinjaman', 'M3 7h18v10H3zM7 12h.01M12 9.5a2.5 2.5 0 110 5 2.5 2.5 0 010-5z'],
        'portal.riwayat' => ['Riwayat', 'M12 8v4l3 2M3.05 11a9 9 0 11.5 4M3 4v5h5'],
        'portal.belanja' => ['Belanja', 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13 5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17'],
        'portal.pesanan' => ['Pesanan', 'M9 5h10M9 12h10M9 19h10M5 5h.01M5 12h.01M5 19h.01'],
    ])->filter(fn ($item, $route) => \Illuminate\Support\Facades\Route::has($route));
    // titik merah di menu Pinjaman bila anggota punya pinjaman menunggak
    $alerts = ['portal.pinjaman' => $member && $nav->has('portal.pinjaman') && \App\Livewire\Portal\Loans::overdueCount($member->id) > 0];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#022c22">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}Portal Anggota · {{ $profile['app_name'] }}</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|amiri:400,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-stone-50 text-stone-900 antialiased" data-portal>
    {{-- spanduk impersonasi & header portal menempel bersama di atas (tidak saling menutupi) --}}
    <div class="sticky top-0 z-30">
    <x-impersonation-banner />
    @if ($member)
        <header class="border-b border-emerald-900/10 bg-emerald-950 text-emerald-50">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
                <a href="{{ route('portal') }}" wire:navigate class="flex min-w-0 items-center gap-2"><x-brand.logo /></a>
                <div class="flex items-center gap-3">
                    <nav class="hidden items-center gap-1 sm:flex" aria-label="Menu portal">
                        @foreach ($nav as $route => [$label])
                            <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
                               @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white/10 text-white' => request()->routeIs($route), 'text-emerald-200 hover:text-white' => ! request()->routeIs($route)])>{{ $label }}@if ($alerts[$route] ?? false)<span class="ml-1 inline-block size-2 rounded-full bg-red-500 align-middle" data-nav-alert="{{ $route }}"></span><span class="sr-only"> (ada tunggakan)</span>@endif</a>
                        @endforeach
                    </nav>
                    @php
                        $here = request()->route()?->getName();
                        $topic = \App\Support\GuideCatalog::portalTopicFor($here);
                        $guideUrl = $topic ? route('portal.panduan', ['topik' => $topic, 'dari' => $here]) : route('portal.panduan');
                    @endphp
                    <a href="{{ $guideUrl }}" wire:navigate data-portal-guide-button @if ($topic) data-portal-guide-topic="{{ $topic }}" @endif @if (request()->routeIs('portal.panduan')) aria-current="page" @endif
                       class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm font-medium text-emerald-200 hover:bg-white/10 hover:text-white" title="Panduan portal">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17h.01"/></svg>
                        <span>Panduan</span>
                    </a>
                    @unless (\App\Support\ImpersonationSession::impersonatingMember()) {{-- saat impersonasi: kembali lewat spanduk --}}
                                        <form method="POST" action="{{ route('portal.logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-emerald-200 hover:bg-white/10 hover:text-white">Keluar</button>
                    </form>
                    @endunless
                </div>
            </div>
        </header>
    @endif
    </div>

    <main @class(['mx-auto max-w-5xl', 'px-4 pt-5 pb-28 sm:pb-10' => $member])>
        {{ $slot }}
    </main>

    @if ($member && $nav->count() > 1)
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden" aria-label="Menu portal">
            <div class="mx-auto grid max-w-md" style="grid-template-columns: repeat({{ $nav->count() }}, minmax(0, 1fr))">
                @foreach ($nav as $route => [$label, $icon])
                    <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
                       @class(['relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium', 'text-emerald-800' => request()->routeIs($route), 'text-stone-500' => ! request()->routeIs($route)])>
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                        {{ $label }}
                        @if ($alerts[$route] ?? false)<span class="absolute top-1.5 left-1/2 ml-2 size-2 rounded-full bg-red-500 ring-2 ring-white" data-nav-alert="{{ $route }}"></span><span class="sr-only">(ada tunggakan)</span>@endif
                    </a>
                @endforeach
            </div>
        </nav>
    @endif
    <x-toasts />
    @livewireScripts
</body>
</html>
