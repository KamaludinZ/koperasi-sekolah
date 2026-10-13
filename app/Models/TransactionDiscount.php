<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian satu potongan pada transaksi: diskon manual kasir, promo otomatis, atau kupon.
 * `label` adalah snapshot (nama promo / kode kupon) agar riwayat tetap terbaca walau
 * promo/kupon diubah. Jumlah semua potongan = transactions.discount. Tidak diubah setelah dibuat.
 */
class TransactionDiscount extends Model
{
    public const UPDATED_AT = null;

    public const SOURCES = ['manual' => 'Diskon manual', 'promo' => 'Promo otomatis', 'kupon' => 'Kupon'];

    protected $fillable = ['transaction_id', 'source', 'promo_id', 'coupon_id', 'label', 'amount', 'reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
