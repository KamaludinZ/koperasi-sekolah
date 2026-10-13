<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tagihan (hutang) ke supplier untuk satu penerimaan barang (lihat SupplierBillService). */
class SupplierBill extends Model
{
    public const STATUSES = ['belum' => 'Belum dibayar', 'sebagian' => 'Dibayar sebagian', 'lunas' => 'Lunas'];

    protected $fillable = ['supplier_id', 'purchase_order_id', 'goods_receipt_id', 'amount', 'paid', 'due_at', 'status', 'paid_off_at'];

    protected $attributes = ['paid' => 0, 'status' => 'belum'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid' => 'integer', 'due_at' => 'date', 'paid_off_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', 'lunas');
    }

    public function remaining(): int
    {
        return max(0, $this->amount - $this->paid);
    }

    /** Hari menuju jatuh tempo (negatif = lewat). */
    public function daysLeft(): int
    {
        return (int) today()->diffInDays($this->due_at, false);
    }

    /** Selaraskan paid & status dengan jumlah pembayaran. */
    public function syncPaid(): void
    {
        $paid = (int) $this->payments()->sum('amount');
        $this->forceFill([
            'paid' => $paid,
            'status' => $paid >= $this->amount ? 'lunas' : ($paid > 0 ? 'sebagian' : 'belum'),
            'paid_off_at' => $paid >= $this->amount ? ($this->paid_off_at ?? now()) : null,
        ])->save();
    }
}
