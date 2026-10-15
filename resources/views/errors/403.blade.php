@extends('errors.layout')
@php
    $user = session()->isStarted() ? \App\Support\Petugas::current() : null;
    $home = $user ? \App\Support\Menu::homeRoute($user['role'], $user['permissions'] ?? null) : 'beranda';
    $reason = isset($exception) && $exception->getMessage() !== '' && $exception->getMessage() !== 'Forbidden' ? $exception->getMessage() : null;
    // rincian: menu/peran yang disyaratkan halaman ini vs peran yang dipegang (gabungan semua peran)
    $denial = $user ? \App\Support\Menu::denial(request()->route()?->getName(), $user) : null;
    if ($denial && in_array(true, $denial['held'], true)) {
        $denial = null; // penjaga menu/peran lolos → 403 dari aturan lain (kebijakan data, hak aksi); cukup pesannya
    }
    $label = fn ($r) => \App\Models\Role::LABELS[$r] ?? ucfirst($r);
    $list = fn (array $names) => collect($names)->map($label)->pipe(fn ($c) => $c->count() > 1 ? $c->slice(0, -1)->join(', ').' atau '.$c->last() : $c->join(''));
    $shortcuts = $user ? collect(\App\Support\Menu::forUser($user))->flatMap(fn ($g) => $g['items'])->filter(fn ($i) => $i['route'])->take(6) : collect();
@endphp
@section('code', '403')
@section('title', 'Akses ditolak')
@section('message')
    {{ $reason ?? 'Halaman ini hanya untuk petugas dengan peran tertentu.' }}
    @unless ($denial && $denial['suggest'])
        Jika Anda merasa seharusnya bisa membukanya, hubungi admin koperasi.
    @endunless
@endsection
@section('extra')
    @if ($user)
        <p class="mx-auto mt-5 w-fit rounded-full border border-emerald-700/70 bg-emerald-900/60 px-4 py-1.5 text-sm text-emerald-100">
            Anda masuk sebagai <strong class="text-white">{{ $user['name'] }}</strong> · <span class="text-amber-300">{{ collect($user['roles'] ?? [$user['role']])->map($label)->join(' · ') }}</span>
        </p>
    @endif

    @if ($denial)
        <div class="mx-auto mt-6 max-w-md rounded-2xl border border-emerald-800 bg-emerald-900/50 p-4 text-left text-sm" data-denial>
            <p class="text-xs font-semibold tracking-wider text-amber-300/90 uppercase">Syarat halaman ini</p>
            <ul class="mt-2 space-y-1 text-emerald-100">
                @if ($denial['menu_label'])
                    <li>Menu <strong class="text-white">{{ $denial['menu_label'] }}</strong></li>
                @endif
                @if ($denial['required'])
                    <li>Peran {{ $list($denial['required']) }}</li>
                @endif
            </ul>

            @if ($denial['held'])
                <p class="mt-4 text-xs font-semibold tracking-wider text-amber-300/90 uppercase">Peran Anda</p>
                <ul class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($denial['held'] as $role => $ok)
                        <li data-held-role="{{ $role }}" data-ok="{{ $ok ? '1' : '0' }}"
                            @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-emerald-700 text-white' => $ok, 'bg-emerald-950/70 text-emerald-300 ring-1 ring-emerald-800' => ! $ok])>
                            <span aria-hidden="true">{{ $ok ? '✓' : '✕' }}</span> {{ $label($role) }}
                            <span class="sr-only">{{ $ok ? 'memenuhi' : 'tidak memenuhi' }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-emerald-300/90">Tak satu pun peran Anda memenuhi syarat. Hak akses Anda adalah gabungan semua peran yang Anda pegang.</p>
            @endif

            @if ($denial['suggest'])
                <p class="mt-4 rounded-lg bg-amber-400/10 px-3 py-2 text-xs text-amber-100" data-suggest>
                    Butuh akses? Minta admin koperasi menambahkan peran <strong class="text-amber-300">{{ $list($denial['suggest']) }}</strong> ke akun Anda.
                </p>
            @endif
        </div>
    @endif

    @if ($shortcuts->isNotEmpty())
        <nav class="mx-auto mt-6 max-w-md" aria-label="Halaman yang bisa Anda buka">
            <p class="text-xs text-emerald-300/80">Halaman yang bisa Anda buka:</p>
            <ul class="mt-2 flex flex-wrap justify-center gap-1.5">
                @foreach ($shortcuts as $item)
                    <li><a href="{{ route($item['route']) }}" class="inline-block rounded-lg border border-emerald-700/80 px-3 py-1.5 text-xs font-medium text-emerald-100 hover:bg-emerald-900 hover:text-white">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>
    @endif
@endsection
@section('actions')
    <a href="{{ route($home) }}" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">{{ $user ? 'Ke halaman saya' : 'Ke beranda' }}</a>
    <button type="button" onclick="history.length > 1 ? history.back() : location.assign('/')"
            class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Kembali</button>
@endsection
