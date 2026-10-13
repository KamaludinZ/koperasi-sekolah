<?php

namespace Database\Seeders;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat shift kasir & kas laci contoh untuk $days hari terakhir (hanya pengembangan/demo):
 * shift pagi & siang per hari buka, transaksi contoh pada jam shift ditautkan ke shift-nya,
 * beberapa setoran/penarikan kas, lalu shift ditutup dengan kas seharusnya dari data dan
 * selisih kecil sesekali. Shift hari ini yang jamnya belum selesai dibiarkan terbuka.
 * Dilewati bila sudah ada shift.
 */
class CashShiftSeeder extends Seeder
{
    private const PLANS = [
        ['name' => 'Shift pagi', 'open' => [6, 45], 'close' => [10, 30], 'opening' => 200000],
        ['name' => 'Shift siang', 'open' => [10, 30], 'close' => [15, 0], 'opening' => 150000],
    ];

    private const MOVES = [
        ['in', 'kembalian', 'Tambah uang kembalian', [2, 4], 25000],
        ['in', 'bendahara', 'Tukar pecahan dari bendahara', [1, 2], 50000],
        ['out', 'bendahara', 'Setor ke bendahara', [2, 6], 50000],
        ['out', 'operasional', 'Beli plastik & kantong', [1, 3], 5000],
        ['out', 'operasional', 'Bayar galon air', [4, 4], 5000],
    ];

    private const DIFFERENCES = [0, 0, 0, 0, -500, -1000, 1000, -2000];

    /** Uang kembalian yang selalu disisakan di laci saat penarikan contoh. */
    private const KEEP_CHANGE = 50000;

    public function run(int $days = 14): void
    {
        if (CashShift::exists()) {
            return;
        }

        $cashiers = TransactionSeeder::cashiers();
        if ($cashiers === []) {
            return;
        }

        mt_srand(20261013);
        DB::transaction(function () use ($days, $cashiers) {
            for ($d = $days - 1; $d >= 0; $d--) {
                $date = now()->startOfDay()->subDays($d);
                if ($date->isSunday()) {
                    continue;
                }

                foreach (self::PLANS as $i => $plan) {
                    $opened = $date->copy()->setTime(...$plan['open'])->addMinutes(mt_rand(0, 8));
                    if ($opened->isFuture()) {
                        break;
                    }
                    $closeAt = $date->copy()->setTime(...$plan['close'])->addMinutes(mt_rand(0, 12));
                    $stillOpen = $closeAt->isFuture();
                    $cashier = $cashiers[($i + $d) % count($cashiers)];

                    $shift = CashShift::forceCreate([
                        'name' => $plan['name'], 'status' => CashShift::STATUS_OPEN, 'user_id' => $cashier,
                        'opened_at' => $opened, 'opening_cash' => $plan['opening'],
                        'created_at' => $opened, 'updated_at' => $opened,
                    ]);

                    Transaction::whereNull('cash_shift_id')
                        ->whereBetween('created_at', [$opened, $stillOpen ? now() : $closeAt])
                        ->update(['cash_shift_id' => $shift->id]);

                    for ($n = mt_rand(0, 2); $n > 0; $n--) {
                        [$type, $category, $reason, [$min, $max], $unit] = self::MOVES[mt_rand(0, count(self::MOVES) - 1)];
                        $at = $opened->copy()->addMinutes(mt_rand(20, 200));
                        $amount = mt_rand($min, $max) * $unit;
                        if ($at->isFuture() || (! $stillOpen && $at->gt($closeAt)) || ($type === 'out' && $amount > $shift->expectedCash() - self::KEEP_CHANGE)) {
                            continue; // penarikan menyisakan uang kembalian di laci
                        }
                        CashMovement::forceCreate([
                            'cash_shift_id' => $shift->id, 'type' => $type, 'category' => $category,
                            'amount' => $amount, 'reason' => $reason,
                            'user_id' => $cashier, 'created_at' => $at,
                        ]);
                    }

                    if ($stillOpen) {
                        break; // shift berjalan hari ini: tidak ada shift berikutnya
                    }

                    $expected = $shift->expectedCash();
                    // Uang dihitung tidak pernah negatif (penjualan contoh acak bisa membuat laci tipis).
                    $difference = max(-$expected, self::DIFFERENCES[mt_rand(0, count(self::DIFFERENCES) - 1)]);
                    $shift->forceFill([
                        'status' => CashShift::STATUS_CLOSED, 'closed_at' => $closeAt, 'closed_by' => $cashier,
                        'expected_cash' => $expected, 'counted_cash' => $expected + $difference,
                        'difference' => $difference,
                        'close_note' => $difference < 0 ? 'Kemungkinan salah kembalian.' : ($difference > 0 ? 'Ada uang lebih, belum diketahui asalnya.' : null),
                        'updated_at' => $closeAt,
                    ])->save();
                }
            }
        });
        mt_srand();
    }
}
