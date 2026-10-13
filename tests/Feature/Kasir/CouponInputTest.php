<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Coupon;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Support\CouponValidator;
use App\Support\MockData;
use Database\Seeders\MemberSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PromoSeeder;
use Illuminate\Validation\ValidationException;
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

    public function test_cart_applies_valid_coupon_to_total(): void
    {
        $this->seed(PromoSeeder::class);

        $c = Livewire::test(Cart::class)
            ->call('setQty', $this->pid('SRG001'), 2) // dasi Rp15.000 × 2 = Rp30.000, tanpa promo berjalan
            ->assertSee('Pakai kode kupon')
            ->call('editCoupon')->assertSet('editingCoupon', true)
            ->set('couponInput', 'salahkode')->call('applyCoupon')
            ->assertHasErrors('couponInput')->assertSee('Kode SALAHKODE tidak dikenal')
            ->set('couponInput', 'mpls2026')->call('applyCoupon')
            ->assertHasNoErrors()->assertSet('appliedCoupon', 'MPLS2026')->assertSet('editingCoupon', false)
            ->assertSee('potongan Rp5.000')->assertSee('sudah dikurangkan dari total');

        $this->assertSame([5000, 25000], [$c->instance()->summary['coupon'], $c->instance()->summary['total']]);

        $c->call('openPayment')->set('paymentMethod', 'qris')->call('pay')->assertHasNoErrors();
        $tx = Transaction::latest('id')->first();
        $row = $tx->discounts()->sole();
        $this->assertSame(['kupon', 'Kupon MPLS2026', 5000, 25000], [$row->source, $row->label, (int) $row->amount, (int) $tx->total]);
        $this->assertSame(1, Coupon::firstWhere('code', 'MPLS2026')->usedCount());
    }

    public function test_applied_coupon_is_rechecked_when_cart_shrinks(): void
    {
        $this->seed(PromoSeeder::class);

        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('SRG001'), 2)
            ->call('editCoupon')->set('couponInput', 'MPLS2026')->call('applyCoupon')
            ->call('setQty', $this->pid('SRG001'), 1) // Rp15.000 < minimal Rp20.000
            ->assertSee('Kupon belum bisa dipakai: Minimal belanja Rp20.000 — kurang Rp5.000.')
            ->call('openPayment')->set('paymentMethod', 'qris')->call('pay')
            ->assertHasErrors('paid');
        $this->assertSame(0, Transaction::count());
    }

    public function test_clear_cart_removes_coupon(): void
    {
        $this->seed(PromoSeeder::class);

        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('SRG001'), 2)
            ->call('editCoupon')->set('couponInput', 'GURUHEBAT')->call('applyCoupon')
            ->call('clear')->assertSet('appliedCoupon', null);
    }

    public function test_check_endpoint_gives_feedback(): void
    {
        $this->seed(PromoSeeder::class);
        $this->loginAs('kasir');

        $this->postJson('/kasir/kupon/cek', ['code' => 'gurU hebat', 'subtotal' => 150000])->assertOk()
            ->assertJson(['ok' => true, 'reason' => 'ok', 'amount' => 10000])
            ->assertJsonPath('coupon.code', 'GURUHEBAT')
            ->assertJsonMissingPath('coupon.id');
        $this->postJson('/kasir/kupon/cek', ['code' => 'HARIBATIK', 'subtotal' => 50000])->assertJson(['ok' => false, 'reason' => 'expired']);
        $this->postJson('/kasir/kupon/cek', ['code' => 'NGAWUR', 'subtotal' => 50000])->assertJson(['ok' => false, 'reason' => 'not_found']);
        $this->postJson('/kasir/kupon/cek', ['subtotal' => 50000])->assertJsonValidationErrors('code');

        $this->loginAs('pengurus');
        $this->postJson('/kasir/kupon/cek', ['code' => 'GURUHEBAT', 'subtotal' => 1000])->assertForbidden();
    }

    public function test_quota_and_per_member_limits_at_checkout(): void
    {
        $this->seed([PromoSeeder::class, MemberSeeder::class]);
        $coupon = Coupon::factory()->create(['code' => 'SEKALI', 'quota' => 2, 'per_member' => 1, 'value' => 1000]);
        $member = Member::first();
        $checkout = fn (?int $memberId) => app(CheckoutService::class)->checkout([$this->pid('SRG001') => 1], 'qris', null, null, $memberId, null, 'sekali');

        $checkout($member->id);
        try {
            $checkout($member->id);
            $this->fail('Batas per anggota tidak ditegakkan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah memakai kupon SEKALI', $e->errors()['coupon'][0]);
        }
        $checkout(null);
        $this->assertSame('habis', $coupon->fresh()->status);

        $this->expectException(ValidationException::class);
        $checkout(null);
    }
}
