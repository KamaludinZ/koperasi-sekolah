<?php

namespace Database\Factories;

use App\Models\CashMovement;
use App\Models\CashShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_shift_id' => CashShift::factory(),
            'type' => CashMovement::TYPE_IN,
            'category' => 'kembalian',
            'amount' => 50000,
            'reason' => 'Tambah uang kembalian',
        ];
    }

    public function out(int $amount = 20000, string $reason = 'Setor ke bendahara'): static
    {
        return $this->state(['type' => CashMovement::TYPE_OUT, 'category' => 'bendahara', 'amount' => $amount, 'reason' => $reason]);
    }
}
