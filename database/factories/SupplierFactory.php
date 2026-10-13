<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'CV '.fake()->unique()->company(),
            'contact' => fake()->name(),
            'phone' => '0812'.fake()->numerify('########'),
            'address' => fake()->address(),
            'terms_days' => fake()->randomElement([0, 7, 14, 30]),
            'is_active' => true,
        ];
    }

    public function cash(): static
    {
        return $this->state(['terms_days' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
