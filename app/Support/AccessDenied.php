<?php

namespace App\Support;

use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pencatatan percobaan akses yang ditolak (403) ke jejak audit (kategori auth, event auth.access_denied):
 * petugas, alamat, menu/peran yang dibutuhkan (pesan penolakan), dan IP. Satu catatan per petugas per
 * alamat setiap THROTTLE_MINUTES menit agar audit tidak banjir oleh percobaan berulang.
 */
class AccessDenied
{
    public const THROTTLE_MINUTES = 10;

    public static function record(Request $request, ?string $reason = null): void
    {
        try {
            $user = $request->user();
            $path = '/'.ltrim($request->path(), '/');
            if (! Cache::add('denied:'.$user->id.':'.md5($request->method().$path), 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
                return;
            }
            $reason = $reason && ! in_array($reason, ['Forbidden', 'This action is unauthorized.'], true) ? $reason : 'Tidak punya izin.';
            AuditLog::record('auth.access_denied', 'auth', "Akses ditolak: {$request->method()} {$path} — {$reason}",
                ['type' => 'user', 'id' => $user->id, 'label' => $user->name],
                ['path' => $path, 'method' => $request->method(), 'route' => $request->route()?->getName()], reason: $reason);
        } catch (Throwable) {
            // pencatatan tidak boleh menggagalkan respons 403
        }
    }
}
