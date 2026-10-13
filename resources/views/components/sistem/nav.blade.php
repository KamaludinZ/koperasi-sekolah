{{-- Navigasi bagian Sistem. `active` = nama route halaman ini. Halaman yang belum dibuat tampil "segera". --}}
@props(['active'])
@php
    $items = ['sistem' => 'Kesehatan', 'sistem.cadangan' => 'Cadangan data', 'sistem.antrean' => 'Antrean', 'sistem.performa' => 'Performa & cache'];
@endphp
<nav class="mt-3 -mb-px flex gap-1 overflow-x-auto" aria-label="Bagian sistem">
    @foreach ($items as $route => $label)
        @if (\Illuminate\Support\Facades\Route::has($route))
            <a href="{{ route($route) }}" wire:navigate @if ($active === $route) aria-current="page" @endif
               @class(['shrink-0 border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => $active === $route, 'border-transparent text-stone-500 hover:text-stone-800' => $active !== $route])>{{ $label }}</a>
        @else
            <span class="shrink-0 cursor-not-allowed border-b-2 border-transparent px-3 pb-2.5 text-sm font-medium text-stone-300" title="Segera hadir">{{ $label }}</span>
        @endif
    @endforeach
</nav>
