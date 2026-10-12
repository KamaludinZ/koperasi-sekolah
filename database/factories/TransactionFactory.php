<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.fake()->unique()->numerify('########-####'),
            'subtotal' => 0,
            'tax' => 0,
            'discount' => 0,
            'total' => 0,
            'paid' => 0,
            'change' => 0,
            'payment_method' => 'tunai',
            'item_count' => 0,
        ];
    }

    /**
     * Isi item dari barang yang ada, lalu hitung ulang total agar konsisten.
     *
     * @param  array<int, int>  $lines  [productId => qty]; kosong = 1–3 barang acak
     */
    public function withItems(array $lines = []): static
    {
        return $this->afterCreating(function (Transaction $tx) use ($lines) {
            if ($lines === []) {
                $products = Product::inRandomOrder()->limit(fake()->numberBetween(1, 3))->get();
                $lines = $products->mapWithKeys(fn ($p) => [$p->id => fake()->numberBetween(1, 3)])->all();
            }

            foreach (Product::whereKey(array_keys($lines))->get() as $p) {
                $qty = $lines[$p->id];
                $tx->items()->create([
                    'product_id' => $p->id,
                    'product_name' => $p->name,
                    'price' => $p->price,
                    'cost_price' => $p->cost_price,
                    'quantity' => $qty,
                    'subtotal' => $p->price * $qty,
                ]);
            }

            $total = (int) $tx->items()->sum('subtotal');
            $paid = $tx->payment_method === 'tunai' ? (int) (ceil($total / 5000) * 5000) : $total;
            $tx->update([
                'subtotal' => $total,
                'total' => $total,
                'paid' => $paid,
                'change' => $paid - $total,
                'item_count' => (int) $tx->items()->sum('quantity'),
            ]);
        });
    }

    public function at(string $datetime): static
    {
        return $this->state(['created_at' => $datetime, 'updated_at' => $datetime]);
    }

    public function qris(): static
    {
        return $this->state(['payment_method' => 'qris']);
    }
}
