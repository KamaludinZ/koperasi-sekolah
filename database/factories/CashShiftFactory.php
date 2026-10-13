<?php

namespace Database\Factories;

use App\Models\CashShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Shift terbuka hari ini dengan modal awal. Pakai closed() untuk shift yang sudah ditutup
 * (snapshot kas seharusnya = modal awal, uang dihitung sesuai selisih).
 *
 * @extends Factory<CashShift>
 */
class CashShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Shift pagi',
            'status' => CashShift::STATUS_OPEN,
            'opened_at' => now()->subHours(2)->max(today()), // tetap di hari ini (aman dijalankan lewat tengah malam)
            'opening_cash' => 200000,
        ];
    }

    public function closed(int $difference = 0): static
    {
        return $this->state(fn (array $a) => [
            'status' => CashShift::STATUS_CLOSED,
            'closed_at' => now(),
            'expected_cash' => $a['opening_cash'],
            'counted_cash' => $a['opening_cash'] + $difference,
            'difference' => $difference,
        ]);
    }
}
