<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Kupon potongan Rp5.000 tanpa minimal belanja, kuota 100, berlaku sebulan.
 *
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('KUPON##??')),
            'name' => 'Kupon '.fake()->word(),
            'type' => 'nominal',
            'value' => 5000,
            'min_purchase' => 0,
            'quota' => 100,
            'starts_on' => today()->subDays(7),
            'ends_on' => today()->addDays(23),
            'is_active' => true,
        ];
    }
}
