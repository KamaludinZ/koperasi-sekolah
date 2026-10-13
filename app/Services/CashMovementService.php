<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Setoran & penarikan kas laci (di luar penjualan) pada shift yang sedang terbuka, atomik
 * dan tercatat di audit (kategori `kas`). Penarikan tidak boleh melebihi kas seharusnya
 * di laci saat itu. Juga menyediakan daftar & rekap transaksi kas per hari.
 */
class CashMovementService
{
    public const MIN_AMOUNT = 500;

    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(CashMovement::TYPES))],
            'category' => ['required', Rule::in(array_keys(CashMovement::CATEGORIES))],
            'amount' => ['required', 'integer', 'min:'.self::MIN_AMOUNT, 'max:'.ShiftService::MAX_CASH],
            'reason' => ['required', 'string', 'min:3', 'max:150'],
        ];
    }

    public static function messages(): array
    {
        return [
            'type.in' => 'Pilih uang masuk atau uang keluar laci.',
            'category.in' => 'Kategori kas tidak dikenal.',
            'amount.required' => 'Isi jumlah uang.',
            'amount.min' => 'Jumlah minimal Rp500.',
            'amount.max' => 'Jumlah terlalu besar (maks. Rp50.000.000).',
            'reason.required' => 'Tulis alasannya agar bisa dipertanggungjawabkan.',
            'reason.min' => 'Alasan terlalu singkat.',
        ];
    }

    /** @throws ValidationException bila tidak ada shift terbuka atau uang laci tidak cukup */
    public function record(string $type, string $category, int $amount, string $reason, ?User $user): CashMovement
    {
        $reason = trim($reason);

        $movement = DB::transaction(function () use ($type, $category, $amount, $reason, $user) {
            // Kunci shift terbuka agar penarikan bersamaan tidak membuat kas laci minus.
            $shift = CashShift::open()->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['amount' => 'Buka shift dulu sebelum mencatat uang masuk/keluar laci.']);
            }
            if ($type === CashMovement::TYPE_OUT && $amount > ($available = $shift->expectedCash())) {
                throw ValidationException::withMessages(['amount' => 'Uang di laci tidak cukup (seharusnya Rp'.number_format($available, 0, ',', '.').').']);
            }

            return $shift->movements()->create([
                'type' => $type, 'category' => $category, 'amount' => $amount, 'reason' => $reason, 'user_id' => $user?->id,
            ]);
        });

        $isIn = $type === CashMovement::TYPE_IN;
        AuditLog::record($isIn ? 'cash.in' : 'cash.out', 'kas',
            ($isIn ? 'Uang masuk laci' : 'Uang keluar laci').' Rp'.number_format($amount, 0, ',', '.').': '.$reason,
            ['type' => 'cash_shift', 'id' => $movement->cash_shift_id, 'label' => $movement->shift->name.' '.$movement->shift->opened_at->format('d/m/Y')],
            ['category' => CashMovement::CATEGORIES[$category], 'reason' => $reason], $amount);

        return $movement->load('user:id,name', 'shift');
    }

    /**
     * Transaksi kas satu hari (terbaru dulu) + rekapnya (CashMovement::dailyTotals).
     *
     * @return array{data: list<array>, totals: array}
     */
    public function forDate(Carbon|string $date): array
    {
        return [
            'data' => CashMovement::onDate($date)->with('user:id,name', 'shift:id,name')->latest('created_at')->latest('id')->get()
                ->map(fn (CashMovement $m) => self::toArray($m))->all(),
            'totals' => CashMovement::dailyTotals($date),
        ];
    }

    public static function toArray(CashMovement $m): array
    {
        return [
            'id' => $m->id,
            'shift_id' => $m->cash_shift_id,
            'shift' => $m->shift?->name,
            'type' => $m->type,
            'category' => $m->category,
            'category_label' => $m->category_label,
            'amount' => (int) round((float) $m->amount),
            'reason' => $m->reason,
            'cashier' => $m->user?->name,
            'created_at' => $m->created_at->toDateTimeString(),
        ];
    }
}
