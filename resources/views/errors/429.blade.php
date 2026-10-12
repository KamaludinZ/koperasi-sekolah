@extends('errors.layout')
@section('code', '429')
@section('title', 'Terlalu banyak permintaan')
@section('message', 'Tunggu sebentar, lalu coba lagi. Batas ini menjaga aplikasi tetap lancar untuk semua kasir.')
@section('actions')
    <button type="button" onclick="location.reload()" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Coba lagi</button>
@endsection
