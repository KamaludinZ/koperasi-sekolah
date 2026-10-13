<?php

namespace Tests\Feature\Transaksi;

use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Product;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->cashier = User::where('email', 'kasir@koperasi.test')->first() ?? User::factory()->create();
        $this->admin = auth()->user();
    }

    private function svc(): TransactionReturnService
    {
        return app(TransactionReturnService::class);
    }

    private function sale(string $method = 'tunai', ?int $memberId = null, array $credit = []): Transaction
    {
        $paid = $method === 'tunai' ? 20000 : null;

        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => 3, $this->pid('ATK002') => 1], $method, $paid, $this->cashier->id, $memberId, null, null, $credit ?: null);
    }

    private function line(Transaction $t, string $code)
    {
        return $t->items()->where('product_id', $this->pid($code))->first();
    }

    private function error(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Seharusnya gagal validasi.');
    }

    public function test_request_creates_pending_return_with_net_prices(): void
    {
        $t = $this->sale();
        $line = $this->line($t, 'ATK001');
        $r = $this->svc()->requestReturn($t, [$line->id => 2], 'rusak', false, 'tunai', 'Tutup bocor', $this->cashier);

        $this->assertMatchesRegularExpression('/^RTR-\d{8}-0001$/', $r->number);
        $this->assertSame(['retur', 'menunggu', 2, 2 * (int) $line->price, false, 'Barang rusak/cacat'], [$r->kind, $r->status, $r->quantity, $r->amount, $r->restock, $r->reason]);
        $this->assertSame([$line->id => 2], $t->returnedQuantities());
        $this->assertTrue(ActivityLog::where('event', 'return.requested')->exists());
        $this->assertSame(3, $line->fresh()->quantity); // transaksi tidak diubah
    }

    public function test_request_validation(): void
    {
        $t = $this->sale();
        $line = $this->line($t, 'ATK001');
        $this->assertArrayHasKey("qty.{$line->id}", $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 4], 'rusak', true, 'tunai', null, $this->cashier)));
        $this->assertArrayHasKey('qty', $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 0], 'rusak', true, 'tunai', null, $this->cashier)));
        $this->assertArrayHasKey('qty', $this->error(fn () => $this->svc()->requestReturn($t, [999999 => 1], 'rusak', true, 'tunai', null, $this->cashier)));
        $this->assertArrayHasKey('note', $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 1], 'lainnya', true, 'tunai', '', $this->cashier)));
        $this->assertArrayHasKey('reason', $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 1], 'bosan', true, 'tunai', null, $this->cashier)));
        $this->assertArrayHasKey('refund_method', $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 1], 'rusak', true, 'tabungan', null, $this->cashier)));

        $this->svc()->requestReturn($t, [$line->id => 1], 'rusak', true, 'tunai', null, $this->cashier);
        $this->assertStringContainsString('menunggu', $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 1], 'rusak', true, 'tunai', null, $this->cashier))['transaction'][0]);

        $old = $this->sale();
        $old->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->assertStringContainsString('lewat batas', $this->error(fn () => $this->svc()->requestReturn($old, [$this->line($old, 'ATK001')->id => 1], 'rusak', true, 'tunai', null, $this->cashier))['transaction'][0]);
        $this->assertSame(1, TransactionReturn::count());
    }

    public function test_approve_restocks_and_pays_cash_from_drawer(): void
    {
        $shift = CashShift::factory()->create(['opening_cash' => 50000]);
        $t = $this->sale();
        $line = $this->line($t, 'ATK001');
        $stock = Product::find($this->pid('ATK001'))->stock;
        $r = $this->svc()->requestReturn($t, [$line->id => 2], 'salah_ambil', true, 'tunai', null, $this->cashier);

        $done = $this->svc()->approve($r, $this->admin, 'Oke');
        $this->assertSame(['disetujui', $this->admin->id, 'Oke'], [$done->status, $done->decided_by, $done->decision_note]);
        $this->assertSame($stock + 2, Product::find($this->pid('ATK001'))->stock);
        $this->assertSame($r->amount, $t->fresh()->returned_amount);
        $out = CashMovement::where('type', 'out')->sole();
        $this->assertSame([(int) $r->amount, $shift->id], [(int) $out->amount, $out->cash_shift_id]);
        $this->assertTrue(ActivityLog::where('event', 'return.approved')->exists());

        // Sisa barang masih bisa diretur; jumlah sudah diretur dihitung.
        $this->assertArrayHasKey("qty.{$line->id}", $this->error(fn () => $this->svc()->requestReturn($t, [$line->id => 2], 'rusak', false, 'tunai', null, $this->cashier)));
        $this->svc()->requestReturn($t, [$line->id => 1], 'rusak', false, 'tunai', null, $this->cashier);
    }

    public function test_damaged_goods_not_restocked_and_rejection(): void
    {
        $t = $this->sale();
        $stock = Product::find($this->pid('ATK002'))->stock;
        $r = $this->svc()->requestReturn($t, [$this->line($t, 'ATK002')->id => 1], 'rusak', false, 'tunai', null, $this->cashier);
        $this->svc()->approve($r, $this->admin);
        $this->assertSame($stock, Product::find($this->pid('ATK002'))->stock);

        $r2 = $this->svc()->requestReturn($t, [$this->line($t, 'ATK001')->id => 1], 'rusak', true, 'tunai', null, $this->cashier);
        $this->assertArrayHasKey('decision_note', $this->error(fn () => $this->svc()->reject($r2, $this->admin, 'no')));
        $rej = $this->svc()->reject($r2, $this->admin, 'Barang sudah dipakai');
        $this->assertSame(['ditolak', 'Barang sudah dipakai'], [$rej->status, $rej->decision_note]);
        $this->assertArrayHasKey('status', $this->error(fn () => $this->svc()->approve($r2, $this->admin)));
        $this->assertSame((int) $r->amount, $t->fresh()->returned_amount);
    }

    public function test_requester_cannot_decide_own(): void
    {
        $t = $this->sale();
        $r = $this->svc()->requestReturn($t, [$this->line($t, 'ATK001')->id => 1], 'rusak', true, 'tunai', null, $this->admin);
        $this->assertArrayHasKey('decision_note', $this->error(fn () => $this->svc()->approve($r, $this->admin)));
        $this->assertSame('menunggu', $r->fresh()->status);
    }

    public function test_refund_to_savings(): void
    {
        $m = Member::factory()->create(['balance' => 50000]);
        $t = $this->sale('tabungan', $m->id);
        $after = (float) $m->fresh()->balance;
        $r = $this->svc()->requestReturn($t, [$this->line($t, 'ATK001')->id => 1], 'salah_ambil', true, 'tabungan', null, $this->cashier);
        $this->svc()->approve($r, $this->admin);
        $this->assertSame($after + $r->amount, (float) $m->fresh()->balance);
        $this->assertSame(['setor', 'return', $r->id], [SavingsTransaction::latest('id')->first()->type, SavingsTransaction::latest('id')->first()->source_type, (int) SavingsTransaction::latest('id')->first()->source_id]);
    }

    public function test_refund_reduces_bon_and_full_return_cancels_it(): void
    {
        $m = Member::factory()->create(['type' => 'guru']);
        $t = $this->sale('bon', $m->id, ['due_at' => today()->addWeek()->toDateString()]);
        $credit = $t->credit;
        $total = $credit->amount;

        $r = $this->svc()->requestReturn($t, [$this->line($t, 'ATK001')->id => 1], 'rusak', false, 'bon', null, $this->cashier);
        $this->svc()->approve($r, $this->admin);
        $this->assertSame([$total - $r->amount, 'belum'], [$credit->fresh()->amount, $credit->fresh()->status]);

        $all = $t->items->mapWithKeys(fn ($i) => [$i->id => $i->quantity - ($t->returnedQuantities()[$i->id] ?? 0)])->all();
        $r2 = $this->svc()->requestReturn($t->fresh(), $all, 'rusak', false, 'bon', null, $this->cashier);
        $this->svc()->approve($r2, $this->admin);
        $this->assertSame('dibatalkan', $credit->fresh()->status);
        $this->assertSame(0, $credit->fresh()->remaining());
        $this->assertSame((int) $t->total, $t->fresh()->returned_amount);
    }

    public function test_cash_refund_fails_when_drawer_short(): void
    {
        CashShift::factory()->create(['opening_cash' => 0]);
        $t = $this->sale('qris');
        $r = $this->svc()->requestReturn($t, [$this->line($t, 'ATK001')->id => 3], 'rusak', true, 'tunai', null, $this->cashier);
        $this->assertArrayHasKey('amount', $this->error(fn () => $this->svc()->approve($r, $this->admin)));
        $this->assertSame('menunggu', $r->fresh()->status);

        $this->svc()->reject($r, $this->admin, 'Kas kosong, ajukan ulang via transfer');
        $r2 = $this->svc()->requestReturn($t, [$this->line($t, 'ATK001')->id => 3], 'rusak', true, 'qris', null, $this->cashier);
        $this->svc()->approve($r2, $this->admin);
        $this->assertSame(0, CashMovement::count());
    }
}
