<?php

namespace App\Services;

use App\Models\ActivityLog;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tanda keaslian baris jejak audit: HMAC-SHA256 (kunci aplikasi) atas isi baris saat dicatat.
 * Baris yang diubah langsung di database (melewati trigger, mis. oleh pemilik database)
 * tidak lagi cocok dengan tandanya dan terdeteksi oleh verify() / `php artisan audit:verifikasi`.
 */
class AuditIntegrity
{
    /** Kolom yang ikut ditandatangani, berurutan. */
    public const FIELDS = [
        'event', 'category', 'action', 'description', 'subject_type', 'subject_id', 'subject_label',
        'actor_id', 'actor_name', 'actor_role', 'amount', 'properties', 'old_values', 'new_values', 'reason',
        'ip_address', 'user_agent', 'created_at',
    ];

    /**
     * Kolom atribusi impersonasi — ikut ditandatangani HANYA bila salah satunya terisi, sehingga catatan lama (tanpa
     * nilai) tetap memakai tanda tangan aslinya.
     */
    public const IMPERSONATION_FIELDS = ['impersonation_id', 'on_behalf_type', 'on_behalf_id'];

    /** @param  array<string, mixed>|object  $row  nilai mentah seperti tersimpan di database */
    public static function checksum(array|object $row): string
    {
        $row = (array) $row;
        $fields = self::FIELDS;
        foreach (self::IMPERSONATION_FIELDS as $f) {
            if (($row[$f] ?? null) !== null) {
                $fields = [...self::FIELDS, ...self::IMPERSONATION_FIELDS];
                break;
            }
        }
        $payload = implode("\x1F", array_map(function (string $field) use ($row) {
            $value = $row[$field] ?? null;

            return match (true) {
                $value === null => "\x00",
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
                is_bool($value) => $value ? '1' : '0',
                default => self::normalizeTime($field, (string) $value),
            };
        }, $fields));

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** Waktu dari database bisa membawa pecahan detik; samakan ke detik. */
    private static function normalizeTime(string $field, string $value): string
    {
        return $field === 'created_at' ? substr($value, 0, 19) : $value;
    }

    /**
     * Periksa tanda setiap baris (atau sejak id tertentu).
     *
     * @return array{checked: int, invalid: list<int>, unsigned: int, first_id: ?int, last_id: ?int}
     */
    public function verify(?int $fromId = null): array
    {
        $result = ['checked' => 0, 'invalid' => [], 'unsigned' => 0, 'first_id' => null, 'last_id' => null];

        DB::table('activity_logs')->when($fromId, fn ($q) => $q->where('id', '>=', $fromId))
            ->orderBy('id')->lazyById(1000)->each(function ($row) use (&$result) {
                $result['checked']++;
                $result['first_id'] ??= $row->id;
                $result['last_id'] = $row->id;
                if ($row->checksum === null) {
                    $result['unsigned']++;
                } elseif (! hash_equals($row->checksum, self::checksum($row))) {
                    $result['invalid'][] = (int) $row->id;
                }
            });

        return $result;
    }

    /** Apakah satu baris model masih cocok dengan tandanya. */
    public static function isValid(ActivityLog $log): bool
    {
        $raw = DB::table('activity_logs')->where('id', $log->id)->first();

        return $raw && $raw->checksum !== null && hash_equals($raw->checksum, self::checksum($raw));
    }
}
