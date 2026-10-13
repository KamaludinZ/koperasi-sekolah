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
        // Paksa HTTPS + header keamanan untuk semua permintaan (termasuk API & aset dinamis).
        // Dipasang setelah TrustProxies agar X-Forwarded-Proto dari proxy tepercaya ikut dibaca.
        $middleware->append(\App\Http\Middleware\ForceHttps::class);

        // Batasi menu sesuai peran petugas.
        // Sesi petugas: keluarkan akun nonaktif / sesi yang terlalu lama tidak aktif.
        $middleware->web(append: [\App\Http\Middleware\EnsureActiveSession::class, \App\Http\Middleware\TrackSlowRequests::class]);

        // Pembatas akses di rute: menu:<kunci> (wajib masuk + hak menu), peran:<nama,...>.
        $middleware->alias([
            'menu' => \App\Http\Middleware\EnsureMenuAccess::class,
            'peran' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Catat percobaan akses yang ditolak (403) oleh petugas yang masuk — menu, peran, atau izin.
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|Illuminate\Auth\Access\AuthorizationException $e, Illuminate\Http\Request $request) {
            $status = $e instanceof Illuminate\Auth\Access\AuthorizationException ? 403 : $e->getStatusCode();
            if ($status === 403 && $request->user()) {
                App\Support\AccessDenied::record($request, $e->getMessage());
            }

            return null; // tampilan bawaan (errors/403) tetap dipakai
        });
    })->create();
