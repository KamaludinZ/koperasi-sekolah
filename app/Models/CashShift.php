<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Shift kasir atas satu laci kas: dibuka dengan modal awal, ditutup dengan hitungan uang.
 * Kas seharusnya = modal awal + penjualan tunai + kas masuk − kas keluar; saat tutup,
 * kas seharusnya, uang dihitung, dan selisihnya disimpan sebagai snapshot.
 * Buka/tutup lewat layanan shift agar hanya ada satu shift terbuka.
 */
class CashShift extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const NAMES = ['Shift pagi', 'Shift siang', 'Shift sore'];

    protected $fillable = [
        'name', 'status', 'user_id', 'closed_by', 'opened_at', 'closed_at',
        'opening_cash', 'expected_cash', 'counted_cash', 'difference', 'close_note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    /** Petugas yang membuka shift. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    /** Shift yang dibuka pada tanggal tertentu. */
    public function scopeOnDate(Builder $query, Carbon|string $date): void
    {
        $day = Carbon::parse($date);
        $query->whereBetween('opened_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Penjualan tunai shift ini (yang masuk laci = total transaksi tunai). */
    public function cashSales(): int
    {
        return (int) round((float) $this->transactions()->where('payment_method', 'tunai')->sum('total'));
    }

    /** Penjualan non-tunai (QRIS/transfer) — tidak masuk laci. */
    public function nonCashSales(): int
    {
        return (int) round((float) $this->transactions()->where('payment_method', '!=', 'tunai')->sum('total'));
    }

    /** Total kas masuk ('in') atau keluar ('out') di luar penjualan. */
    public function movementTotal(string $type): int
    {
        return (int) round((float) $this->movements()->where('type', $type)->sum('amount'));
    }

    /** Kas seharusnya di laci saat ini (dihitung ulang dari data). */
    public function expectedCash(): int
    {
        return (int) round((float) $this->opening_cash) + $this->cashSales() + $this->movementTotal(CashMovement::TYPE_IN) - $this->movementTotal(CashMovement::TYPE_OUT);
    }
}
