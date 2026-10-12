<?php

use App\Livewire\Barang;
use App\Livewire\Kasir;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/kasir');

Route::get('/kasir', Kasir\Index::class)->name('kasir');

Route::get('/barang', Barang\Index::class)->name('barang');
