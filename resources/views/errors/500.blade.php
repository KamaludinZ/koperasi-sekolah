@extends('errors.layout')
@section('code', '500')
@section('title', 'Terjadi kesalahan')
@section('message', 'Maaf, ada gangguan di sistem kami. Data transaksi yang sudah tersimpan tetap aman. Coba muat ulang; bila masih terjadi, laporkan ke admin koperasi.')
@section('actions')
    <button type="button" onclick="location.reload()" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Muat ulang</button>
    <a href="/" class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Ke beranda</a>
@endsection
