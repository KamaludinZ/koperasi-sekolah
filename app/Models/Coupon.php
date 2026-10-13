<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kupon/voucher: kode (huruf besar tanpa spasi), potongan persen (dengan batas maks.) atau
 * rupiah, minimal belanja, kuota total & per anggota, masa berlaku. Pemakaian dicatat di
 * transaction_discounts (source = kupon). Status: aktif | terjadwal | berakhir | habis | nonaktif.
 */
class Coupon extends Model
{
    use HasFactory;

    public const TYPES = ['persen' => 'Potongan persen', 'nominal' => 'Potongan rupiah'];

    protected $fillable = [
        'code', 'name', 'type', 'value', 'max_discount', 'min_purchase', 'quota', 'per_member',
        'starts_on', 'ends_on', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'quota' => 'integer',
            'per_member' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** Kode selalu disimpan huruf besar tanpa spasi. */
    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = self::normalize($value);
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $code));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(TransactionDiscount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Jumlah pemakaian (pakai `redemptions_count` bila sudah dimuat lewat withCount). */
    public function usedCount(): int
    {
        return (int) ($this->redemptions_count ?? $this->redemptions()->count());
    }

    public function getStatusAttribute(): string
    {
        $status = Promo::periodStatus($this->starts_on, $this->ends_on, $this->is_active);

        return $status === 'aktif' && $this->quota !== null && $this->usedCount() >= $this->quota ? 'habis' : $status;
    }
}
