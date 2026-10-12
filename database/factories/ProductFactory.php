<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $price = fake()->numberBetween(5, 200) * 500;

        return [
            'code' => strtoupper(fake()->unique()->bothify('BRG###??')),
            'name' => ucfirst(fake()->words(2, true)),
            'category_id' => Category::factory(),
            'price' => $price,
            'cost_price' => (int) round($price * 0.8),
            'stock' => fake()->numberBetween(0, 100),
            'min_stock' => fake()->numberBetween(1, 20),
            'unit' => 'pcs',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function lowStock(): static
    {
        return $this->state(['stock' => 2, 'min_stock' => 10]);
    }
}
