<?php

namespace Tests\Feature\Database;

use App\Models\Coupon;
use App\Models\Promo;
use App\Models\Transaction;
use App\Models\TransactionDiscount;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PromoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromoSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, PromoSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_seeder_creates_promos_with_targets_and_coupons(): void
    {
        $this->assertSame(7, Promo::count());
        $this->assertSame(5, Coupon::count());

        $stationery = Promo::firstWhere('name', 'Diskon Alat Tulis Awal Semester');
        $this->assertSame(['Alat Tulis'], $stationery->categories->pluck('name')->all());
        $this->assertSame(['Biskuit Kelapa'], Promo::firstWhere('type', 'beli_gratis')->products->pluck('name')->all());
        $this->assertSame(0, Promo::firstWhere('scope', 'semua')->targets()->count());

        $this->seed(PromoSeeder::class); // tidak menggandakan
        $this->assertSame(7, Promo::count());
    }

    public function test_promo_status_from_period_and_switch(): void
    {
        $status = Promo::all()->pluck('status', 'name');
        $this->assertSame('aktif', $status['Beli 2 Gratis 1 Biskuit Kelapa']);
        $this->assertSame('terjadwal', $status['Promo Seragam Tahun Ajaran Baru']);
        $this->assertSame('berakhir', $status['Diskon Hari Guru']);
        $this->assertSame('nonaktif', $status['Potongan Roti Coklat Sore']);
        $this->assertSame(4, Promo::runningOn(today())->count());
    }

    public function test_coupon_code_normalized_and_quota_status(): void
    {
        $coupon = Coupon::factory()->create(['code' => ' hemat 10 ', 'quota' => 1]);
        $this->assertSame('HEMAT10', $coupon->code);
        $this->assertSame('aktif', $coupon->status);

        $tx = Transaction::factory()->create(['total' => 15000, 'discount' => 5000]);
        TransactionDiscount::create(['transaction_id' => $tx->id, 'source' => 'kupon', 'coupon_id' => $coupon->id, 'label' => $coupon->code, 'amount' => 5000]);
        $coupon->increment('used_count');

        $this->assertSame('habis', $coupon->fresh()->status);
        $this->assertSame([1, 0], [$coupon->fresh()->usedCount(), $coupon->fresh()->remaining()]);
        $this->assertSame(['kupon'], $tx->discounts->pluck('source')->all());

        $this->expectException(QueryException::class);
        Coupon::factory()->create(['code' => 'hemat10']); // kode unik
    }

    public function test_used_coupon_cannot_be_deleted(): void
    {
        $coupon = Coupon::firstWhere('code', 'MPLS2026');
        $tx = Transaction::factory()->create(['total' => 15000, 'discount' => 5000]);
        TransactionDiscount::create(['transaction_id' => $tx->id, 'source' => 'kupon', 'coupon_id' => $coupon->id, 'label' => 'MPLS2026', 'amount' => 5000]);

        $this->expectException(QueryException::class);
        $coupon->delete();
    }

    public function test_postgres_constraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint CHECK hanya di PostgreSQL.');
        }
        $tx = Transaction::factory()->create(['total' => 10000]);
        $promo = Promo::first();

        foreach ([
            fn () => Promo::factory()->create(['type' => 'persen', 'value' => 120]),
            fn () => Promo::factory()->create(['type' => 'beli_gratis', 'value' => 0, 'buy_qty' => null]),
            fn () => Promo::factory()->create(['ends_on' => today()->subDays(10)]),
            fn () => Promo::factory()->create(['time_from' => '13:00', 'time_to' => null]),
            fn () => $promo->targets()->create(['category_id' => null, 'product_id' => null]),
            fn () => Coupon::factory()->create(['type' => 'persen', 'value' => 0]),
            fn () => TransactionDiscount::create(['transaction_id' => $tx->id, 'source' => 'kupon', 'label' => 'X', 'amount' => 1000]),
            fn () => TransactionDiscount::create(['transaction_id' => $tx->id, 'source' => 'manual', 'label' => 'X', 'amount' => 0]),
        ] as $i => $bad) {
            try {
                DB::transaction($bad);
                $this->fail("Constraint #{$i} tidak menolak data salah.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }
}
