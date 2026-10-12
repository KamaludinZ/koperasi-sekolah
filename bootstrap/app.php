<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Batasi menu sesuai peran petugas.
        // Sesi petugas (akun nonaktif / tidak aktif terlalu lama) lalu batasi menu sesuai peran.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureActiveSession::class,
            \App\Http\Middleware\EnsureMenuAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
