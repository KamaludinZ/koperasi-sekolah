<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transaksi kas harian: setoran (in) atau penarikan (out) uang laci di luar penjualan pada
 * sebuah shift, dengan kategori & alasan wajib. Tidak diubah setelah dibuat.
 */
class CashMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPES = [self::TYPE_IN => 'Uang masuk laci', self::TYPE_OUT => 'Uang keluar laci'];

    public const CATEGORIES = [
        'kembalian' => 'Uang kembalian',
        'bendahara' => 'Bendahara koperasi',
        'operasional' => 'Biaya operasional',
        'lainnya' => 'Lainnya',
    ];

    /** Kategori yang lazim per jenis (untuk pilihan di form). */
    public const CATEGORIES_BY_TYPE = [
        self::TYPE_IN => ['kembalian', 'bendahara', 'lainnya'],
        self::TYPE_OUT => ['bendahara', 'operasional', 'lainnya'],
    ];

    protected $fillable = ['cash_shift_id', 'type', 'category', 'amount', 'reason', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    /** Petugas yang mencatat. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function scopeOnDate(Builder $query, Carbon|string $date): void
    {
        $day = Carbon::parse($date);
        $query->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
    }

    /**
     * Rekap kas masuk/keluar satu hari: total per jenis, jumlah catatan, dan total per kategori.
     *
     * @return array{in: int, out: int, net: int, count: int, by_category: array<string, array{in: int, out: int}>}
     */
    public static function dailyTotals(Carbon|string $date): array
    {
        $rows = static::onDate($date)
            ->groupBy('type', 'category')
            ->selectRaw('type, category, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')
            ->get();
        $sum = fn (string $type) => (int) round((float) $rows->where('type', $type)->sum('total'));

        return [
            'in' => $sum(self::TYPE_IN),
            'out' => $sum(self::TYPE_OUT),
            'net' => $sum(self::TYPE_IN) - $sum(self::TYPE_OUT),
            'count' => (int) $rows->sum('n'),
            'by_category' => collect(self::CATEGORIES)->map(fn ($label, $key) => [
                'in' => (int) round((float) $rows->where('category', $key)->where('type', self::TYPE_IN)->sum('total')),
                'out' => (int) round((float) $rows->where('category', $key)->where('type', self::TYPE_OUT)->sum('total')),
            ])->all(),
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /** Nominal bertanda: + masuk, − keluar. */
    public function getSignedAmountAttribute(): int
    {
        $amount = (int) round((float) $this->amount);

        return $this->type === self::TYPE_OUT ? -$amount : $amount;
    }
}
