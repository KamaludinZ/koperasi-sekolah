{{-- Tautan ke halaman lain yang dijaga menu/peran: hanya jadi tautan bila petugas boleh membukanya (gabungan
     semua perannya, lihat Menu::canReach); bila tidak, teksnya tampil tanpa tautan — tak ada jalan ke 403. --}}
@props(['route', 'params' => []])
@if (\App\Support\Menu::canReach($route))
    <a href="{{ route($route, $params) }}" wire:navigate {{ $attributes }}>{{ $slot }}</a>
@else
    <span data-menu-hidden="{{ $route }}">{{ $slot }}</span>
@endif
