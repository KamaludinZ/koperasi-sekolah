<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Promo otomatis di kasir. Jenis: persen (potongan % per barang), nominal (potongan rupiah
 * per barang), beli_gratis (beli X gratis Y), grosir (harga per pcs mulai min_qty).
 * Cakupan: semua barang, kategori, atau barang tertentu (promo_targets). Status dihitung dari
 * saklar aktif & periode: aktif | terjadwal | berakhir | nonaktif.
 */
class Promo extends Model
{
    use HasFactory;

    public const TYPES = [
        'persen' => 'Potongan persen',
        'nominal' => 'Potongan rupiah',
        'beli_gratis' => 'Beli X gratis Y',
        'grosir' => 'Harga grosir',
    ];

    public const SCOPES = ['semua' => 'Semua barang', 'kategori' => 'Kategori', 'barang' => 'Barang tertentu'];

    protected $fillable = [
        'name', 'type', 'value', 'buy_qty', 'free_qty', 'min_qty', 'min_purchase', 'scope',
        'starts_on', 'ends_on', 'days', 'time_from', 'time_to', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'buy_qty' => 'integer',
            'free_qty' => 'integer',
            'min_qty' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PromoTarget::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promo_targets')->whereNotNull('promo_targets.category_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promo_targets')->whereNotNull('promo_targets.product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Potongan yang sudah diberikan promo ini di transaksi. */
    public function discounts(): HasMany
    {
        return $this->hasMany(TransactionDiscount::class);
    }

    /** Aktif dan dalam periode pada tanggal tertentu (hari & jam dicek terpisah). */
    public function scopeRunningOn(Builder $query, Carbon|string $date): void
    {
        $day = Carbon::parse($date)->toDateString();
        $query->where('is_active', true)->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day);
    }

    public function getStatusAttribute(): string
    {
        return self::periodStatus($this->starts_on, $this->ends_on, $this->is_active);
    }

    public static function periodStatus(Carbon $start, Carbon $end, bool $active): string
    {
        return match (true) {
            ! $active => 'nonaktif',
            today()->lt($start->copy()->startOfDay()) => 'terjadwal',
            today()->gt($end->copy()->startOfDay()) => 'berakhir',
            default => 'aktif',
        };
    }
}
