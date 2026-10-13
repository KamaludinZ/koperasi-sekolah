<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Memeriksa kode kupon/voucher terhadap daftar kupon (lihat MockData::coupons) dan subtotal
 * belanja, lalu memberi umpan balik yang jelas untuk kasir: tidak dikenal, belum berlaku,
 * kedaluwarsa, kuota habis, nonaktif, kurang minimal belanja, atau berhasil + besar potongan.
 */
class CouponValidator
{
    /**
     * @param  list<array>  $coupons
     * @return array{ok: bool, reason: string, message: string, coupon: ?array, amount: int}
     */
    public static function check(string $code, int $subtotal, array $coupons): array
    {
        $code = self::normalize($code);
        $fail = fn (string $reason, string $message, ?array $coupon = null) => ['ok' => false, 'reason' => $reason, 'message' => $message, 'coupon' => $coupon, 'amount' => 0];
        $rp = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');

        if ($code === '') {
            return $fail('empty', 'Masukkan kode kupon.');
        }

        $coupon = collect($coupons)->first(fn (array $c) => self::normalize($c['code']) === $code);

        return match (true) {
            $coupon === null => $fail('not_found', "Kode {$code} tidak dikenal. Periksa ejaannya."),
            $coupon['status'] === 'nonaktif' => $fail('inactive', "Kupon {$code} sedang dinonaktifkan.", $coupon),
            $coupon['status'] === 'terjadwal' => $fail('not_started', "Kupon {$code} baru berlaku mulai ".Carbon::parse($coupon['starts_at'])->translatedFormat('d F Y').'.', $coupon),
            $coupon['status'] === 'berakhir' => $fail('expired', "Kupon {$code} sudah kedaluwarsa sejak ".Carbon::parse($coupon['ends_at'])->translatedFormat('d F Y').'.', $coupon),
            $coupon['status'] === 'habis' => $fail('quota', "Kuota kupon {$code} sudah habis ({$coupon['used']}/{$coupon['quota']}).", $coupon),
            $subtotal < $coupon['min_purchase'] => $fail('min_purchase', 'Minimal belanja '.$rp($coupon['min_purchase']).' — kurang '.$rp($coupon['min_purchase'] - $subtotal).'.', $coupon),
            default => ['ok' => true, 'reason' => 'ok', 'coupon' => $coupon, 'amount' => $amount = self::amount($coupon, $subtotal),
                'message' => "{$coupon['name']}: potongan {$rp($amount)}".($coupon['type'] === 'persen' ? " ({$coupon['value']}%".($coupon['max_discount'] ? ', maks. '.$rp($coupon['max_discount']) : '').')' : '').'.'],
        };
    }

    /** Potongan rupiah kupon: persen (dengan batas maks.) atau nominal, tidak melebihi subtotal. */
    public static function amount(array $coupon, int $subtotal): int
    {
        $amount = $coupon['type'] === 'persen' ? (int) round($subtotal * $coupon['value'] / 100) : (int) $coupon['value'];
        if ($coupon['max_discount']) {
            $amount = min($amount, (int) $coupon['max_discount']);
        }

        return max(0, min($amount, $subtotal));
    }

    /** Kode tanpa spasi, huruf besar. */
    public static function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $code));
    }
}
