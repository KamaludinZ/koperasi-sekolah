<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Portal\OrderShow;
use App\Livewire\Portal\Shop;
use App\Models\Member;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ImpersonationPolicy;
use App\Support\ImpersonationSession;
use App\Support\PortalCart;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PortalImpersonationModeTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, ProductSeeder::class]);
        $this->andi = Member::factory()->create(['name' => 'Andi']);
        $this->actingAsMember($this->andi);
    }

    private function impersonate(): void
    {
        $admin = User::where('email', 'admin@koperasi.test')->first();
        session([ImpersonationSession::KEY => [
            'id' => 'aaaaaaaa-1111-2222-3333-444444444444', 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'target_type' => 'anggota', 'target_id' => $this->andi->id, 'target_name' => 'Andi', 'target_label' => $this->andi->member_code,
            'reason' => 'Membantu anggota yang tidak bisa memakai portal', 'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(30)->timestamp,
        ]]);
    }

    private function order(): Order
    {
        return app(OrderService::class)->place($this->andi, [$this->pid('ATK001') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tunai']);
    }

    public function test_portal_pages_remain_viewable_with_banner_and_no_logout_button(): void
    {
        $this->impersonate();
        foreach (['/portal', '/portal/belanja', '/portal/simpanan', '/portal/pinjaman', '/portal/riwayat', '/portal/pesanan'] as $page) {
            $this->get($page)->assertOk()->assertSeeHtml('data-impersonation-banner')->assertSeeHtml('data-read-only-mode');
        }
        $this->get('/portal')->assertDontSeeHtml('action="'.route('portal.logout').'"');
    }

    public function test_money_and_pin_actions_are_blocked_on_the_server(): void
    {
        $o = $this->order();
        PortalCart::set($this->pid('ATK001'), 1);
        $this->impersonate();

        $this->get('/portal/checkout')->assertForbidden()->assertSeeText('Tidak tersedia dalam mode impersonasi');
        $this->postJson('/portal/pesanan', ['fulfillment' => 'ambil', 'payment_method' => 'tunai'])->assertForbidden()
            ->assertJsonPath('message', ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE);
        $this->postJson("/portal/pesanan/{$o->id}/bayar-saldo", ['pin' => '123456'])->assertForbidden();
        $this->postJson('/portal/pin/verifikasi', ['pin' => '123456'])->assertForbidden();
        $this->assertSame(1, Order::count()); // tidak ada pesanan baru
    }

    public function test_cart_shows_read_only_notice_instead_of_checkout_link(): void
    {
        PortalCart::set($this->pid('ATK001'), 1);
        $this->impersonate();
        Livewire::test(Shop::class)->set('cartOpen', true)->assertSeeHtml('data-read-only')->assertDontSee(route('portal.checkout'));
    }

    public function test_order_cannot_be_cancelled_while_impersonating(): void
    {
        $o = $this->order();
        $this->impersonate();
        Livewire::test(OrderShow::class, ['order' => $o->id])
            ->assertSeeHtml('data-read-only')->assertDontSeeText('Batalkan pesanan…')
            ->set('reason', 'Salah pilih barang')->call('cancel')->assertHasErrors('reason');
        $this->assertSame('menunggu', $o->fresh()->status);
    }

    public function test_without_impersonation_everything_works_as_before(): void
    {
        $o = $this->order();
        PortalCart::set($this->pid('ATK001'), 1);
        $this->get('/portal/checkout')->assertOk();
        $this->get('/portal')->assertSeeHtml('action="'.route('portal.logout').'"')->assertDontSeeHtml('data-impersonation-banner');
        Livewire::test(OrderShow::class, ['order' => $o->id])->assertSeeText('Batalkan pesanan…');
    }

    public function test_staff_impersonation_does_not_make_portal_read_only(): void
    {
        session([ImpersonationSession::KEY => ['id' => 'x', 'admin_id' => 1, 'admin_name' => 'A', 'target_type' => 'petugas', 'target_id' => 2,
            'target_name' => 'B', 'reason' => 'membantu petugas', 'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(5)->timestamp]]);
        $this->assertFalse(ImpersonationSession::impersonatingMember());
        PortalCart::set($this->pid('ATK001'), 1);
        $this->get('/portal/checkout')->assertOk();
    }
}
