<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Checkout;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\Order;
use App\Support\PortalCart;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PinVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->andi = Member::factory()->create(['name' => 'Andi', 'password' => 'rahasia123', 'pin' => '246810']);
        $this->actingAsMember($this->andi);
        PortalCart::set($this->pid('ATK001'), 1);
    }

    public function test_review_opens_pin_step_then_correct_pin_places_order(): void
    {
        Livewire::test(Checkout::class)->call('review')->assertSet('confirming', true)->assertSee('Verifikasi PIN')->assertSee('data-pin-dialog', false)->assertSee('data-pin-input', false)->assertSee('pinInput', false)
            ->set('pin', '246810')->call('place')->assertHasNoErrors()->assertRedirect(route('portal.pesanan.show', Order::value('id')));
        $this->assertSame(1, Order::count());
    }

    public function test_wrong_pin_rejected_and_locked_after_three(): void
    {
        $c = Livewire::test(Checkout::class)->call('review');
        $c->set('pin', '111111')->call('place')->assertHasErrors(['pin' => 'PIN salah. Sisa 2 percobaan.'])->assertSet('pin', '')->assertSet('confirming', true);
        // Dialog dibuka ulang di komponen baru: sisa percobaan tetap terlihat dari server.
        Livewire::test(Checkout::class)->call('review')->assertSee('data-pin-left="2"', false)->assertSee('Sisa 2 percobaan');
        foreach (range(1, 2) as $i) {
            $c->set('pin', '111111')->call('place');
        }
        $c->set('pin', '246810')->call('place')->assertHasErrors('pin')->assertSee('PIN salah terlalu sering')->assertSee('data-pin-locked', false)
            ->assertSee('data-countdown', false)->assertSee('15:00')->assertDontSee('data-pin-input', false);
        $this->travel(16)->minutes();
        Livewire::test(Checkout::class)->call('review')->assertDontSee('data-pin-locked', false)->assertSee('data-pin-input', false);
        $this->assertSame(0, Order::count());
        $this->assertSame(3, ActivityLog::where('event', 'portal.pin_failed')->count());
        $c->set('pin', 'abcdef')->call('place')->assertHasErrors('pin');
    }

    public function test_member_without_pin_cannot_checkout(): void
    {
        $this->andi->forceFill(['pin' => null])->save();
        $this->get('/portal/checkout')->assertOk()->assertSee('data-no-pin', false);
        Livewire::test(Checkout::class)->call('review')->set('pin', '123456')->call('place')
            ->assertHasErrors(['pin' => 'PIN transaksi belum diatur. Minta pengurus koperasi mengaturnya.']);
        $this->assertSame(0, Order::count());
    }

    public function test_pin_is_hashed_hidden_and_not_a_login_secret(): void
    {
        $this->assertNotSame('246810', $this->andi->getAttributes()['pin']);
        $this->assertArrayNotHasKey('pin', $this->andi->toArray());
        auth('member')->logout();
        Livewire::test(\App\Livewire\Portal\Login::class)->set('identifier', $this->andi->member_code)->set('password', '246810')->call('login')->assertHasErrors('identifier');
    }
}
