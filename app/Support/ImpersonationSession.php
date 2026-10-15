<?php

namespace App\Support;

/**
 * Status impersonasi yang sedang berjalan di sesi ini (kontrak data antara layanan impersonasi, spanduk penanda,
 * dan pencatat audit). Disimpan di session `impersonation`:
 *   id (uuid sesi impersonasi), admin_id, admin_name, target_type ('petugas'|'anggota'), target_id, target_name,
 *   target_label (mis. "Kasir" / "SIS-0012 · 7A"), reason, started_at & expires_at (unix timestamp),
 *   return_to (opsional: halaman asal admin, path internal yang sudah disaring).
 * Nilai yang rusak/tidak lengkap dianggap tidak ada (tidak pernah dipercaya setengah-setengah).
 */
class ImpersonationSession
{
    public const KEY = 'impersonation';

    private const REQUIRED = ['id', 'admin_id', 'admin_name', 'target_type', 'target_id', 'target_name', 'reason', 'started_at', 'expires_at'];

    /** @return ?array{id: string, admin_id: int, admin_name: string, target_type: string, target_id: int, target_name: string, target_label: ?string, reason: string, started_at: int, expires_at: int} */
    public static function current(): ?array
    {
        if (! app()->bound('session')) {
            return null;
        }
        $s = session(self::KEY);
        if (! is_array($s) || array_diff(self::REQUIRED, array_keys($s)) !== [] || ! in_array($s['target_type'], ['petugas', 'anggota'], true)) {
            return null;
        }

        return [
            'id' => (string) $s['id'], 'admin_id' => (int) $s['admin_id'], 'admin_name' => (string) $s['admin_name'],
            'target_type' => $s['target_type'], 'target_id' => (int) $s['target_id'], 'target_name' => (string) $s['target_name'],
            'target_label' => isset($s['target_label']) ? (string) $s['target_label'] : null, 'reason' => (string) $s['reason'],
            'started_at' => (int) $s['started_at'], 'expires_at' => (int) $s['expires_at'],
            'return_to' => isset($s['return_to']) && is_string($s['return_to']) ? $s['return_to'] : null,
        ];
    }

    /** Sedang masuk sebagai anggota portal (portal = mode lihat saja untuk aksi uang & PIN). */
    public static function impersonatingMember(): bool
    {
        return (self::current()['target_type'] ?? null) === 'anggota';
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    /** Detik tersisa sebelum sesi impersonasi berakhir otomatis (0 bila sudah lewat / tidak aktif). */
    public static function remainingSeconds(): int
    {
        $s = self::current();

        return $s ? max(0, $s['expires_at'] - now()->timestamp) : 0;
    }

    public static function expired(): bool
    {
        $s = self::current();

        return $s !== null && $s['expires_at'] <= now()->timestamp;
    }
}
