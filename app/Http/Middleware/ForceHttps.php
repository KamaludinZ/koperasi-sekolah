<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paksa koneksi terenkripsi bila `koperasi.force_https` aktif:
 * - GET/HEAD lewat http → 301 ke alamat https yang sama; metode lain → 426 (data tidak diteruskan);
 * - respons https diberi header HSTS (`koperasi.hsts_max_age`).
 * Selalu menambah header keamanan dasar: nosniff, frame hanya dari situs sendiri, referrer ringkas,
 * dan izin kamera hanya untuk situs sendiri (pemindai barcode). Alamat `koperasi.https_except` dikecualikan.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        $force = (bool) config('koperasi.force_https');

        if ($force && ! $request->secure() && ! $request->is(...config('koperasi.https_except', []))) {
            if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                abort(426, 'Gunakan koneksi aman (https).');
            }

            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        // CSP ringan yang tidak mengganggu Alpine/Livewire: cegah clickjacking, <base> & form ke domain lain, plugin.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
        }
        if ($force && $request->secure() && ($age = (int) config('koperasi.hsts_max_age')) > 0) {
            $headers->set('Strict-Transport-Security', "max-age={$age}; includeSubDomains");
        }

        return $response;
    }
}
