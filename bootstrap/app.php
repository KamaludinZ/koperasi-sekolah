<?php

use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsurePortalMember;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\TrackImpersonation;
use App\Http\Middleware\TrackSlowRequests;
use App\Support\AccessDenied;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Paksa HTTPS + header keamanan untuk semua permintaan (termasuk API & aset dinamis).
        // Dipasang setelah TrustProxies agar X-Forwarded-Proto dari proxy tepercaya ikut dibaca.
        $middleware->append(ForceHttps::class);

        // Batasi menu sesuai peran petugas.
        // Sesi petugas: keluarkan akun nonaktif / sesi yang terlalu lama tidak aktif.
        // TrackImpersonation lebih dulu: impersonasi yang tak sah lagi diakhiri & admin dipulihkan sebelum sesi diperiksa.
        $middleware->web(append: [TrackImpersonation::class, EnsureActiveSession::class, TrackSlowRequests::class]);

        // Tamu di portal anggota diarahkan ke halaman masuk anggota, bukan login petugas.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('portal', 'portal/*') ? route('portal.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('portal', 'portal/*') ? route('portal') : '/');

        // Pembatas akses di rute: menu:<kunci> (wajib masuk + hak menu), peran:<nama,...>.
        $middleware->alias([
            'menu' => EnsureMenuAccess::class,
            'peran' => EnsureRole::class,
            'portal' => EnsurePortalMember::class,
        ]);
        // Penjaga akses dijalankan SEBELUM pengikatan model rute ({pinjaman}, {user}, …): petugas di luar gabungan
        // izinnya selalu mendapat 403 — tidak 404 untuk data yang tidak ada — sehingga ada/tidaknya data tidak bocor.
        // EnsureActiveSession tetap paling depan: akun nonaktif/tanpa peran/sesi kedaluwarsa dikeluarkan dulu, bukan 403.
        foreach ([TrackImpersonation::class, EnsureActiveSession::class, EnsureMenuAccess::class, EnsureRole::class, EnsurePortalMember::class] as $guard) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $guard);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Catat percobaan akses yang ditolak (403) oleh petugas yang masuk — menu, peran, atau izin.
        $exceptions->render(function (HttpExceptionInterface|AuthorizationException $e, Request $request) {
            $status = $e instanceof AuthorizationException ? 403 : $e->getStatusCode();
            if ($status === 403 && $request->user()) {
                AccessDenied::record($request, $e->getMessage());
            }

            return null; // tampilan bawaan (errors/403) tetap dipakai
        });
    })->create();
