<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Salinan katalog yang diunduh perangkat kasir untuk mode offline (harga & pajak saat itu). */
class PosOfflineCatalog extends Model
{
    use HasUlids, MassPrunable;

    public $timestamps = false;

    protected $fillable = ['user_id', 'device_id', 'prices', 'tax', 'product_count', 'issued_at', 'expires_at'];

    protected function casts(): array
    {
        return ['prices' => 'array', 'tax' => 'array', 'issued_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Salinan yang sudah kedaluwarsa lebih dari 30 hari dibuang (php artisan model:prune). */
    public function prunable()
    {
        return static::where('expires_at', '<', now()->subDays(30));
    }
}
