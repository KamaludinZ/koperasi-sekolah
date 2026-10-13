<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => fn () => PurchaseOrder::nextNumber(),
            'supplier_id' => Supplier::factory(),
            'status' => 'dipesan',
            'ordered_at' => today(),
            'expected_at' => today()->addDays(3),
            'sent_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draf', 'sent_at' => null]);
    }
}
