<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perubahan stok barang. Tambahkan lewat App\Services\StockService
 * agar stok barang & catatan riwayat selalu konsisten.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_IN = 'masuk';

    public const TYPE_OUT = 'keluar';

    public const TYPE_ADJUSTMENT = 'penyesuaian';

    public const TYPES = [
        self::TYPE_IN => 'Stok masuk',
        self::TYPE_OUT => 'Stok keluar',
        self::TYPE_ADJUSTMENT => 'Penyesuaian',
    ];

    protected $fillable = ['product_id', 'type', 'quantity', 'stock_before', 'stock_after', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Perubahan bertanda: + untuk masuk, − untuk keluar, selisih untuk penyesuaian. */
    public function getSignedQuantityAttribute(): int
    {
        if ($this->stock_before !== null && $this->stock_after !== null) {
            return $this->stock_after - $this->stock_before;
        }

        return $this->type === self::TYPE_OUT ? -$this->quantity : $this->quantity;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
