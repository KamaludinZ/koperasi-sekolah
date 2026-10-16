<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Promo;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Promo & kupon contoh (sama dengan contoh di tahap frontend), tanggalnya relatif terhadap
 * hari ini. Hanya untuk pengembangan/demo; dilewati bila sudah ada promo atau kupon.
 */
class PromoSeeder extends Seeder
{
    public function run(): void
    {
        if (Promo::exists() || Coupon::exists()) {
            return;
        }

        $admin = User::where('email', 'admin@koperasi.test')->value('id');
        $t = today();
        $promos = [
            ['Diskon Alat Tulis Awal Semester', 'persen', 10, null, null, 1, 0, 'kategori', ['Alat Tulis'], -10, 20, [1, 2, 3, 4, 5, 6], null, null, true],
            ['Beli 2 Gratis 1 Biskuit Kelapa', 'beli_gratis', 0, 2, 1, 3, 0, 'barang', ['Biskuit Kelapa'], -3, 4, [1, 2, 3, 4, 5, 6], null, null, true],
            ['Happy Hour Minuman Pulang Sekolah', 'nominal', 500, null, null, 1, 0, 'kategori', ['Minuman'], -30, 60, [1, 2, 3, 4, 5], '13:00', '14:30', true],
            ['Grosir Buku Tulis (isi 10)', 'grosir', 3500, null, null, 10, 0, 'barang', ['Buku Tulis 38 Lembar'], -60, 120, [1, 2, 3, 4, 5, 6], null, null, true],
            ['Promo Seragam Tahun Ajaran Baru', 'persen', 15, null, null, 1, 50000, 'kategori', ['Seragam'], 5, 35, [1, 2, 3, 4, 5, 6], null, null, true],
            ['Diskon Hari Guru', 'persen', 20, null, null, 1, 10000, 'semua', [], -40, -38, [1, 2, 3, 4, 5, 6], null, null, true],
            ['Potongan Roti Coklat Sore', 'nominal', 1000, null, null, 1, 0, 'barang', ['Roti Coklat'], -7, 7, [1, 2, 3, 4, 5], '12:00', '14:30', false],
        ];
        $coupons = [
            ['MPLS2026', 'Voucher siswa baru (MPLS)', 'nominal', 5000, null, 20000, 200, 1, -14, 16],
            ['GURUHEBAT', 'Potongan khusus guru', 'persen', 10, 10000, 0, null, null, -45, 75],
            ['PRAMUKA25', 'Voucher lomba pramuka', 'nominal', 3000, null, 10000, 50, 1, -20, 10],
            ['HARIBATIK', 'Diskon Hari Batik', 'persen', 15, 15000, 25000, 100, 1, -14, -11],
            ['OSIS100', 'Hadiah pengurus OSIS', 'nominal', 10000, null, 30000, 30, 1, 3, 33],
        ];

        AuditLog::quietly(fn () => DB::transaction(function () use ($promos, $coupons, $admin, $t) {
            foreach ($promos as [$name, $type, $value, $buy, $free, $minQty, $minPurchase, $scope, $targets, $start, $end, $days, $from, $to, $active]) {
                $promo = Promo::create([
                    'name' => $name, 'type' => $type, 'value' => $value, 'buy_qty' => $buy, 'free_qty' => $free,
                    'min_qty' => $minQty, 'min_purchase' => $minPurchase, 'scope' => $scope,
                    'starts_on' => $t->copy()->addDays($start), 'ends_on' => $t->copy()->addDays($end),
                    'days' => $days, 'time_from' => $from, 'time_to' => $to, 'is_active' => $active, 'created_by' => $admin,
                ]);
                foreach ($targets as $target) {
                    $promo->targets()->create($scope === 'kategori'
                        ? ['category_id' => Category::where('name', $target)->value('id')]
                        : ['product_id' => Product::where('name', $target)->value('id')]);
                }
            }
            foreach ($coupons as [$code, $name, $type, $value, $max, $minPurchase, $quota, $perMember, $start, $end]) {
                Coupon::create([
                    'code' => $code, 'name' => $name, 'type' => $type, 'value' => $value, 'max_discount' => $max,
                    'min_purchase' => $minPurchase, 'quota' => $quota, 'per_member' => $perMember,
                    'starts_on' => $t->copy()->addDays($start), 'ends_on' => $t->copy()->addDays($end), 'is_active' => true, 'created_by' => $admin,
                ]);
            }
        }));
    }
}
