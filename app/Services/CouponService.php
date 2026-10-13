<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\TransactionDiscount;
use Illuminate\Support\Facades\DB;
use App\Support\CouponValidator;
use Illuminate\Validation\ValidationException;

/**
 * Kupon/voucher dari database: periksa kode (CouponValidator memberi umpan balik: tidak
 * dikenal, nonaktif, belum berlaku, kedaluwarsa, kuota habis, kurang minimal belanja),
 * batas pemakaian per anggota bila anggotanya diketahui, dan hitung potongannya.
 * redeem() dipakai CheckoutService di dalam transaksi database: kunci baris kupon lalu
 * menaikkan used_count secara atomik (hanya bila masih ada sisa kuota), sehingga kuota tidak
 * terlampaui saat dua kasir memakai kupon bersamaan. release() mengembalikan kuota.
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
                ->whereHas('transaction', fn ($q) => $q->where('member_id', $memberId)->whereNull('voided_at'))->count();
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

        // Kurangi kuota secara atomik: hanya berhasil bila masih ada sisa kuota.
        $taken = DB::table('coupons')->where('id', $result['coupon']['id'])
            ->where(fn ($q) => $q->whereNull('quota')->orWhereColumn('used_count', '<', 'quota'))
            ->increment('used_count');
        if ($taken === 0) {
            throw ValidationException::withMessages(['coupon' => "Kuota kupon {$result['coupon']['code']} sudah habis."]);
        }

        return ['coupon_id' => $result['coupon']['id'], 'code' => $result['coupon']['code'], 'amount' => $result['amount']];
    }

    /**
     * Kembalikan satu kuota kupon (mis. transaksi dibatalkan/diretur); tidak pernah di bawah 0.
     * Panggil di dalam transaksi database yang membatalkan pemakaiannya.
     */
    public function release(int $couponId): void
    {
        DB::table('coupons')->where('id', $couponId)->where('used_count', '>', 0)->decrement('used_count');
    }

    /**
     * Selaraskan penghitung pemakaian dengan riwayat transaction_discounts (perintah kupon:hitung-ulang).
     *
     * @return array<string, array{from: int, to: int}> kupon yang penghitungnya berubah
     */
    public function recount(): array
    {
        $changed = [];
        foreach (Coupon::withCount(['redemptions' => fn ($q) => $q->whereHas('transaction', fn ($t) => $t->whereNull('voided_at'))])->get() as $coupon) {
            if ($coupon->used_count !== $coupon->redemptions_count) {
                $changed[$coupon->code] = ['from' => $coupon->used_count, 'to' => $coupon->redemptions_count];
                $coupon->forceFill(['used_count' => $coupon->redemptions_count])->saveQuietly();
            }
        }

        return $changed;
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
            'remaining' => $c->remaining(),
            'per_member' => $c->per_member,
            'starts_at' => $c->starts_on->toDateString(),
            'ends_at' => $c->ends_on->toDateString(),
            'is_active' => $c->is_active,
            'status' => $c->status,
        ];
    }
}
