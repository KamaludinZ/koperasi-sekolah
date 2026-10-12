{{-- Mode perawatan (php artisan down). Akses petugas selama perawatan: php artisan down --secret="kode" lalu buka /kode. --}}
@extends('errors.layout')
@php
    $note = isset($exception) && $exception->getMessage() !== 'Service Unavailable' ? $exception->getMessage() : null;
@endphp
@section('head')
    <meta http-equiv="refresh" content="60">
@endsection
@section('code', '503')
@section('title', 'Sedang dalam perawatan')
@section('message', 'Aplikasi koperasi sedang diperbarui agar lebih baik. Insyaallah tidak lama — halaman ini akan memuat ulang sendiri setiap menit.')
@section('extra')
    @if ($note)
        <p class="mx-auto mt-5 max-w-md rounded-xl border border-amber-400/40 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">{{ $note }}</p>
    @endif
    <div class="mx-auto mt-8 max-w-sm rounded-2xl border border-emerald-700/60 bg-emerald-900/60 p-4 text-left text-sm">
        <p class="font-semibold text-white">Selama perawatan</p>
        <ul class="mt-2 space-y-1.5 text-emerald-200">
            <li class="flex gap-2"><span class="text-amber-300">✦</span> Catat penjualan sementara secara manual.</li>
            <li class="flex gap-2"><span class="text-amber-300">✦</span> Data yang sudah tersimpan tetap aman.</li>
            <li class="flex gap-2"><span class="text-amber-300">✦</span> Kasir dapat dipakai lagi setelah halaman ini hilang.</li>
        </ul>
    </div>
@endsection
@section('actions')
    <button type="button" onclick="location.reload()" class="rounded-xl bg-amber-400 px-5 py-2.5 text-sm font-semibold text-emerald-950 hover:bg-amber-300">Periksa lagi</button>
@endsection
