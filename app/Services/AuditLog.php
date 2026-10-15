<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\Actor;
use App\Support\Petugas;
use Illuminate\Support\Str;

/**
 * Pencatat jejak audit. Pelaku diambil dari petugas yang sedang masuk
 * (tahap ini: akun tiruan di session; tanpa sesi = "Sistem"), beserta IP & perangkat.
 * Dipanggil dari service (penjualan, stok, barang, anggota, tabungan, laporan) dan
 * dari alur login/pengguna. Gagal mencatat tidak boleh menggagalkan transaksi utama.
 */
class AuditLog
{
    private static ?string $reason = null;

    private static int $quiet = 0;

    /**
     * Jalankan $callback dengan alasan perubahan yang ikut tercatat pada audit data master.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withReason(?string $reason, callable $callback): mixed
    {
        $previous = self::$reason;
        self::$reason = filled($reason) ? Str::limit(trim($reason), 200, '') : null;
        try {
            return $callback();
        } finally {
            self::$reason = $previous;
        }
    }

    public static function reason(): ?string
    {
        return self::$reason;
    }

    /**
     * Jalankan $callback tanpa mencatat audit data master (mis. seeder data contoh).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function quietly(callable $callback): mixed
    {
        self::$quiet++;
        try {
            return $callback();
        } finally {
            self::$quiet--;
        }
    }

    public static function isQuiet(): bool
    {
        return self::$quiet > 0;
    }

    /**
     * @param  array{type?: string, id?: int|null, label?: string|null}|null  $subject
     * @param  array{id?: int|null, name?: string|null, role?: string|null}|null  $actor  timpa pelaku (mis. percobaan login)
     * @param  ?string  $action  aksi data master: dibuat/diubah/dihapus/dipulihkan
     * @param  ?array<string, mixed>  $old  nilai sebelum per kolom
     * @param  ?array<string, mixed>  $new  nilai sesudah per kolom
     */
    public static function record(
        string $event,
        string $category,
        string $description,
        ?array $subject = null,
        array $properties = [],
        ?int $amount = null,
        ?array $actor = null,
        ?string $action = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
    ): ?ActivityLog {
        try {
            $web = ! app()->runningInConsole() || app()->runningUnitTests();
            // selama impersonasi pelaku = admin pelaku (bukan akun target); keterangan impersonasi ikut ditandatangani HMAC
            // (catatan impersonation.* sudah membawa admin & id sesinya sendiri — tidak ditimpa keadaan sesi)
            $own = isset($properties['impersonation_id']);
            $user = $web && ! $own ? Actor::forAudit($actor ?? Petugas::current()) : ($actor ?? ($web ? Petugas::current() : null));
            $request = $web ? request() : null;
            if ($web && ! $own && ($imp = Actor::impersonation())) {
                $properties['impersonation'] = ['id' => $imp['id'], 'target_type' => $imp['target_type'], 'target_id' => $imp['target_id'], 'target_name' => $imp['target_name']];
            }

            return ActivityLog::create([
                'event' => $event,
                'category' => $category,
                'description' => Str::limit($description, 250),
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
                'subject_label' => isset($subject['label']) ? Str::limit($subject['label'], 145) : null,
                'actor_id' => $user['id'] ?? null,
                'actor_name' => $user['name'] ?? 'Sistem',
                'actor_role' => $user['role'] ?? null,
                'amount' => $amount,
                'properties' => $properties ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'action' => $action,
                'old_values' => $old,
                'new_values' => $new,
                'reason' => $reason !== null ? Str::limit($reason, 200, '') : null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Perubahan sebelum → sesudah untuk kolom tertentu (yang tidak berubah dilewati).
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if ((string) $from !== (string) $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }
}
