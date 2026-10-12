<?php

use App\Http\Controllers\Barang\ProductController;
use App\Http\Controllers\Barang\ProductListController;
use App\Http\Controllers\Barang\StockAlertController;
use App\Http\Controllers\Kasir\CheckoutController;
use App\Http\Controllers\Kasir\ProductSearchController;
use App\Http\Controllers\Kasir\TransactionReceiptController;
use App\Http\Controllers\Transaksi\TransactionListController;
use App\Livewire\Anggota;
use App\Livewire\Barang;
use App\Livewire\Kasir;
use App\Livewire\Laporan;
use App\Livewire\Transaksi;
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
Route::get('/barang/daftar', ProductListController::class)
    ->middleware('throttle:120,1')
    ->name('barang.list');
Route::post('/barang', [ProductController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('barang.store');
Route::put('/barang/{product}', [ProductController::class, 'update'])
    ->whereNumber('product')
    ->middleware('throttle:60,1')
    ->name('barang.update');
Route::get('/barang/stok-menipis', [StockAlertController::class, 'lowStock'])->name('barang.low-stock');
Route::get('/barang/batas-minimum', [StockAlertController::class, 'minStocks'])->name('barang.min-stock');
Route::put('/barang/batas-minimum', [StockAlertController::class, 'updateMinStocks'])
    ->middleware('throttle:30,1')
    ->name('barang.min-stock.update');

Route::get('/transaksi', Transaksi\Index::class)->name('transaksi');
Route::get('/transaksi/daftar', TransactionListController::class)
    ->middleware('throttle:120,1')
    ->name('transaksi.list');
Route::get('/transaksi/{transaction}', Transaksi\Show::class)
    ->whereNumber('transaction')
    ->name('transaksi.show');

Route::get('/anggota', Anggota\Index::class)->name('anggota');
Route::get('/anggota/{member}', Anggota\Show::class)
    ->whereNumber('member')
    ->name('anggota.show');

Route::get('/laporan', Laporan\Index::class)->name('laporan');
