<?php

namespace Database\Factories;

use App\Models\Promo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Promo persen 10% untuk semua barang, berlaku sepekan di sekitar hari ini, Senin–Sabtu.
 *
 * @extends Factory<Promo>
 */
class PromoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Promo '.fake()->unique()->word(),
            'type' => 'persen',
            'value' => 10,
            'min_qty' => 1,
            'min_purchase' => 0,
            'scope' => 'semua',
            'starts_on' => today()->subDays(3),
            'ends_on' => today()->addDays(4),
            'days' => [1, 2, 3, 4, 5, 6],
            'is_active' => true,
        ];
    }
}
