<?php

namespace Database\Factories;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'pengumuman',
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(12),
            'target' => 'semua',
        ];
    }

    public function stock(): static
    {
        return $this->state(['type' => 'stok', 'target' => 'pengurus']);
    }
}
