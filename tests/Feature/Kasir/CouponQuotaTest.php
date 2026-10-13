<?php

namespace Tests\Feature\Kasir;

use App\Models\Coupon;
use App\Services\CheckoutService;
use App\Services\CouponService;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CouponQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function buy(string $code)
    {
        return app(CheckoutService::class)->checkout([$this->pid('SRG001') => 1], 'qris', null, null, null, null, $code);
    }

    public function test_each_redemption_reduces_remaining_quota(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'TIGA', 'quota' => 3, 'value' => 1000]);

        $this->buy('TIGA');
        $this->buy('tiga');
        $coupon->refresh();
        $this->assertSame([2, 1, 'aktif'], [$coupon->used_count, $coupon->remaining(), $coupon->status]);

        $this->buy('TIGA');
        $this->assertSame([3, 0, 'habis'], [$coupon->fresh()->used_count, $coupon->fresh()->remaining(), $coupon->fresh()->status]);

        try {
            $this->buy('TIGA');
            $this->fail('Kuota habis tidak ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Kuota kupon TIGA sudah habis', $e->errors()['coupon'][0]);
        }
        $this->assertSame(3, $coupon->fresh()->used_count);
        $this->assertSame(3, $coupon->redemptions()->count());
    }

    public function test_unlimited_coupon_counts_without_limit(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'BEBAS', 'quota' => null, 'value' => 1000]);
        foreach (range(1, 4) as $i) {
            $this->buy('BEBAS');
        }
        $this->assertSame([4, null], [$coupon->fresh()->used_count, $coupon->fresh()->remaining()]);
    }

    public function test_failed_checkout_does_not_consume_quota(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'SATU', 'quota' => 1, 'value' => 1000]);

        try {
            app(CheckoutService::class)->checkout([$this->pid('SRG001') => 1], 'tunai', 100, null, null, null, 'SATU'); // uang kurang
        } catch (ValidationException) {
        }
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_release_and_recount(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'DUA', 'quota' => 2, 'value' => 1000]);
        $this->buy('DUA');
        $this->buy('DUA');

        app(CouponService::class)->release($coupon->id);
        $this->assertSame([1, 'aktif'], [$coupon->fresh()->used_count, $coupon->fresh()->status]);
        app(CouponService::class)->release($coupon->id);
        app(CouponService::class)->release($coupon->id); // tidak di bawah 0
        $this->assertSame(0, $coupon->fresh()->used_count);

        Artisan::call('kupon:hitung-ulang');
        $this->assertStringContainsString('DUA: 0 → 2', Artisan::output());
        $this->assertSame(2, $coupon->fresh()->used_count);
    }

    public function test_api_exposes_remaining_quota(): void
    {
        Coupon::factory()->create(['code' => 'SISA', 'quota' => 5, 'value' => 1000]);
        $this->buy('SISA');

        $this->postJson('/kasir/kupon/cek', ['code' => 'SISA', 'subtotal' => 20000])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(4, app(CouponService::class)->check('SISA', 20000)['coupon']['remaining']);
    }

    public function test_postgres_rejects_counter_beyond_quota(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint CHECK hanya di PostgreSQL.');
        }
        $coupon = Coupon::factory()->create(['quota' => 1]);

        $this->expectException(QueryException::class);
        DB::table('coupons')->where('id', $coupon->id)->update(['used_count' => 2]);
    }
}
