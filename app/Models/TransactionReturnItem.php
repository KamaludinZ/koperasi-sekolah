<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Barang yang dikembalikan dalam satu pengajuan retur/pembatalan. */
class TransactionReturnItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['transaction_return_id', 'transaction_item_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer'];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(TransactionReturn::class, 'transaction_return_id');
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
