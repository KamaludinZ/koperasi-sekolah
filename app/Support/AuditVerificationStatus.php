<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;

/**
 * Hasil pemeriksaan keaslian jejak audit (checksum HMAC tiap baris activity_logs) yang terakhir — dari jadwal harian
 * (audit:verifikasi) atau pemeriksaan manual Admin. Disimpan di cache aplikasi agar halaman audit bisa menampilkan
 * kapan terakhir diperiksa, hasilnya, dan baris mana yang tidak cocok.
 */
class AuditVerificationStatus
{
    public const KEY = 'audit:verifikasi:terakhir';

    /** @param  array{checked: int, invalid: list<int>, unsigned: int, first_id: ?int, last_id: ?int}  $result */
    public static function store(array $result, string $source, ?string $by = null): array
    {
        $row = [
            'at' => now()->toIso8601String(), 'source' => $source, 'by' => $by,
            'checked' => (int) $result['checked'], 'unsigned' => (int) $result['unsigned'],
            'first_id' => $result['first_id'], 'last_id' => $result['last_id'],
            'invalid_count' => count($result['invalid']), 'invalid' => array_slice(array_map('intval', $result['invalid']), 0, 50),
            'ok' => $result['invalid'] === [],
        ];
        Cache::forever(self::KEY, $row);

        return $row;
    }

    /** @return ?array{at: string, source: string, by: ?string, checked: int, unsigned: int, first_id: ?int, last_id: ?int, invalid_count: int, invalid: list<int>, ok: bool} */
    public static function last(): ?array
    {
        $row = Cache::get(self::KEY);

        return is_array($row) && isset($row['at'], $row['checked']) ? $row : null;
    }

    /**
     * Rincian baris yang tidak cocok (untuk ditampilkan): id, peristiwa, waktu, uraian.
     *
     * @return list<array{id: int, event: string, at: ?string, description: string}>
     */
    public static function invalidRows(?array $last, int $limit = 10): array
    {
        if (! $last || $last['invalid'] === []) {
            return [];
        }

        return ActivityLog::query()->whereIn('id', array_slice($last['invalid'], 0, $limit))->orderBy('id')
            ->get(['id', 'event', 'created_at', 'description'])
            ->map(fn ($l) => ['id' => (int) $l->id, 'event' => $l->event, 'at' => $l->created_at?->toDateTimeString(), 'description' => $l->description])
            ->all();
    }
}
