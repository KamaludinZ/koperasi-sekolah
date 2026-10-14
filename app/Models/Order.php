<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pesanan checkout mandiri anggota (portal). */
class Order extends Model
{
    public const STATUSES = ['menunggu' => 'Menunggu', 'diproses' => 'Diproses', 'siap' => 'Siap diambil/diantar', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];

    public const FULFILLMENTS = ['ambil' => 'Ambil sendiri di koperasi', 'antar' => 'Diantar'];

    public const PAYMENTS = ['tabungan' => 'Potong saldo tabungan', 'simpanan' => 'Potong saldo simpanan', 'tunai' => 'Tunai di kasir', 'qris' => 'QRIS / transfer'];

    public const PAYMENT_STATUSES = ['belum' => 'Belum dibayar', 'lunas' => 'Lunas', 'dikembalikan' => 'Dikembalikan'];

    protected $fillable = ['code', 'member_id', 'status', 'fulfillment', 'delivery_to', 'payment_method', 'saving_type_id', 'payment_status',
        'subtotal', 'discount', 'tax', 'tax_included', 'rounding', 'total', 'item_count', 'note', 'cancel_reason', 'transaction_id', 'client_ref', 'paid_at', 'completed_at'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'tax_included' => 'decimal:2', 'rounding' => 'decimal:2', 'total' => 'decimal:2', 'item_count' => 'integer',
            'paid_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function savingType(): BelongsTo
    {
        return $this->belongsTo(SavingType::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
