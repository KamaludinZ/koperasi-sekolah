<?php

namespace Tests\Feature\Anggota;

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\MemberCredit;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\MemberCreditService;
use App\Services\ReceiptPresenter;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MemberCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function bon(int $memberId, int $qty = 2, ?string $due = null, ?string $note = null): Transaction
    {
        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => $qty], 'bon', null, auth()->id(), $memberId, null, null,
            ['due_at' => $due ?? today()->addDays(14)->toDateString(), 'note' => $note]); // Rp4.000/pcs
    }

    private function errorOf(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }
        $this->fail('Seharusnya gagal validasi.');
    }

    public function test_limits_from_config_or_member(): void
    {
        $siswa = Member::factory()->create(['type' => 'siswa']);
        $guru = Member::factory()->create(['type' => 'guru']);
        $this->assertSame([50000, 300000], [MemberCreditService::limitFor($siswa), MemberCreditService::limitFor($guru)]);
        $siswa->update(['credit_limit' => 20000]);
        $this->assertSame(20000, MemberCreditService::limitFor($siswa->fresh()));
        config(['koperasi.credit.limits.guru' => 100000]);
        $this->assertSame(100000, MemberCreditService::limitFor($guru));
    }

    public function test_checkout_with_bon_records_credit(): void
    {
        $m = Member::factory()->create(['type' => 'siswa', 'balance' => 0]);
        $tx = $this->bon($m->id, 2, today()->addDays(10)->toDateString(), 'Dibayar orang tua');

        $this->assertSame(['bon', $m->id, 8000, 8000, 0], [$tx->payment_method, $tx->member_id, (int) $tx->total, (int) $tx->paid, (int) $tx->change]);
        $credit = $tx->credit;
        $this->assertMatchesRegularExpression('/^BON-\d{8}-0001$/', $credit->number);
        $this->assertSame([8000, 0, 'belum', today()->addDays(10)->toDateString(), 'Dibayar orang tua'], [$credit->amount, $credit->paid, $credit->status, $credit->due_at->toDateString(), $credit->note]);
        $this->assertSame(0.0, (float) $m->fresh()->balance);
        $this->assertTrue(ActivityLog::where('event', 'credit.created')->exists());

        $s = app(MemberCreditService::class)->summary($m);
        $this->assertSame(['limit' => 50000, 'outstanding' => 8000, 'available' => 42000, 'open_bills' => 1, 'oldest_due_at' => today()->addDays(10)->toDateString(), 'overdue' => false], $s);

        $receipt = app(ReceiptPresenter::class)->present($tx);
        $this->assertSame([$credit->number, today()->addDays(10)->toDateString()], [$receipt['credit_number'], $receipt['credit_due_at']]);

        $this->assertSame('BON-'.now()->format('Ymd').'-0002', $this->bon($m->id, 1)->credit->number);
    }

    public function test_limit_overdue_inactive_and_due_date_rules(): void
    {
        $m = Member::factory()->create(['type' => 'siswa', 'credit_limit' => 10000]);
        $stock = Product::find($this->pid('ATK001'))->stock;

        $this->assertStringContainsString('Melebihi sisa batas bon sebesar Rp2.000', $this->errorOf(fn () => $this->bon($m->id, 3)));
        $this->assertSame(0, Transaction::count());
        $this->assertSame($stock, Product::find($this->pid('ATK001'))->stock);

        $this->assertSame('Jatuh tempo paling cepat besok.', $this->errorOf(fn () => $this->bon($m->id, 1, today()->toDateString())));
        $this->assertSame('Jatuh tempo paling lama 60 hari dari hari ini.', $this->errorOf(fn () => $this->bon($m->id, 1, today()->addDays(61)->toDateString())));
        $this->assertSame('Pilih tanggal jatuh tempo bon.', $this->errorOf(fn () => app(CheckoutService::class)->checkout([$this->pid('ATK001') => 1], 'bon', null, null, $m->id)));
        $this->assertStringContainsString('Pilih anggota', $this->errorOf(fn () => $this->bon(0)));

        MemberCredit::create(['number' => 'BON-LAMA', 'member_id' => $m->id, 'amount' => 1000, 'due_at' => today()->subDay()]);
        $this->assertStringContainsString('lewat jatuh tempo', $this->errorOf(fn () => $this->bon($m->id, 1)));

        $inactive = Member::factory()->create(['is_active' => false]);
        $this->assertStringContainsString('nonaktif', $this->errorOf(fn () => $this->bon($inactive->id, 1)));
        $this->assertSame(0, Transaction::count());
    }

    public function test_check_reasons(): void
    {
        $svc = app(MemberCreditService::class);
        $m = Member::factory()->create(['type' => 'siswa']);
        $this->assertSame(['can_pay' => true, 'reason' => 'ok', 'shortfall' => 0], array_intersect_key($svc->check($m, 50000), ['can_pay' => 1, 'reason' => 1, 'shortfall' => 1]));
        $this->assertSame(['over_limit', 1], [$svc->check($m, 50001)['reason'], $svc->check($m, 50001)['shortfall']]);
        MemberCredit::create(['number' => 'BON-X', 'member_id' => $m->id, 'amount' => 1000, 'paid' => 400, 'status' => 'sebagian', 'due_at' => today()->subDay()]);
        $this->assertSame(['overdue', 600], [$svc->check($m, 1)['reason'], $svc->summary($m)['outstanding']]);
        MemberCredit::create(['number' => 'BON-Y', 'member_id' => $m->id, 'amount' => 9000, 'status' => 'dibatalkan', 'due_at' => today()->subDay()]);
        $this->assertSame(1, $svc->summary($m)['open_bills']);
    }

    public function test_bon_sales_not_cash_in_shift(): void
    {
        $shift = CashShift::factory()->create();
        $m = Member::factory()->create();
        $tx = $this->bon($m->id);
        $this->assertSame([$shift->id, 0], [$tx->cash_shift_id, $shift->fresh()->cashSales()]);
    }

    public function test_endpoints(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $m = Member::factory()->create(['type' => 'siswa']);
        $this->getJson(route('kasir.members.credit', ['member' => $m, 'total' => 8000]))->assertOk()
            ->assertJson(['can_pay' => true, 'reason' => 'ok', 'limit' => 50000, 'available' => 50000, 'max_days' => 60])->assertJsonPath('member.id', $m->id);

        $item = [['product_id' => $this->pid('ATK001'), 'quantity' => 2]];
        $this->postJson(route('kasir.checkout'), ['items' => $item, 'payment_method' => 'bon', 'member_id' => $m->id])
            ->assertStatus(422)->assertJsonValidationErrors('credit_due_at');
        $this->postJson(route('kasir.checkout'), ['items' => $item, 'payment_method' => 'bon', 'credit_due_at' => today()->addWeek()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('member_id');
        $this->postJson(route('kasir.checkout'), ['items' => $item, 'payment_method' => 'bon', 'member_id' => $m->id, 'credit_due_at' => today()->addWeek()->toDateString(), 'credit_note' => 'Kas kelas'])
            ->assertCreated()->assertJsonPath('data.payment_method', 'bon');
        $this->assertSame('Kas kelas', MemberCredit::sole()->note);

        $this->getJson(route('kasir.members.credit', ['member' => $m, 'total' => 45000]))->assertJson(['can_pay' => false, 'reason' => 'over_limit', 'shortfall' => 3000, 'outstanding' => 8000]);
    }
}
