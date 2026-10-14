<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Jenis simpanan anggota (pokok/wajib/sukarela/lainnya), terpisah dari tabungan. */
class SavingType extends Model
{
    use Auditable;

    public const KINDS = ['pokok' => 'Pokok', 'wajib' => 'Wajib', 'sukarela' => 'Sukarela', 'lainnya' => 'Lainnya'];

    public const PERIODS = ['sekali' => 'Sekali (saat mendaftar)', 'bulanan' => 'Tiap bulan', 'bebas' => 'Bebas (kapan saja)'];

    public const AUDIT = [
        'type' => 'saving_type', 'category' => 'simpanan', 'noun' => 'jenis simpanan',
        'fields' => ['code', 'name', 'kind', 'amount', 'period', 'withdrawable', 'description', 'is_active'],
    ];

    protected $fillable = ['code', 'name', 'kind', 'amount', 'period', 'withdrawable', 'description', 'is_active', 'sort_order'];

    protected $attributes = ['withdrawable' => false, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'withdrawable' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function auditLabel(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
