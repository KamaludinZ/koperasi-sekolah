<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan koperasi dalam bentuk key-value (tabel settings). Kunci berformat
 * "kelompok.nama" (mis. profile.name, tax.rate); kolom type menentukan cara nilai
 * teks dibaca kembali (string/bool/int/float/json).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'group', 'value', 'type', 'updated_by'];

    public const DEFAULT_MIN_STOCK = 'stock.default_min_stock';

    public const TYPES = ['string', 'bool', 'int', 'float', 'json'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = Cache::rememberForever("setting:{$key}", fn () => static::where('key', $key)->first(['value', 'type'])?->only(['value', 'type']));

        return $row === null || $row['value'] === null ? $default : static::decode($row['value'], $row['type'] ?? 'string');
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $type = static::typeOf($value);
        static::updateOrCreate(['key' => $key], [
            'group' => strtok($key, '.') ?: 'general',
            'value' => static::encode($value, $type),
            'type' => $type,
            'updated_by' => $userId,
        ]);
        Cache::forget("setting:{$key}");
        Cache::forget(\App\Services\SettingsService::CACHE_KEY);
    }

    /** Semua nilai satu kelompok sebagai [nama => nilai], mis. group('tax') → ['rate' => 2.5, ...]. */
    public static function group(string $group): array
    {
        return static::where('group', $group)->get(['key', 'value', 'type'])
            ->mapWithKeys(fn (self $s) => [substr($s->key, strlen($group) + 1) => $s->value === null ? null : static::decode($s->value, $s->type)])
            ->all();
    }

    public static function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    public static function encode(mixed $value, string $type): ?string
    {
        return match (true) {
            $value === null => null,
            $type === 'bool' => $value ? '1' : '0',
            $type === 'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    public static function decode(string $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
