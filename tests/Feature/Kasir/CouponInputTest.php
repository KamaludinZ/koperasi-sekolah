<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Support\CouponValidator;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CouponInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed(ProductSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_validator_feedback_for_each_state(): void
    {
        $coupons = MockData::coupons();
        $check = fn (string $code, int $subtotal = 50000) => CouponValidator::check($code, $subtotal, $coupons);

        $this->assertSame('empty', $check('  ')['reason']);
        $this->assertSame('not_found', $check('ASAL')['reason']);
        $this->assertSame('not_started', $check('osis100')['reason']);
        $this->assertSame('expired', $check('HARIBATIK')['reason']);
        $this->assertSame('quota', $check('PRAMUKA25')['reason']);
        $this->assertSame('Kuota kupon PRAMUKA25 sudah habis (50/50).', $check('PRAMUKA25')['message']);
        $this->assertSame('Minimal belanja Rp20.000 — kurang Rp5.000.', $check('MPLS2026', 15000)['message']);

        $ok = $check(' mpls 2026 ');
        $this->assertTrue($ok['ok']);
        $this->assertSame(5000, $ok['amount']);

        // Persen dengan batas maksimum: 10% dari Rp150.000 = Rp15.000 → maks. Rp10.000.
        $this->assertSame(10000, $check('GURUHEBAT', 150000)['amount']);
        $this->assertSame('Potongan khusus guru: potongan Rp3.000 (10%, maks. Rp10.000).', $check('GURUHEBAT', 30000)['message']);
    }

    public function test_cart_applies_valid_coupon_as_preview(): void
    {
        $c = Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 6) // Rp24.000
            ->assertSee('Pakai kode kupon')
            ->call('editCoupon')->assertSet('editingCoupon', true)
            ->set('couponInput', 'salahkode')->call('applyCoupon')
            ->assertHasErrors('couponInput')->assertSee('Kode SALAHKODE tidak dikenal')
            ->set('couponInput', 'mpls2026')->call('applyCoupon')
            ->assertHasNoErrors()->assertSet('appliedCoupon', 'MPLS2026')->assertSet('editingCoupon', false)
            ->assertSee('potongan Rp5.000')->assertSee('belum memotong total');

        $this->assertSame(24000, $c->instance()->summary['total']);
    }

    public function test_applied_coupon_is_rechecked_when_cart_shrinks(): void
    {
        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 6)
            ->call('editCoupon')->set('couponInput', 'MPLS2026')->call('applyCoupon')
            ->call('setQty', $this->pid('ATK001'), 3) // Rp12.000 < minimal Rp20.000
            ->assertSee('Kupon belum bisa dipakai: Minimal belanja Rp20.000 — kurang Rp8.000.')
            ->call('removeCoupon')->assertSet('appliedCoupon', null)
            ->call('editCoupon')->set('couponInput', 'MPLS2026')->call('applyCoupon')->assertHasErrors('couponInput');
    }

    public function test_clear_cart_removes_coupon(): void
    {
        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 6)
            ->call('editCoupon')->set('couponInput', 'GURUHEBAT')->call('applyCoupon')
            ->call('clear')->assertSet('appliedCoupon', null);
    }
}
