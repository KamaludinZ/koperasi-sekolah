{{-- Cadangan untuk kode 5xx yang tidak punya halaman sendiri (501, 502, 504, …). --}}
@extends('errors.layout')
@section('code', $exception->getStatusCode())
@section('title', 'Terjadi kesalahan')
@section('message', 'Server sedang bermasalah. Tunggu sebentar lalu muat ulang halaman. Bila terus terjadi, hubungi admin koperasi.')
