<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

        $cashiers = self::cashiers();
        $members = Member::active()->pluck('id')->all(); // ±1 dari 3 pembeli adalah anggota (bila ada)

        mt_srand(20261012);
        DB::transaction(function () use ($products, $days, $cashiers, $members) {
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
                        ->state(['member_id' => $members && mt_rand(1, 3) === 1 ? $members[mt_rand(0, count($members) - 1)] : null])
                        ->at($at->toDateTimeString())
                        ->withItems($lines)
                        ->create();
                }
            }
        });
        mt_srand();
    }

    /**
     * Petugas yang melayani transaksi contoh: akun demo kasir & admin (lihat UserSeeder).
     *
     * @return list<int>
     */
    public static function cashiers(): array
    {
        app(UserSeeder::class)->run();

        return User::whereIn('email', ['kasir@koperasi.test', 'admin@koperasi.test'])->orderBy('id')->pluck('id')->all();
    }
}
