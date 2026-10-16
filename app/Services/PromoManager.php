<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat, mengubah, menyalakan/mematikan, dan menghapus promo otomatis & kupon (form Diskon & Promo).
 * Aturan bentuk data diperiksa di form; di sini dijaga aturan bisnis yang tidak boleh lolos dari jalur mana pun:
 * sasaran promo sesuai cakupan (kategori/barang yang ada), beli X gratis Y memakai min_qty = X + Y, kode kupon
 * unik, kuota tidak di bawah pemakaian, dan promo/kupon yang sudah dipakai di transaksi tidak bisa dihapus
 * (cukup dinonaktifkan) agar laporan diskon tetap utuh. Perubahan kolom tercatat lewat MasterDataObserver;
 * perubahan sasaran promo dicatat terpisah.
 */
class PromoManager
{
    public const MAX_TARGETS = 100;

    /**
     * @param  array{name: string, type: string, value: int, buy_qty?: ?int, free_qty?: ?int, min_qty?: int, min_purchase: int, scope: string,
     *               category_ids?: list<int>, product_ids?: list<int>, starts_on: string, ends_on: string, days: list<int>, time_from?: ?string, time_to?: ?string, is_active: bool}  $data
     */
    public function savePromo(?Promo $promo, array $data, User $actor): Promo
    {
        $type = $data['type'];
        $scope = $data['scope'];
        $targets = match ($scope) {
            'kategori' => Category::whereIn('id', $data['category_ids'] ?? [])->orderBy('id')->pluck('id')->all(),
            'barang' => Product::whereIn('id', $data['product_ids'] ?? [])->orderBy('id')->pluck('id')->all(),
            default => [],
        };
        if ($scope !== 'semua' && $targets === []) {
            throw ValidationException::withMessages(['targets' => $scope === 'kategori' ? 'Pilih minimal satu kategori.' : 'Pilih minimal satu barang.']);
        }
        if (count($targets) > self::MAX_TARGETS) {
            throw ValidationException::withMessages(['targets' => 'Paling banyak '.self::MAX_TARGETS.' sasaran per promo.']);
        }
        $buy = $type === 'beli_gratis' ? (int) $data['buy_qty'] : null;
        $free = $type === 'beli_gratis' ? (int) $data['free_qty'] : null;
        $hasTime = filled($data['time_from'] ?? null) && filled($data['time_to'] ?? null);

        $attributes = [
            'name' => $data['name'],
            'type' => $type,
            'value' => $type === 'beli_gratis' ? 0 : (int) $data['value'],
            'buy_qty' => $buy,
            'free_qty' => $free,
            'min_qty' => match ($type) {
                'beli_gratis' => $buy + $free,
                'grosir' => max(2, (int) ($data['min_qty'] ?? 2)),
                default => max(1, (int) ($data['min_qty'] ?? 1)),
            },
            'min_purchase' => (int) $data['min_purchase'],
            'scope' => $scope,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'time_from' => $hasTime ? $data['time_from'] : null,
            'time_to' => $hasTime ? $data['time_to'] : null,
            'is_active' => (bool) $data['is_active'],
        ];
        sort($attributes['days']);

        return DB::transaction(function () use ($promo, $attributes, $targets, $scope, $actor) {
            $existing = $promo !== null;
            $before = $existing ? $this->targetNames($promo) : [];
            if ($existing) {
                $promo->update($attributes);
            } else {
                $promo = Promo::create($attributes + ['created_by' => $actor->id]);
            }
            $promo->targets()->delete();
            foreach ($targets as $id) {
                $promo->targets()->create($scope === 'kategori' ? ['category_id' => $id] : ['product_id' => $id]);
            }
            $promo->unsetRelation('targets');
            $after = $this->targetNames($promo);
            if ($existing && $before !== $after) {
                AuditLog::record('promo.updated', 'promo', "Sasaran promo {$promo->name} diubah",
                    ['type' => 'promo', 'id' => $promo->id, 'label' => $promo->name], [],
                    action: 'diubah', old: ['targets' => implode(', ', $before) ?: 'Semua barang'], new: ['targets' => implode(', ', $after) ?: 'Semua barang']);
            }
            app(PromoEngine::class)->flush();

            return $promo->fresh();
        });
    }

    /**
     * @param  array{code: string, name: string, type: string, value: int, max_discount?: ?int, min_purchase: int, quota?: ?int, per_member?: ?int,
     *               starts_on: string, ends_on: string, is_active: bool}  $data
     */
    public function saveCoupon(?Coupon $coupon, array $data, User $actor): Coupon
    {
        $code = Coupon::normalize($data['code']);
        $taken = Coupon::where('code', $code)->when($coupon, fn ($q) => $q->whereKeyNot($coupon->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "Kode {$code} sudah dipakai kupon lain."]);
        }
        $quota = filled($data['quota'] ?? null) ? (int) $data['quota'] : null;
        if ($coupon && $quota !== null && $quota < $coupon->usedCount()) {
            throw ValidationException::withMessages(['quota' => "Kuota tidak boleh di bawah pemakaian ({$coupon->usedCount()}×)."]);
        }

        $attributes = [
            'code' => $code,
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => (int) $data['value'],
            'max_discount' => $data['type'] === 'persen' && filled($data['max_discount'] ?? null) ? (int) $data['max_discount'] : null,
            'min_purchase' => (int) $data['min_purchase'],
            'quota' => $quota,
            'per_member' => filled($data['per_member'] ?? null) ? (int) $data['per_member'] : null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'is_active' => (bool) $data['is_active'],
        ];

        return DB::transaction(function () use ($coupon, $attributes, $actor) {
            if ($coupon) {
                $coupon->update($attributes);

                return $coupon->fresh();
            }

            return Coupon::create($attributes + ['created_by' => $actor->id]);
        });
    }

    public function toggle(Promo|Coupon $model, bool $active): void
    {
        $model->update(['is_active' => $active]);
        app(PromoEngine::class)->flush();
    }

    /** Hapus promo/kupon yang belum pernah dipakai; yang sudah dipakai cukup dinonaktifkan. */
    public function delete(Promo|Coupon $model): void
    {
        $used = $model instanceof Promo ? $model->discounts()->exists() : ($model->usedCount() > 0 || $model->redemptions()->exists());
        if ($used) {
            throw ValidationException::withMessages(['delete' => 'Sudah dipakai di transaksi — nonaktifkan saja agar laporan diskon tetap utuh.']);
        }
        DB::transaction(fn () => $model->delete());
        app(PromoEngine::class)->flush();
    }

    /** @return list<string> */
    private function targetNames(Promo $promo): array
    {
        return $promo->targets()->with('category:id,name', 'product:id,name')->get()
            ->map(fn ($t) => $t->category?->name ?? $t->product?->name)->filter()->sort()->values()->all();
    }
}
