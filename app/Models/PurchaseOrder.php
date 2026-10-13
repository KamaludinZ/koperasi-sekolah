<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Pesanan pembelian barang ke supplier (lihat PurchaseOrderService). Nomor PO-YYYYMM-NNNN.
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draf' => 'Draf',
        'dipesan' => 'Dipesan',
        'sebagian' => 'Diterima sebagian',
        'diterima' => 'Diterima',
        'dibatalkan' => 'Dibatalkan',
    ];

    /** Status yang masih menunggu barang datang. */
    public const WAITING = ['dipesan', 'sebagian'];

    protected $fillable = [
        'number', 'supplier_id', 'status', 'ordered_at', 'expected_at', 'note', 'terms_days', 'total',
        'created_by', 'sent_at', 'received_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = ['status' => 'draf', 'total' => 0, 'terms_days' => 0];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'date',
            'expected_at' => 'date',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total' => 'integer',
            'terms_days' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('received_at')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeWaiting(Builder $query): void
    {
        $query->whereIn('status', self::WAITING);
    }

    public function isLate(): bool
    {
        return in_array($this->status, self::WAITING, true) && $this->expected_at && $this->expected_at->lt(today());
    }

    /** Nomor berikutnya untuk bulan berjalan: PO-YYYYMM-NNNN. */
    public static function nextNumber(): string
    {
        $prefix = 'PO-'.now()->format('Ym').'-';
        $last = DB::table('purchase_orders')->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, -4) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Hitung ulang total dari item. */
    public function recalculate(): void
    {
        $this->forceFill(['total' => (int) $this->items()->sum(DB::raw('quantity * cost'))])->save();
    }
}
