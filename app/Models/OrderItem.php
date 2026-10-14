<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris pesanan portal (nama & harga disalin saat memesan). */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'product_id', 'product_name', 'quantity', 'price', 'subtotal'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
