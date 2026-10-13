{{-- Beranda publik koperasi madrasah. Data dari BerandaController. --}}
@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $badge = [
        'tersedia' => ['Tersedia', 'bg-emerald-100 text-emerald-800'],
        'terbatas' => ['Stok terbatas', 'bg-amber-100 text-amber-900'],
        'habis' => ['Habis', 'bg-stone-200 text-stone-600'],
    ];
    $loggedIn = \App\Support\Petugas::current();
@endphp
<x-layouts.guest title="Beranda">
    {{-- Hero --}}
    <header class="relative isolate overflow-hidden bg-emerald-950 text-emerald-50">
        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.1]" aria-hidden="true"></div>
        <div class="absolute top-1/2 -right-40 -z-10 size-[34rem] -translate-y-1/2 rounded-full bg-emerald-700/30 blur-3xl" aria-hidden="true"></div>

        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-5 sm:px-6" aria-label="Utama">
            <a href="{{ route('beranda') }}" class="min-w-0 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-300"><x-brand.logo /></a>
            <div class="flex items-center gap-1 sm:gap-2">
                <a href="#barang" class="hidden rounded-lg px-3 py-2 text-sm text-emerald-100 hover:text-white md:block">Barang</a>
                <a href="#layanan" class="hidden rounded-lg px-3 py-2 text-sm text-emerald-100 hover:text-white md:block">Layanan</a>
                <a href="#kunjungi" class="hidden rounded-lg px-3 py-2 text-sm text-emerald-100 hover:text-white md:block">Jam buka</a>
                <a href="{{ $loggedIn ? route('kasir') : route('login') }}"
                   class="shrink-0 rounded-xl bg-amber-400 px-4 py-2 text-sm font-semibold text-emerald-950 shadow-sm hover:bg-amber-300">{{ $loggedIn ? 'Buka aplikasi' : 'Masuk petugas' }}</a>
            </div>
        </nav>

        <div class="mx-auto grid max-w-6xl gap-10 px-4 pt-10 pb-20 sm:px-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-center lg:pt-16 lg:pb-28">
            <div>
                <h1 class="font-display text-4xl leading-[1.15] font-bold text-balance text-white sm:text-5xl lg:text-[3.4rem]">
                    Kebutuhan sekolah lengkap, menabung jadi kebiasaan.
                </h1>
                <p class="mt-5 max-w-xl text-base leading-relaxed text-emerald-100">
                    {{ $profile['name'] }} melayani siswa, guru, dan tenaga kependidikan {{ $profile['school'] }} —
                    alat tulis, seragam, makanan ringan, hingga tabungan anggota.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="#barang" class="rounded-xl bg-white px-5 py-3 text-sm font-semibold text-emerald-900 hover:bg-emerald-50">Lihat barang populer</a>
                    <a href="#kunjungi" class="rounded-xl border border-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Jam buka &amp; lokasi</a>
                </div>
            </div>

            {{-- Status hari ini --}}
            <aside class="rounded-2xl border border-emerald-700/60 bg-emerald-900/70 p-5 shadow-xl shadow-emerald-950/40 backdrop-blur" aria-label="Status koperasi hari ini">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold tracking-[0.15em] text-emerald-300 uppercase">Hari ini</p>
                    <span @class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                                  'bg-emerald-400/15 text-emerald-200' => $status['open'],
                                  'bg-stone-500/20 text-stone-200' => ! $status['open']])>
                        <span @class(['size-2 rounded-full', 'animate-pulse bg-emerald-400' => $status['open'], 'bg-stone-400' => ! $status['open']])></span>
                        {{ $status['label'] }}
                    </span>
                </div>
                <p class="mt-3 font-display text-2xl font-bold text-white">{{ now()->translatedFormat('l, d F Y') }}</p>
                <p class="text-sm text-emerald-200">{{ $status['detail'] }}</p>
                <dl class="mt-5 grid grid-cols-3 gap-2 border-t border-emerald-700/60 pt-4 text-center">
                    <div><dt class="text-[11px] text-emerald-300">Barang siap</dt><dd class="text-xl font-bold text-white tabular-nums">{{ $stats['products'] }}</dd></div>
                    <div><dt class="text-[11px] text-emerald-300">Kategori</dt><dd class="text-xl font-bold text-white tabular-nums">{{ $stats['categories'] }}</dd></div>
                    <div><dt class="text-[11px] text-emerald-300">Anggota</dt><dd class="text-xl font-bold text-white tabular-nums">{{ $stats['members'] }}</dd></div>
                </dl>
            </aside>
        </div>

        {{-- Lengkung bawah --}}
        <svg class="absolute inset-x-0 bottom-0 h-8 w-full text-stone-50 sm:h-12" viewBox="0 0 1440 48" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 48h1440V0C1200 32 960 48 720 48S240 32 0 0z"/></svg>
    </header>

    <main>
        @if (count($announcements))
            <section class="mx-auto max-w-6xl px-4 pt-12 sm:px-6" aria-labelledby="pengumuman">
                <h2 id="pengumuman" class="ornament-divider text-xs font-semibold tracking-[0.2em] text-amber-600 uppercase">Pengumuman</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach (array_slice($announcements, 0, 4) as $a)
                        <article class="rounded-2xl border-l-4 border-amber-400 bg-white p-5 shadow-sm ring-1 ring-stone-200">
                            <h3 class="font-display text-xl font-bold text-emerald-950">{{ $a['title'] }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed whitespace-pre-line text-stone-700">{{ $a['message'] }}</p>
                            <p class="mt-2 text-xs text-stone-500">{{ \Illuminate\Support\Carbon::parse($a['created_at'])->translatedFormat('d F Y') }}@if ($a['expires_at']) · berlaku sampai {{ \Illuminate\Support\Carbon::parse($a['expires_at'])->translatedFormat('d F Y') }}@endif</p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Layanan --}}
        <section id="layanan" class="mx-auto max-w-6xl scroll-mt-6 px-4 py-16 sm:px-6">
            <p class="ornament-divider mx-auto max-w-xs text-xs font-semibold tracking-[0.2em] text-amber-600 uppercase">Layanan kami</p>
            <h2 class="mt-3 text-center font-display text-3xl font-bold text-emerald-950 sm:text-4xl">Dari warga madrasah, untuk warga madrasah</h2>
            <div class="mt-10 grid gap-4 md:grid-cols-3">
                @foreach ([
                    ['Belanja kebutuhan sekolah', 'Alat tulis, seragam, atribut, makanan & minuman dengan harga bersahabat untuk siswa.', 'M3 3h2l2.4 12.2a2 2 0 002 1.8h8.2a2 2 0 002-1.6L21 8H6M9 21a1 1 0 100-2 1 1 0 000 2zm9 0a1 1 0 100-2 1 1 0 000 2z'],
                    ['Tabungan anggota', 'Siswa dan guru bisa menabung di koperasi. Setiap setoran & penarikan tercatat dengan rapi.', 'M19 7h-1V5a2 2 0 00-2-2H5a2 2 0 00-2 2v12a3 3 0 003 3h13a2 2 0 002-2V9a2 2 0 00-2-2zM3 7h15M16 14h.01'],
                    ['Amanah & transparan', 'Setiap transaksi mendapat struk. Pengurus melaporkan rekap penjualan secara berkala.', 'M9 12l2 2 4-4M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6z'],
                ] as [$title, $text, $icon])
                    <article class="group relative overflow-hidden rounded-2xl border border-stone-200 bg-white p-6 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-emerald-900/5">
                        <span class="grid size-12 place-items-center rounded-xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100 transition group-hover:bg-emerald-800 group-hover:text-amber-300">
                            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-semibold text-emerald-950">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">{{ $text }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Barang populer --}}
        <section id="barang" class="scroll-mt-6 border-y border-stone-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.2em] text-amber-600 uppercase">Paling dicari</p>
                        <h2 class="mt-2 font-display text-3xl font-bold text-emerald-950">Barang populer bulan ini</h2>
                    </div>
                    <p class="max-w-xs text-sm text-stone-500">Harga dapat berubah. Ketersediaan diperbarui otomatis dari kasir.</p>
                </div>

                @if (count($popular))
                    <ul class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($popular as $p)
                            <li class="flex items-center gap-4 rounded-2xl border border-stone-200 p-4">
                                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-amber-50 font-display text-xl font-bold text-amber-700 ring-1 ring-amber-100">{{ mb_substr($p['name'], 0, 1) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-stone-900">{{ $p['name'] }}</p>
                                    <p class="text-xs text-stone-500">{{ $p['category'] }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="font-bold text-emerald-800 tabular-nums">{{ $rp($p['price']) }}</p>
                                    <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge[$p['availability']][1] }}">{{ $badge[$p['availability']][0] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-8 rounded-2xl border border-dashed border-stone-300 p-8 text-center text-sm text-stone-500">Daftar barang akan segera tampil.</p>
                @endif

                @if ($categories->isNotEmpty())
                    <div class="mt-8 flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-sm text-stone-500">Kategori:</span>
                        @foreach ($categories as $c)
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm text-emerald-900">{{ $c->name }} <span class="text-emerald-600 tabular-nums">· {{ $c->products_count }}</span></span>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- Jam buka & lokasi --}}
        <section id="kunjungi" class="mx-auto grid max-w-6xl scroll-mt-6 gap-6 px-4 py-16 sm:px-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-stone-200 bg-white p-6">
                <h2 class="font-display text-2xl font-bold text-emerald-950">Jam buka</h2>
                <dl class="mt-4 divide-y divide-stone-100">
                    @foreach ($profile['hours'] as $h)
                        <div class="flex justify-between py-2.5 text-sm"><dt class="text-stone-600">{{ $h['days'] }}</dt><dd class="font-semibold text-stone-900 tabular-nums">{{ $h['time'] }}</dd></div>
                    @endforeach
                    @php $closed = \App\Support\OpeningHours::closedDays($profile['schedule']); @endphp
                    <div class="flex justify-between py-2.5 text-sm"><dt class="text-stone-600">{{ $closed ? implode(', ', $closed).' & ' : '' }}hari libur</dt><dd class="font-semibold text-stone-400">Tutup</dd></div>
                </dl>
                <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">Saat jam istirahat dan shalat berjamaah, layanan kasir dijeda sejenak.</p>
            </div>
            <div class="relative isolate overflow-hidden rounded-2xl bg-emerald-900 p-6 text-emerald-50">
                <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.1]" aria-hidden="true"></div>
                <h2 class="font-display text-2xl font-bold text-white">Kunjungi kami</h2>
                <ul class="mt-4 space-y-4 text-sm">
                    <li class="flex gap-3">
                        <svg class="mt-0.5 size-5 shrink-0 text-amber-300" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0114 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        <span>{{ $profile['school'] }}<br><span class="text-emerald-200">{{ $profile['address'] }}</span></span>
                    </li>
                    <li class="flex gap-3">
                        <svg class="mt-0.5 size-5 shrink-0 text-amber-300" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/></svg>
                        <a href="tel:{{ preg_replace('/\D/', '', $profile['phone']) }}" class="hover:underline">{{ $profile['phone'] }}</a>
                    </li>
                </ul>
                <blockquote class="mt-8 border-l-2 border-amber-400 pl-4">
                    <p class="font-display text-xl leading-relaxed text-amber-200" lang="ar" dir="rtl">وَتَعَاوَنُوا عَلَى الْبِرِّ وَالتَّقْوَىٰ</p>
                    <p class="mt-1 text-sm text-emerald-200">"Tolong-menolonglah kamu dalam kebaikan dan takwa." — QS. Al-Ma'idah: 2</p>
                </blockquote>
            </div>
        </section>
    </main>

    <footer class="border-t border-stone-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-6 text-sm text-stone-500 sm:flex-row sm:px-6">
            <x-brand.logo variant="dark" size="sm" />
            <p>© {{ now()->year }} {{ $profile['name'] }} · {{ $profile['motto'] }}</p>
        </div>
    </footer>
</x-layouts.guest>
