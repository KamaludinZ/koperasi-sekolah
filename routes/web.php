<?php

use App\Http\Controllers\Kasir\CheckoutController;
use App\Http\Controllers\Kasir\ProductSearchController;
use App\Livewire\Barang;
use App\Livewire\Kasir;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/kasir');

Route::get('/kasir', Kasir\Index::class)->name('kasir');
Route::get('/kasir/barang/cari', ProductSearchController::class)
    ->middleware('throttle:120,1')
    ->name('kasir.products.search');
Route::post('/kasir/transaksi', CheckoutController::class)
    ->middleware('throttle:60,1')
    ->name('kasir.checkout');

Route::get('/barang', Barang\Index::class)->name('barang');
