<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\TransactionDiscount;
use App\Support\CouponValidator;
use Illuminate\Validation\ValidationException;

/**
 * Kupon/voucher dari database: periksa kode (CouponValidator memberi umpan balik: tidak
 * dikenal, nonaktif, belum berlaku, kedaluwarsa, kuota habis, kurang minimal belanja),
 * batas pemakaian per anggota bila anggotanya diketahui, dan hitung potongannya.
 * redeem() dipakai CheckoutService di dalam transaksi database dengan kunci baris kupon
 * agar kuota tidak terlampaui saat dua kasir memakai kupon bersamaan.
 */
class CouponService
{
    /**
     * @return array{ok: bool, reason: string, message: string, coupon: ?array, amount: int}
     */
    public function check(string $code, int $base, ?int $memberId = null, bool $lock = false): array
    {
        $normalized = Coupon::normalize($code);
        $model = $normalized === '' ? null : Coupon::where('code', $normalized)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $result = CouponValidator::check($code, $base, $model ? [self::toArray($model)] : []);

        if ($result['ok'] && $memberId && $model->per_member) {
            $used = TransactionDiscount::where('coupon_id', $model->id)
                ->whereHas('transaction', fn ($q) => $q->where('member_id', $memberId))->count();
            if ($used >= $model->per_member) {
                return ['ok' => false, 'reason' => 'per_member', 'message' => "Anggota ini sudah memakai kupon {$model->code} ({$used}×, batas {$model->per_member}×).",
                    'coupon' => $result['coupon'], 'amount' => 0];
            }
        }

        return $result;
    }

    /**
     * Pakai kupon pada transaksi yang sedang dibuat (panggil di dalam DB::transaction).
     *
     * @return array{coupon_id: int, code: string, amount: int}|null
     *
     * @throws ValidationException (kunci `coupon`) bila kupon tidak bisa dipakai
     */
    public function redeem(?string $code, int $base, ?int $memberId = null): ?array
    {
        if (! filled($code)) {
            return null;
        }
        $result = $this->check($code, $base, $memberId, lock: true);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['coupon' => $result['message']]);
        }

        return ['coupon_id' => $result['coupon']['id'], 'code' => $result['coupon']['code'], 'amount' => $result['amount']];
    }

    /** Bentuk array kupon (sama dengan data tahap frontend), dengan jumlah pemakaian & status. */
    public static function toArray(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type,
            'value' => (int) round((float) $c->value),
            'max_discount' => $c->max_discount !== null ? (int) round((float) $c->max_discount) : null,
            'min_purchase' => (int) round((float) $c->min_purchase),
            'quota' => $c->quota,
            'used' => $c->usedCount(),
            'per_member' => $c->per_member,
            'starts_at' => $c->starts_on->toDateString(),
            'ends_at' => $c->ends_on->toDateString(),
            'is_active' => $c->is_active,
            'status' => $c->status,
        ];
    }
}
