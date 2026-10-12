<?php

use App\Livewire\Kasir;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/kasir');

Route::get('/kasir', Kasir\Index::class)->name('kasir');
