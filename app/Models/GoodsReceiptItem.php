<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu barang yang diterima: jumlah, harga beli, item pesanan asal, dan mutasi stok yang dibuat. */
class GoodsReceiptItem extends Model
{
    protected $fillable = ['goods_receipt_id', 'purchase_order_item_id', 'product_id', 'quantity', 'cost', 'stock_movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'cost' => 'integer'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
