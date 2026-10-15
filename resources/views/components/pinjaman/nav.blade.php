{{-- Navigasi bagian Pinjaman. `active` = nama route halaman ini; route yang belum ada tidak ditampilkan. --}}
@props(['active'])
@php
    $items = collect(['pinjaman' => 'Konsol', 'pinjaman.pengajuan' => 'Pengajuan', 'pinjaman.pencairan' => 'Pencairan', 'pinjaman.angsuran' => 'Angsuran', 'pinjaman.tunggakan' => 'Tunggakan', 'pinjaman.rekap' => 'Rekap', 'pinjaman.audit' => 'Jejak audit'])
        ->filter(fn ($label, $route) => \Illuminate\Support\Facades\Route::has($route));
    // lencana jumlah pinjaman yang menunggak saat ini di tab Tunggakan
    $overdueCount = $items->has('pinjaman.tunggakan') ? \App\Models\Loan::query()->effectivelyOverdue()->count() : 0;
@endphp
<nav class="mt-3 -mb-px flex gap-1 overflow-x-auto" aria-label="Bagian pinjaman">
    @foreach ($items as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @if ($active === $route) aria-current="page" @endif
           @class(['shrink-0 border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => $active === $route, 'border-transparent text-stone-500 hover:text-stone-800' => $active !== $route])>{{ $label }}@if ($route === 'pinjaman.tunggakan' && $overdueCount > 0)<span class="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 text-[11px] font-semibold text-white tabular-nums" data-nav-overdue>{{ $overdueCount }}</span><span class="sr-only"> pinjaman menunggak</span>@endif</a>
    @endforeach
</nav>
