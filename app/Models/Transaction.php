<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number', 'user_id', 'cash_shift_id', 'member_id', 'subtotal', 'tax', 'tax_label', 'tax_rate', 'tax_included',
        'discount', 'rounding', 'total', 'paid', 'change', 'payment_method', 'item_count',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_included' => 'decimal:2',
            'rounding' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid' => 'decimal:2',
            'change' => 'decimal:2',
            'item_count' => 'integer',
        ];
    }

    /** Mutasi pemotongan tabungan untuk transaksi bermetode tabungan. */
    public function savingsEntry(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SavingsTransaction::class, 'source_id')->where('source_type', 'transaction')->where('type', SavingsTransaction::TYPE_WITHDRAWAL);
    }

    /** Bon yang tercatat dari transaksi bermetode bon. */
    public function credit(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MemberCredit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /** Kasir yang memproses transaksi. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rincian potongan (manual / promo / kupon); jumlahnya = kolom discount. */
    public function discounts(): HasMany
    {
        return $this->hasMany(TransactionDiscount::class);
    }

    /** Shift kasir saat transaksi dibuat (null = sebelum fitur shift / tanpa shift). */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    /** Terbaru dulu, stabil untuk transaksi pada detik yang sama. */
    /** Anggota pembeli (null = pembeli umum). */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed(); // riwayat tetap menampilkan data yang dihapus lunak
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Transaksi dalam rentang waktu (batas boleh null = terbuka). */
    public function scopeBetweenDates(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        $query->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end));
    }

    public function scopePaymentMethod(Builder $query, ?string $method): void
    {
        $query->when($method, fn ($q) => $q->where('payment_method', $method));
    }
}
