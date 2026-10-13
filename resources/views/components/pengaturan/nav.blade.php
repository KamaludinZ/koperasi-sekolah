{{-- Navigasi bagian Pengaturan Koperasi. Halaman yang belum dibuat tampil "segera". --}}
@php
    $items = [
        'pengaturan' => 'Ringkasan',
        'pengaturan.profil' => 'Profil koperasi',
        'pengaturan.struk' => 'Struk',
        'pengaturan.pajak' => 'Pajak & pembulatan',
        'pengaturan.keamanan' => 'Keamanan & retensi',
    ];
@endphp
<nav class="mt-3 -mb-4 flex gap-1 overflow-x-auto" aria-label="Bagian pengaturan">
    @foreach ($items as $route => $label)
        @if (\Illuminate\Support\Facades\Route::has($route))
            <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
               @class(['shrink-0 border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => request()->routeIs($route), 'border-transparent text-stone-500 hover:text-stone-800' => ! request()->routeIs($route)])>{{ $label }}</a>
        @else
            <span class="shrink-0 cursor-not-allowed border-b-2 border-transparent px-3 pb-2.5 text-sm font-medium text-stone-300" title="Segera hadir">{{ $label }}</span>
        @endif
    @endforeach
</nav>
