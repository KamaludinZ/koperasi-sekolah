<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\MockData;
use Illuminate\Support\Str;

/**
 * Pencatat jejak audit. Pelaku diambil dari petugas yang sedang masuk
 * (tahap ini: akun tiruan di session; tanpa sesi = "Sistem"), beserta IP & perangkat.
 * Dipanggil dari service (penjualan, stok, barang, anggota, tabungan, laporan) dan
 * dari alur login/pengguna. Gagal mencatat tidak boleh menggagalkan transaksi utama.
 */
class AuditLog
{
    /**
     * @param  array{type?: string, id?: int|null, label?: string|null}|null  $subject
     * @param  array{id?: int|null, name?: string|null, role?: string|null}|null  $actor  timpa pelaku (mis. percobaan login)
     */
    public static function record(
        string $event,
        string $category,
        string $description,
        ?array $subject = null,
        array $properties = [],
        ?int $amount = null,
        ?array $actor = null,
    ): ?ActivityLog {
        try {
            $user = $actor ?? (app()->runningInConsole() && ! app()->runningUnitTests() ? null : MockData::currentUser());
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

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
