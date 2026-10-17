<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_code' => 'S-'.fake()->unique()->numerify('####-###'),
            'name' => fake()->name(),
            'type' => 'siswa',
            'class' => fake()->randomElement(['7', '8', '9']).fake()->randomElement(['A', 'B', 'C']),
            'phone' => fake()->optional()->numerify('0812-####-####'),
            'is_active' => true,
            'joined_at' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'portal_password_changed_at' => now(), // kata sandi portal (bila diberi) dianggap sudah milik anggota
        ];
    }

    public function teacher(): static
    {
        return $this->state(fn () => [
            'member_code' => 'G-'.fake()->unique()->numerify('####-###'),
            'type' => 'guru',
            'class' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Saldo awal langsung (tanpa mutasi) — hanya untuk test. */
    public function withBalance(int $balance): static
    {
        return $this->afterCreating(fn (Member $m) => $m->forceFill(['balance' => $balance])->save());
    }
}
