@extends('errors.layout')
@section('code', '419')
@section('title', 'Sesi sudah berakhir')
@section('message', 'Halaman terlalu lama dibiarkan terbuka sehingga sesi keamanannya habis. Muat ulang halaman lalu ulangi langkah terakhir.')
@section('actions')
    <button type="button" onclick="location.reload()" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Muat ulang</button>
    <a href="/masuk" class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Masuk lagi</a>
@endsection
