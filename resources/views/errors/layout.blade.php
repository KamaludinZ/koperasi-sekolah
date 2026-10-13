{{--
    Kerangka halaman error & perawatan bertema madrasah. Tidak memakai session/DB
    supaya tetap tampil saat aplikasi bermasalah. Section: code, title, message, actions, extra.
--}}
@php $profile = \App\Support\MockData::profile(); @endphp
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#022c22">
    <meta name="robots" content="noindex">
    @yield('head')
    <title>@yield('title') · {{ $profile['app_name'] }}</title>
    <x-brand.favicon />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|amiri:400,700" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="relative isolate flex min-h-full flex-col overflow-x-hidden bg-emerald-950 text-emerald-50 antialiased">
    <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.09]" aria-hidden="true"></div>
    <div class="absolute top-1/3 left-1/2 -z-10 size-[36rem] -translate-x-1/2 rounded-full bg-emerald-700/25 blur-3xl" aria-hidden="true"></div>

    <header class="px-5 py-5 sm:px-8">
        <a href="/" class="inline-block rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-300"><x-brand.logo /></a>
    </header>

    <main class="flex flex-1 items-center justify-center px-5 pb-16">
        <div class="w-full max-w-lg text-center">
            {{-- Ornamen bintang dengan kode --}}
            <div class="relative mx-auto grid size-40 place-items-center">
                <svg class="absolute inset-0 size-full animate-[spin_60s_linear_infinite] text-amber-400/80" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                    <rect x="20" y="20" width="60" height="60" rx="3"/>
                    <rect x="20" y="20" width="60" height="60" rx="3" transform="rotate(45 50 50)"/>
                </svg>
                <span class="relative font-display text-5xl font-bold text-white">@yield('code')</span>
            </div>

            <h1 class="mt-8 font-display text-3xl font-bold text-white sm:text-4xl">@yield('title')</h1>
            <p class="mx-auto mt-3 max-w-md leading-relaxed text-emerald-200">@yield('message')</p>

            @yield('extra')

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                @hasSection('actions')
                    @yield('actions')
                @else
                    <a href="/" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Ke beranda</a>
                    <button type="button" onclick="history.length > 1 ? history.back() : location.assign('/')"
                            class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Kembali</button>
                @endif
            </div>
        </div>
    </main>

    <footer class="px-5 py-5 text-center text-xs text-emerald-400/80">{{ $profile['name'] }} · {{ $profile['school'] }}</footer>
</body>
</html>
