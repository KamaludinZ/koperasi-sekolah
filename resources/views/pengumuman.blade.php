{{-- Halaman publik semua pengumuman koperasi yang masih berlaku (tanpa login). --}}
<x-layouts.guest title="Pengumuman">
    <header class="relative isolate overflow-hidden bg-emerald-950 text-emerald-50">
        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.1]" aria-hidden="true"></div>
        <nav class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-5 sm:px-6" aria-label="Utama">
            <a href="{{ route('beranda') }}" class="min-w-0 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-300"><x-brand.logo /></a>
            <a href="{{ route('beranda') }}" class="shrink-0 rounded-xl border border-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900">Beranda</a>
        </nav>
        <div class="mx-auto max-w-4xl px-4 pt-6 pb-12 sm:px-6">
            <h1 class="font-display text-4xl font-bold text-white">Pengumuman</h1>
            <p class="mt-2 text-emerald-200">Kabar terbaru dari {{ $profile['name'] }} untuk siswa, guru, dan wali.</p>
        </div>
    </header>

    <main class="mx-auto max-w-4xl space-y-4 px-4 py-10 sm:px-6">
        @forelse ($announcements as $a)
            <article id="pengumuman-{{ $a['id'] }}" class="rounded-2xl border-l-4 border-amber-400 bg-white p-6 shadow-sm ring-1 ring-stone-200">
                <p class="text-xs text-stone-500">{{ \Illuminate\Support\Carbon::parse($a['created_at'])->translatedFormat('l, d F Y') }}@if ($a['expires_at']) · berlaku sampai {{ \Illuminate\Support\Carbon::parse($a['expires_at'])->translatedFormat('d F Y') }}@endif</p>
                <h2 class="mt-1 font-display text-2xl font-bold text-emerald-950">{{ $a['title'] }}</h2>
                <p class="mt-2 leading-relaxed whitespace-pre-line text-stone-700">{{ $a['message'] }}</p>
            </article>
        @empty
            <p class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center text-stone-500">Belum ada pengumuman saat ini.</p>
        @endforelse
    </main>
</x-layouts.guest>
