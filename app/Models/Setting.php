<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan koperasi dalam bentuk key-value (tabel settings).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public const DEFAULT_MIN_STOCK = 'stock.default_min_stock';

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever("settings.{$key}", fn () => static::where('key', $key)->value('value'));

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("settings.{$key}");
    }
}
