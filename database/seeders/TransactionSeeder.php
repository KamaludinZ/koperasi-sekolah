<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat penjualan contoh ±90 hari terakhir (hanya untuk pengembangan/demo).
 * Tidak mengubah stok barang — stok contoh dianggap kondisi saat ini.
 */
class TransactionSeeder extends Seeder
{
    public function run(int $days = 90): void
    {
        $products = Product::active()->get();
        if ($products->isEmpty() || Transaction::exists()) {
            return;
        }

        mt_srand(20261012);
        DB::transaction(function () use ($products, $days) {
            for ($d = $days; $d >= 1; $d--) {
                $date = now()->startOfDay()->subDays($d);
                if ($date->isSunday()) {
                    continue;
                }

                $times = collect(range(1, mt_rand(2, 7)))
                    ->map(fn () => $date->copy()->setTime(mt_rand(7, 14), mt_rand(0, 59), mt_rand(0, 59)))
                    ->sort()->values();

                foreach ($times as $seq => $at) {
                    $lines = $products->random(mt_rand(1, min(4, $products->count())))
                        ->mapWithKeys(fn ($p) => [$p->id => mt_rand(1, 3)])->all();

                    Transaction::factory()
                        ->state(['invoice_number' => 'INV-'.$at->format('Ymd').'-'.str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT)])
                        ->state(['payment_method' => mt_rand(1, 5) === 1 ? 'qris' : 'tunai'])
                        ->at($at->toDateTimeString())
                        ->withItems($lines)
                        ->create();
                }
            }
        });
        mt_srand();
    }
}
