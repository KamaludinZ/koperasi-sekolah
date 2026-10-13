{{-- Navigasi bagian Audit & Riwayat. `active` = nama route halaman ini. --}}
@props(['active'])
@php
    $items = ['audit' => 'Semua aktivitas', 'audit.perubahan' => 'Perubahan data', 'audit.terhapus' => 'Data terhapus'];
@endphp
<nav class="mt-3 -mb-px flex gap-1 overflow-x-auto" aria-label="Bagian audit">
    @foreach ($items as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @if ($active === $route) aria-current="page" @endif
           @class(['shrink-0 border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => $active === $route, 'border-transparent text-stone-500 hover:text-stone-800' => $active !== $route])>{{ $label }}</a>
    @endforeach
</nav>
