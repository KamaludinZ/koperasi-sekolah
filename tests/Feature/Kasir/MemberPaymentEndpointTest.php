<?php

namespace Tests\Feature\Kasir;

use App\Models\Member;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    public function test_search_active_members_without_phone(): void
    {
        Member::factory()->create(['name' => 'Andi Pratama', 'member_code' => 'S-2026-001', 'balance' => 20000, 'phone' => '0812']);
        Member::factory()->create(['name' => 'Andi Nonaktif', 'is_active' => false]);

        $this->getJson(route('kasir.members.search', ['q' => 'andi']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.member_code', 'S-2026-001')->assertJsonPath('data.0.balance', 20000)
            ->assertJsonMissingPath('data.0.phone');
        $this->getJson(route('kasir.members.search', ['q' => 'a']))->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_savings_check(): void
    {
        $m = Member::factory()->create(['balance' => 10000]);
        $this->getJson(route('kasir.members.savings', ['member' => $m, 'total' => 8000]))->assertOk()
            ->assertJson(['can_pay' => true, 'reason' => 'ok', 'balance' => 10000, 'amount' => 8000, 'shortfall' => 0, 'balance_after' => 2000])
            ->assertJsonPath('member.id', $m->id);
        $this->getJson(route('kasir.members.savings', ['member' => $m, 'total' => 13000]))->assertOk()
            ->assertJson(['can_pay' => false, 'reason' => 'insufficient', 'shortfall' => 3000, 'balance_after' => null]);
        $this->getJson(route('kasir.members.savings', ['member' => $m]))->assertStatus(422)->assertJsonValidationErrors('total');

        $m->update(['is_active' => false]);
        $this->getJson(route('kasir.members.savings', ['member' => $m, 'total' => 1000]))->assertOk()->assertJson(['can_pay' => false, 'reason' => 'inactive']);
        $this->getJson(route('kasir.members.savings', ['member' => 999999, 'total' => 1000]))->assertNotFound();
    }

    public function test_full_flow_check_then_checkout(): void
    {
        $this->seed(ProductSeeder::class);
        $m = Member::factory()->create(['balance' => 10000]);
        $this->getJson(route('kasir.members.savings', ['member' => $m, 'total' => 4000]))->assertJson(['can_pay' => true]);
        $this->postJson(route('kasir.checkout'), ['items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 1]], 'payment_method' => 'tabungan', 'member_id' => $m->id])
            ->assertCreated();
        $this->getJson(route('kasir.members.savings', ['member' => $m, 'total' => 4000]))->assertJson(['balance' => 6000, 'balance_after' => 2000]);
    }

    public function test_requires_kasir_menu(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $user = auth()->user();
        if ($user->canOpen('kasir')) {
            $this->markTestSkipped('Pengurus punya menu kasir di data awal.');
        }
        $this->getJson(route('kasir.members.search', ['q' => 'andi']))->assertForbidden();
    }
}
