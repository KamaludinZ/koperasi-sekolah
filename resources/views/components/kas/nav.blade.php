{{-- Navigasi bagian Kas Harian. `active` = nama route halaman ini (tetap benar saat Livewire memperbarui halaman). --}}
@props(['active'])
@php
    $items = ['kas' => 'Harian & shift', 'kas.rekap' => 'Rekap periode', 'kas.shift' => 'Riwayat shift'];
@endphp
<nav class="mt-3 -mb-px flex gap-1 overflow-x-auto" aria-label="Bagian kas harian">
    @foreach ($items as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @if ($active === $route) aria-current="page" @endif
           @class(['shrink-0 border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => $active === $route, 'border-transparent text-stone-500 hover:text-stone-800' => $active !== $route])>{{ $label }}</a>
    @endforeach
</nav>
