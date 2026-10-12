{{-- Tab halaman akun: kata sandi & sesi aktif --}}
<nav class="mt-3 -mb-4 flex gap-1" aria-label="Pengaturan akun">
    @foreach (['akun.password' => 'Kata sandi', 'akun.sessions' => 'Sesi aktif'] as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
           @class(['border-b-2 px-3 pb-2.5 text-sm font-medium', 'border-emerald-700 text-emerald-900' => request()->routeIs($route), 'border-transparent text-stone-500 hover:text-stone-800' => ! request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
</nav>
