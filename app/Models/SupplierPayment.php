<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pembayaran hutang supplier (tunai/transfer). */
class SupplierPayment extends Model
{
    public const METHODS = ['tunai' => 'Tunai', 'transfer' => 'Transfer'];

    protected $fillable = ['supplier_bill_id', 'amount', 'paid_at', 'method', 'reference', 'note', 'paid_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'date'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
