<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kupon/voucher: kode (huruf besar tanpa spasi), potongan persen (dengan batas maks.) atau
 * rupiah, minimal belanja, kuota total & per anggota, masa berlaku. Pemakaian dicatat di
 * transaction_discounts (source = kupon) dan penghitung used_count (sisa kuota). Status: aktif | terjadwal | berakhir | habis | nonaktif.
 * Setiap perubahan tercatat di audit.
 */
class Coupon extends Model
{
    use Auditable;
    use HasFactory;

    public const AUDIT = [
        'type' => 'coupon', 'category' => 'promo', 'noun' => 'kupon',
        'fields' => ['code', 'name', 'type', 'value', 'max_discount', 'min_purchase', 'quota', 'per_member', 'starts_on', 'ends_on', 'is_active'],
    ];

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
            'used_count' => 'integer',
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

    public function auditLabel(): string
    {
        return $this->code.' · '.$this->name;
    }

    public function auditTitle(): string
    {
        return $this->code;
    }

    public function auditValues(array $values): array
    {
        return Promo::readable($values, ['type' => self::TYPES]);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(TransactionDiscount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Jumlah pemakaian (penghitung `used_count`, dinaikkan/dikembalikan oleh CouponService). */
    public function usedCount(): int
    {
        return (int) $this->used_count;
    }

    /** Sisa kuota; null = tanpa batas. */
    public function remaining(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->usedCount());
    }

    public function getStatusAttribute(): string
    {
        $status = Promo::periodStatus($this->starts_on, $this->ends_on, $this->is_active);

        return $status === 'aktif' && $this->quota !== null && $this->usedCount() >= $this->quota ? 'habis' : $status;
    }
}
