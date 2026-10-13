<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pengajuan retur (bawaan: retur 1 unit barang pertama, menunggu). State: batal(), approved(), rejected().
 * Baris barang dibuat otomatis dari transaksi (withItems di TransactionFactory).
 *
 * @extends Factory<TransactionReturn>
 */
class TransactionReturnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'RTR-'.fake()->unique()->numerify('########-####'),
            'kind' => 'retur',
            'transaction_id' => Transaction::factory()->withItems(),
            'status' => 'menunggu',
            'reason_key' => 'rusak',
            'reason' => TransactionReturn::RETURN_REASONS['rusak'],
            'restock' => false,
            'refund_method' => 'tunai',
            'amount' => 0,
            'quantity' => 1,
            'requested_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (TransactionReturn $r) {
            if ($r->items()->exists()) {
                return;
            }
            $items = $r->kind === 'batal' ? $r->transaction->items : $r->transaction->items->take(1);
            $amount = 0;
            $qty = 0;
            foreach ($items as $it) {
                $q = $r->kind === 'batal' ? $it->quantity : 1;
                $line = (int) $it->price * $q;
                $r->items()->create(['transaction_item_id' => $it->id, 'product_id' => $it->product_id, 'product_name' => $it->product_name,
                    'quantity' => $q, 'unit_price' => (int) $it->price, 'amount' => $line]);
                $amount += $line;
                $qty += $q;
            }
            $r->update(['amount' => $r->kind === 'batal' ? (int) $r->transaction->total : $amount, 'quantity' => max(1, $qty)]);
        });
    }

    public function batal(): static
    {
        return $this->state(fn () => [
            'number' => 'BTL-'.fake()->unique()->numerify('########-####'),
            'kind' => 'batal', 'reason_key' => 'salah_input', 'reason' => TransactionReturn::VOID_REASONS['salah_input'], 'restock' => true,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'disetujui', 'decided_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => 'ditolak', 'decided_at' => now(), 'decision_note' => 'Lewat batas waktu']);
    }
}
