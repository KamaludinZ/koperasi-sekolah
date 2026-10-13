<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Pengaturan\Returns as ReturnsSettings;
use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Coupon;
use App\Models\Member;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\SettingsService;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class VoidServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->cashier = User::where('email', 'kasir@koperasi.test')->first();
    }

    private function svc(): TransactionReturnService
    {
        return app(TransactionReturnService::class);
    }

    private function sale(int $qty = 2, string $method = 'tunai', ?int $memberId = null, ?string $coupon = null, array $credit = []): Transaction
    {
        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => $qty], $method, $method === 'tunai' ? 100000 : null, $this->cashier->id, $memberId, null, $coupon, $credit ?: null);
    }

    public function test_small_recent_void_by_same_cashier_is_auto_approved(): void
    {
        CashShift::factory()->create(['opening_cash' => 50000]);
        $t = $this->sale(2); // Rp8.000
        $stock = Product::find($this->pid('ATK001'))->stock;

        $r = $this->svc()->requestVoid($t, 'salah_input', null, $this->cashier);
        $this->assertSame(['batal', 'disetujui', null, 8000, 2], [$r->kind, $r->status, $r->decided_by, $r->amount, $r->quantity]);
        $this->assertStringContainsString('otomatis', $r->decision_note);
        $this->assertMatchesRegularExpression('/^BTL-\d{8}-0001$/', $r->number);

        $t->refresh();
        $this->assertTrue($t->isVoided());
        $this->assertSame([$this->cashier->id, 8000], [$t->voided_by, $t->returned_amount]);
        $this->assertStringContainsString('Salah input kasir', $t->void_reason);
        $this->assertSame($stock + 2, Product::find($this->pid('ATK001'))->stock);
        $this->assertSame(8000, (int) CashMovement::where('type', 'out')->sole()->amount);
        $this->assertTrue(ActivityLog::where('event', 'void.approved')->exists());
        $this->assertSame(0, Transaction::notVoided()->count());
    }

    public function test_threshold_rules_require_approval(): void
    {
        $other = User::factory()->create();
        $this->assertNull(TransactionReturnService::autoApproval($this->sale(10), 40000, $this->cashier)); // di atas Rp20.000
        $this->assertNull(TransactionReturnService::autoApproval($this->sale(1), 4000, $other));         // bukan kasirnya
        $old = $this->sale(1);
        $old->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $this->assertNull(TransactionReturnService::autoApproval($old, 4000, $this->cashier));            // lewat 30 menit
        app(SettingsService::class)->save('returns', ['void_auto_max' => 0]);
        $this->assertNull(TransactionReturnService::autoApproval($this->sale(1), 4000, $this->cashier));  // dimatikan

        $big = $this->sale(10);
        $r = $this->svc()->requestVoid($big, 'batal_beli', null, $this->cashier);
        $this->assertSame('menunggu', $r->status);
        $this->assertFalse($big->fresh()->isVoided());

        $done = $this->svc()->approve($r, auth()->user(), 'Disetujui');
        $this->assertSame('disetujui', $done->status);
        $this->assertSame(auth()->id(), $big->fresh()->voided_by);
    }

    public function test_void_validation_and_blocks(): void
    {
        $t = $this->sale(10);
        $err = fn (callable $f) => (function () use ($f) {
            try {
                $f();
            } catch (ValidationException $e) {
                return $e->errors();
            }
            $this->fail('Seharusnya gagal.');
        })();

        $this->assertArrayHasKey('reason', $err(fn () => $this->svc()->requestVoid($t, 'iseng', null, $this->cashier)));
        $this->assertArrayHasKey('note', $err(fn () => $this->svc()->requestVoid($t, 'lainnya', 'abc', $this->cashier)));

        $this->svc()->requestVoid($t, 'ganda', null, $this->cashier);
        $this->assertArrayHasKey('transaction', $err(fn () => $this->svc()->requestVoid($t, 'ganda', null, $this->cashier)));

        $small = $this->sale(1);
        $this->svc()->requestVoid($small, 'ganda', null, $this->cashier); // otomatis disetujui
        $this->assertStringContainsString('sudah dibatalkan', $err(fn () => $this->svc()->requestVoid($small, 'ganda', null, $this->cashier))['transaction'][0]);
        $this->assertArrayHasKey('transaction', $err(fn () => $this->svc()->requestReturn($small, [$small->items->first()->id => 1], 'rusak', true, 'tunai', null, $this->cashier)));
    }

    public function test_void_after_partial_return_covers_remainder(): void
    {
        $t = $this->sale(10); // Rp40.000
        $line = $t->items->first();
        $ret = $this->svc()->requestReturn($t, [$line->id => 3], 'rusak', false, 'tunai', null, $this->cashier);
        $this->svc()->approve($ret, auth()->user());

        $v = $this->svc()->requestVoid($t->fresh(), 'batal_beli', null, $this->cashier);
        $this->assertSame([7, 28000], [$v->quantity, $v->amount]);
        $this->svc()->approve($v, auth()->user());
        $this->assertSame(40000, $t->fresh()->returned_amount);
    }

    public function test_void_releases_coupon_and_refunds_member_methods(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'HEMAT1', 'type' => 'nominal', 'value' => 1000, 'quota' => 5, 'min_purchase' => 0]);
        $t = $this->sale(2, coupon: 'HEMAT1');
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->svc()->requestVoid($t, 'salah_input', null, $this->cashier);
        $this->assertSame(0, $coupon->fresh()->used_count);

        $m = Member::factory()->create(['balance' => 30000]);
        $sav = $this->sale(2, 'tabungan', $m->id);
        $this->svc()->requestVoid($sav, 'batal_beli', null, $this->cashier);
        $this->assertSame(30000.0, (float) $m->fresh()->balance);

        $bon = $this->sale(2, 'bon', $m->id, credit: ['due_at' => today()->addWeek()->toDateString()]);
        $this->svc()->requestVoid($bon, 'batal_beli', null, $this->cashier);
        $this->assertSame('dibatalkan', $bon->credit->fresh()->status);
    }

    public function test_settings_page(): void
    {
        $this->get('/pengaturan/retur')->assertOk()->assertSee('Batal otomatis sampai')->assertSee('Rp20.000');
        Livewire::test(ReturnsSettings::class)->set('limit_days', 5)->set('void_auto_max', 0)->call('save')->assertHasNoErrors();
        $this->assertSame([5, 0], [app(SettingsService::class)->returns()['limit_days'], app(SettingsService::class)->returns()['void_auto_max']]);
        $this->assertSame(5, TransactionReturnService::limitDays());
        Livewire::test(ReturnsSettings::class)->set('limit_days', 0)->assertHasErrors(['limit_days' => 'min']);
    }
}
