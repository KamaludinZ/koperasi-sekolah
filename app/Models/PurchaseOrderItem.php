<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu barang dalam pesanan pembelian: salinan kode/nama/satuan, jumlah dipesan & diterima, harga beli. */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_id', 'product_code', 'product_name', 'unit', 'quantity', 'received_quantity', 'cost'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_quantity' => 'integer', 'cost' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function remaining(): int
    {
        return max(0, $this->quantity - $this->received_quantity);
    }

    public function subtotal(): int
    {
        return $this->quantity * $this->cost;
    }
}
