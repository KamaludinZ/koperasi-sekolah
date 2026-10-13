<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Satu kedatangan barang dari supplier untuk sebuah pesanan pembelian (lihat GoodsReceiptService). */
class GoodsReceipt extends Model
{
    protected $fillable = ['number', 'purchase_order_id', 'supplier_id', 'received_at', 'delivery_note', 'note', 'total_quantity', 'total_value', 'received_by'];

    protected function casts(): array
    {
        return ['received_at' => 'date', 'total_quantity' => 'integer', 'total_value' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('id');
    }

    /** Nomor berikutnya untuk bulan berjalan: TB-YYYYMM-NNNN. */
    public static function nextNumber(): string
    {
        $prefix = 'TB-'.now()->format('Ym').'-';
        $last = DB::table('goods_receipts')->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, -4) + 1), 4, '0', STR_PAD_LEFT);
    }
}
