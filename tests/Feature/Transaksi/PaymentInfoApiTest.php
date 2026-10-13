<?php

namespace Tests\Feature\Transaksi;

use App\Models\Member;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\TransactionHistory;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentInfoApiTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Transaction $savings;

    private Transaction $bon;

    private Transaction $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->member = Member::factory()->create(['name' => 'Andi Pratama', 'member_code' => 'S-2026-001', 'balance' => 20000]);
        $checkout = app(CheckoutService::class);
        $this->savings = $checkout->checkout([$this->pid('ATK001') => 2], 'tabungan', null, auth()->id(), $this->member->id);
        $this->bon = $checkout->checkout([$this->pid('ATK001') => 1], 'bon', null, auth()->id(), $this->member->id, null, null, ['due_at' => today()->addDays(14)->toDateString()]);
        $this->cash = $checkout->checkout([$this->pid('ATK001') => 1], 'tunai', 5000, auth()->id());
    }

    public function test_receipt_api_includes_officer_member_and_savings(): void
    {
        $this->getJson(route('kasir.transactions.show', $this->savings))->assertOk()
            ->assertJsonPath('data.officer.id', auth()->id())->assertJsonPath('data.officer.name', 'Admin Koperasi')
            ->assertJsonPath('data.member_info.member_code', 'S-2026-001')
            ->assertJsonPath('data.savings.balance_before', 20000)->assertJsonPath('data.savings.amount', 8000)->assertJsonPath('data.savings.balance_after', 12000)
            ->assertJsonPath('data.balance_after', 12000)->assertJsonPath('data.credit', null);
    }

    public function test_detail_api_includes_credit(): void
    {
        $this->getJson(route('transaksi.detail', $this->bon))->assertOk()
            ->assertJsonPath('data.credit.status', 'belum')->assertJsonPath('data.credit.remaining', 4000)
            ->assertJsonPath('data.credit.due_at', today()->addDays(14)->toDateString())->assertJsonPath('data.savings', null)
            ->assertJsonPath('data.officer.name', 'Admin Koperasi')->assertJsonPath('data.member_info.name', 'Andi Pratama');

        $this->getJson(route('transaksi.detail', $this->cash))->assertOk()->assertJsonPath('data.member_info', null)->assertJsonPath('data.savings', null)->assertJsonPath('data.credit', null);
    }

    public function test_list_api_rows_have_balance_after_credit_and_cashier_without_n_plus_one(): void
    {
        $res = $this->getJson(route('transaksi.list'))->assertOk();
        $rows = collect($res->json('data'))->keyBy('id');
        $this->assertSame([12000, null], [$rows[$this->savings->id]['balance_after'], $rows[$this->savings->id]['credit']]);
        $this->assertSame('belum', $rows[$this->bon->id]['credit']['status']);
        $this->assertSame([null, null, auth()->id()], [$rows[$this->cash->id]['balance_after'], $rows[$this->cash->id]['credit'], $rows[$this->cash->id]['cashier_id']]);
        $this->assertSame('S-2026-001', $rows[$this->savings->id]['member_code']);

        DB::enableQueryLog();
        app(TransactionHistory::class)->paginate([], 20)->items();
        $this->assertLessThanOrEqual(7, count(DB::getQueryLog()));
    }

    public function test_detail_page_shows_officer_balance_and_credit(): void
    {
        $this->get(route('transaksi.show', $this->savings))->assertOk()->assertSee('Petugas')->assertSee('Saldo tabungan')->assertSee('Rp12.000');
        $this->get(route('transaksi.show', $this->bon))->assertOk()->assertSee('Belum dibayar')->assertSee($this->bon->credit->number);
    }
}
