@extends('errors.layout')
@php
    $user = session()->isStarted() ? \App\Support\Petugas::current() : null;
    $home = $user ? \App\Support\Menu::homeRoute($user['role'], $user['permissions'] ?? null) : 'beranda';
    $reason = isset($exception) && $exception->getMessage() !== '' && $exception->getMessage() !== 'Forbidden' ? $exception->getMessage() : null;
@endphp
@section('code', '403')
@section('title', 'Akses tidak diizinkan')
@section('message')
    {{ $reason ?? 'Halaman ini hanya untuk petugas dengan peran tertentu.' }}
    Jika Anda merasa seharusnya bisa membukanya, hubungi admin koperasi.
@endsection
@section('extra')
    @if ($user)
        <p class="mx-auto mt-5 w-fit rounded-full border border-emerald-700/70 bg-emerald-900/60 px-4 py-1.5 text-sm text-emerald-100">
            Anda masuk sebagai <strong class="text-white">{{ $user['name'] }}</strong> · <span class="text-amber-300">{{ \App\Models\Role::LABELS[$user['role']] ?? $user['role'] }}</span>
        </p>
    @endif
@endsection
@section('actions')
    <a href="{{ route($home) }}" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">{{ $user ? 'Ke halaman saya' : 'Ke beranda' }}</a>
    <button type="button" onclick="history.length > 1 ? history.back() : location.assign('/')"
            class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Kembali</button>
@endsection
