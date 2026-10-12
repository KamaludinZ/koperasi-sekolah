<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Riwayat penjualan contoh ±90 hari terakhir sampai jam sekarang (hanya untuk
 * pengembangan/demo), diproses bergiliran oleh beberapa kasir contoh.
 * Tidak mengubah stok barang — stok contoh dianggap kondisi saat ini.
 * Dilewati bila tabel transaksi sudah berisi.
 */
class TransactionSeeder extends Seeder
{
    public function run(int $days = 90): void
    {
        $products = Product::active()->get();
        if ($products->isEmpty() || Transaction::exists()) {
            return;
        }

        $cashiers = $this->cashiers();

        mt_srand(20261012);
        DB::transaction(function () use ($products, $days, $cashiers) {
            for ($d = $days; $d >= 0; $d--) {
                $date = now()->startOfDay()->subDays($d);
                if ($date->isSunday()) {
                    continue;
                }

                $times = collect(range(1, mt_rand(2, 7)))
                    ->map(fn () => $date->copy()->setTime(mt_rand(7, 14), mt_rand(0, 59), mt_rand(0, 59)))
                    ->reject(fn ($at) => $at->isFuture()) // hari ini: hanya sampai jam sekarang
                    ->sort()->values();

                foreach ($times as $seq => $at) {
                    $lines = $products->random(mt_rand(1, min(4, $products->count())))
                        ->mapWithKeys(fn ($p) => [$p->id => mt_rand(1, 3)])->all();

                    Transaction::factory()
                        ->state(['invoice_number' => 'INV-'.$at->format('Ymd').'-'.str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT)])
                        ->state(['payment_method' => mt_rand(1, 5) === 1 ? 'qris' : 'tunai'])
                        ->state(['user_id' => $cashiers[mt_rand(0, count($cashiers) - 1)]])
                        ->at($at->toDateTimeString())
                        ->withItems($lines)
                        ->create();
                }
            }
        });
        mt_srand();
    }

    /**
     * Akun kasir contoh (password acak; peran & login diatur di fitur Login & Hak Akses).
     *
     * @return list<int>
     */
    private function cashiers(): array
    {
        return collect(['Bu Rina' => 'rina', 'Pak Dedi' => 'dedi'])
            ->map(fn ($slug, $name) => User::firstOrCreate(
                ['email' => "kasir.{$slug}@koperasi.test"],
                ['name' => $name, 'password' => Hash::make(Str::random(32))],
            )->id)
            ->values()
            ->all();
    }
}
