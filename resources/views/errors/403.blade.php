@extends('errors.layout')
@section('code', '403')
@section('title', 'Akses tidak diizinkan')
@section('message', 'Halaman ini hanya untuk petugas dengan peran tertentu. Jika Anda merasa seharusnya bisa membukanya, hubungi admin koperasi.')
@section('actions')
    <a href="/kasir" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Ke layar kasir</a>
    <a href="/masuk" class="rounded-xl border border-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Masuk dengan akun lain</a>
@endsection
