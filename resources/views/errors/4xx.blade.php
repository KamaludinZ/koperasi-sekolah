{{-- Cadangan untuk kode 4xx yang tidak punya halaman sendiri (405, 413, 400, …) agar tidak jatuh ke halaman bawaan Symfony. --}}
@php
    $code = $exception->getStatusCode();
    [$title, $message] = match ($code) {
        405 => ['Cara membuka halaman tidak sesuai', 'Alamat ini hanya dipakai saat menekan tombol di aplikasi. Kembali ke halaman sebelumnya lalu ulangi dari tombolnya.'],
        413 => ['Berkas terlalu besar', 'Data atau berkas yang dikirim melebihi batas unggahan. Perkecil ukuran berkas lalu coba lagi.'],
        default => ['Permintaan tidak dapat diproses', 'Ada yang tidak sesuai dengan permintaan ini. Kembali ke halaman sebelumnya lalu coba lagi.'],
    };
@endphp
@extends('errors.layout')
@section('code', $code)
@section('title', $title)
@section('message', $message)
