<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mutasi setoran mandiri (saldo 0 → nominal). Untuk riwayat yang saldonya
 * berantai, pakai layanan tabungan.
 *
 * @extends Factory<SavingsTransaction>
 */
class SavingsTransactionFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomElement([2000, 5000, 10000, 20000]);

        return [
            'member_id' => Member::factory(),
            'type' => SavingsTransaction::TYPE_DEPOSIT,
            'amount' => $amount,
            'balance_before' => 0,
            'balance_after' => $amount,
            'note' => 'Setoran tabungan',
        ];
    }
}
