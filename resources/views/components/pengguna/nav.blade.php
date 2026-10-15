{{-- Navigasi bagian Kelola Pengguna. `active` = nama route halaman ini. --}}
@props(['active'])
@php
    $items = ['pengguna' => 'Akun petugas', 'pengguna.sesi' => 'Sesi aktif', 'pengguna.terkunci' => 'Akun terkunci', 'pengguna.akses' => 'Hak akses gabungan'];
@endphp
<nav class="mt-3 -mb-px flex gap-1 overflow-x-auto" aria-label="Bagian pengguna">
    @foreach ($items as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @if ($active === $route) aria-current="page" @endif
           @class(['shrink-0 border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => $active === $route, 'border-transparent text-stone-500 hover:text-stone-800' => $active !== $route])>{{ $label }}</a>
    @endforeach
</nav>
