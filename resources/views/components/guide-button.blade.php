{{--
    Tombol Panduan di header petugas: membuka Pusat Panduan langsung pada panduan menu halaman yang sedang dibuka
    (mis. dari /pinjaman/… → panduan Pinjaman). `variant`: sidebar (gelap) | bar (bilah atas layar kecil).
--}}
@props(['variant' => 'sidebar'])
@php
    $segment = request()->segment(1);
    $guideKey = $segment && array_key_exists($segment, \App\Support\Menu::ITEMS) ? $segment : null;
    $label = $guideKey ? 'Panduan menu '.\App\Support\Menu::ITEMS[$guideKey]['label'] : 'Pusat Panduan';
@endphp
<a href="{{ route('panduan', $guideKey ? ['menu' => $guideKey] : []) }}" wire:navigate aria-label="{{ $label }}" title="{{ $label }}" data-guide-button="{{ $guideKey ?? 'umum' }}"
   @class(['shrink-0 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-300',
           'grid size-9 place-items-center text-emerald-200 hover:bg-emerald-900 hover:text-white' => $variant === 'sidebar',
           'grid size-10 place-items-center text-white hover:bg-emerald-900' => $variant === 'bar',
           'flex h-9 w-full items-center justify-center gap-2 px-2 text-sm font-medium text-emerald-200 hover:bg-emerald-800 hover:text-white' => $variant === 'row'])>
    <svg class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17h.01"/></svg>
    @if ($variant === 'row')<span aria-hidden="true">Panduan</span>@endif
</a>
