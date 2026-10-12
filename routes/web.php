<?php

use App\Http\Controllers\Kasir\CheckoutController;
use App\Http\Controllers\Kasir\ProductSearchController;
use App\Http\Controllers\Kasir\TransactionReceiptController;
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
Route::get('/kasir/transaksi/{transaction}', [TransactionReceiptController::class, 'show'])
    ->whereNumber('transaction')
    ->name('kasir.transactions.show');
Route::get('/kasir/transaksi/{transaction}/struk', [TransactionReceiptController::class, 'print'])
    ->whereNumber('transaction')
    ->name('kasir.transactions.receipt');
Route::get('/kasir/transaksi/{transaction}/struk.pdf', [TransactionReceiptController::class, 'pdf'])
    ->whereNumber('transaction')
    ->middleware('throttle:60,1')
    ->name('kasir.transactions.receipt.pdf');

Route::get('/barang', Barang\Index::class)->name('barang');
