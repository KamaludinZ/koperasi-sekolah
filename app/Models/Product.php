<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    use Auditable;
    use HasFactory;

    /** Audit data master; stok dicatat terpisah lewat riwayat stok. */
    public const AUDIT = [
        'type' => 'product', 'category' => 'barang', 'noun' => 'barang',
        'fields' => ['code', 'name', 'category_id', 'price', 'cost_price', 'min_stock', 'unit', 'is_active'],
    ];

    protected $fillable = [
        'code', 'name', 'category_id', 'price', 'cost_price',
        'stock', 'min_stock', 'unit', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /** Harga sebagai angka bulat dan kategori sebagai nama. */
    public function auditValues(array $values): array
    {
        foreach (['price', 'cost_price'] as $f) {
            if (array_key_exists($f, $values) && $values[$f] !== null) {
                $values[$f] = (int) $values[$f];
            }
        }
        if (array_key_exists('category_id', $values)) {
            $values['category_id'] = $values['category_id'] ? Category::whereKey($values['category_id'])->value('name') : null;
        }

        return $values;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latestFirst();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Barang yang stoknya habis atau sama/di bawah batas minimum. */
    public function scopeLowStock(Builder $query): void
    {
        $query->whereColumn('stock', '<=', 'min_stock');
    }
}
