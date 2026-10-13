<?php

namespace App\Services;

use App\Models\Role;
use App\Models\TransactionDiscount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Diskon manual per transaksi (persen atau rupiah) dengan alasan, dibatasi aturan di
 * pengaturan `discount` (aktif, maks. %, maks. rupiah, alasan wajib). Batas berlaku untuk
 * kasir; admin boleh melewatinya (bertindak sebagai pemberi persetujuan). Potongan dihitung
 * dari dasar harga yang diberikan (subtotal setelah promo) dan tidak melebihi dasar itu.
 */
class ManualDiscountService
{
    public const TYPES = ['persen' => '%', 'nominal' => 'Rp'];

    public function __construct(private SettingsService $settings) {}

    public function rules(): array
    {
        return $this->settings->discount();
    }

    /** Rupiah potongan: persen dibulatkan ke rupiah; tidak negatif dan tidak melebihi $base. */
    public static function amount(string $type, int $value, int $base): int
    {
        $amount = $type === 'persen' ? (int) round($base * $value / 100) : $value;

        return max(0, min($amount, $base));
    }

    /** Boleh melewati batas diskon kasir. */
    public static function canExceedLimit(?User $user): bool
    {
        return (bool) $user?->hasRole(Role::ADMIN);
    }

    /**
     * Periksa & hitung diskon manual.
     *
     * @param  array{type?: string, value?: int|string|null, reason?: ?string}|null  $input
     * @return array{type: string, value: int, reason: ?string, amount: int, label: string}|null null bila tanpa diskon
     *
     * @throws ValidationException (kunci `discount`) bila tidak diizinkan atau melebihi batas
     */
    public function resolve(?array $input, int $base, ?User $user): ?array
    {
        if (! $input || (int) ($input['value'] ?? 0) <= 0) {
            return null;
        }
        $type = (string) ($input['type'] ?? '');
        $value = (int) $input['value'];
        $reason = filled($input['reason'] ?? null) ? trim((string) $input['reason']) : null;
        $rules = $this->rules();
        $fail = fn (string $m) => throw ValidationException::withMessages(['discount' => $m]);

        if (! array_key_exists($type, self::TYPES)) {
            $fail('Jenis diskon tidak dikenal.');
        }
        if ($type === 'persen' && $value > 100) {
            $fail('Diskon maksimal 100%.');
        }
        if ($reason !== null && mb_strlen($reason) > 100) {
            $fail('Alasan diskon maksimal 100 karakter.');
        }
        if ($type === 'nominal' && $value > $base) {
            $fail('Diskon tidak boleh melebihi subtotal belanja.');
        }

        $amount = self::amount($type, $value, $base);
        if (! self::canExceedLimit($user)) {
            if (! $rules['enabled']) {
                $fail('Diskon manual sedang dimatikan oleh admin.');
            }
            if ($rules['reason_required'] && $reason === null) {
                $fail('Tulis alasan diskon.');
            }
            if (($type === 'persen' && $value > $rules['max_percent']) || $amount > $rules['max_amount']) {
                $fail('Melebihi batas diskon kasir ('.$rules['max_percent'].'% · maks. Rp'.number_format($rules['max_amount'], 0, ',', '.').'). Minta persetujuan admin.');
            }
        }

        return [
            'type' => $type, 'value' => $value, 'reason' => $reason, 'amount' => $amount,
            'label' => 'Diskon manual'.($type === 'persen' ? " {$value}%" : ''),
        ];
    }

    /** Pemakaian diskon manual N hari terakhir: jumlah transaksi & total potongan. */
    public function usage(int $days = 7): array
    {
        $q = TransactionDiscount::where('source', 'manual')->where('created_at', '>=', today()->subDays($days - 1));

        return ['count' => (clone $q)->count(), 'amount' => (int) round((float) $q->sum('amount'))];
    }
}
