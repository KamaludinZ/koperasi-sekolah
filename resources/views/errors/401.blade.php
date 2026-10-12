@extends('errors.layout')
@section('code', '401')
@section('title', 'Silakan masuk dulu')
@section('message', 'Halaman ini khusus petugas koperasi. Masuk memakai akun Anda untuk melanjutkan.')
@section('actions')
    <a href="/masuk" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Masuk petugas</a>
    <a href="/" class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Ke beranda</a>
@endsection
