{{-- Keadaan kosong portal Pinjaman Saya: anggota belum pernah punya pinjaman. Menjelaskan alur pengajuan lewat pengurus
     dan kontak koperasi (dari profil koperasi), tanpa formulir — pengajuan dicatat oleh pengurus. --}}
@php
    $profile = \App\Support\MockData::profile();
    $phone = trim((string) ($profile['phone'] ?? ''));
    $tel = preg_replace('/[^0-9+]/', '', $phone);
@endphp
<section class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center" data-empty="pinjaman-saya" aria-labelledby="loans-empty-title">
    <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-700" aria-hidden="true">
        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7h18v10H3zM7 12h.01M17 12h.01M12 9.5a2.5 2.5 0 110 5 2.5 2.5 0 010-5z"/></svg>
    </span>
    <h2 id="loans-empty-title" class="mt-3 font-semibold text-stone-800">Belum ada pinjaman</h2>
    <p class="mx-auto mt-1 max-w-md text-sm text-stone-500">Anda belum memiliki pinjaman di {{ $profile['short_name'] ?? 'koperasi' }}. Pengajuan pinjaman dicatat oleh pengurus koperasi — bila butuh dana, hubungi pengurus.</p>

    <ol class="mx-auto mt-6 grid max-w-xl gap-3 text-left text-sm sm:grid-cols-3" aria-label="Alur pengajuan pinjaman" data-loan-steps>
        @foreach ([
            ['Ajukan ke pengurus', 'Sampaikan nominal, tenor, dan cara bayar yang diinginkan.'],
            ['Ditinjau pengurus', 'Status pengajuan langsung tampil di halaman ini.'],
            ['Dana cair & angsuran', 'Jadwal angsuran dan sisa pinjaman bisa dipantau di sini.'],
        ] as $i => [$title, $desc])
            <li class="rounded-xl bg-stone-50 p-3">
                <span class="flex size-6 items-center justify-center rounded-full bg-emerald-700 text-xs font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                <p class="mt-2 font-medium text-stone-800">{{ $title }}</p>
                <p class="text-xs text-stone-500">{{ $desc }}</p>
            </li>
        @endforeach
    </ol>

    <div class="mt-6 flex flex-wrap justify-center gap-2">
        @if ($tel !== '')
            <a href="tel:{{ $tel }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800" data-contact-phone>Hubungi pengurus · {{ $phone }}</a>
        @endif
        <a href="{{ route('portal.simpanan') }}" wire:navigate class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Lihat simpanan saya</a>
    </div>
</section>
