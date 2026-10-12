<?php

use App\Http\Controllers\Kasir\ProductSearchController;
use App\Livewire\Barang;
use App\Livewire\Kasir;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/kasir');

Route::get('/kasir', Kasir\Index::class)->name('kasir');
Route::get('/kasir/barang/cari', ProductSearchController::class)
    ->middleware('throttle:120,1')
    ->name('kasir.products.search');

Route::get('/barang', Barang\Index::class)->name('barang');
