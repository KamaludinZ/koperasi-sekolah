<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Payables;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Supplier;
use App\Models\SupplierBill;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PayablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, UserSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    private function bill(string $status): SupplierBill
    {
        return SupplierBill::where('status', $status)->firstOrFail();
    }

    public function test_access(): void
    {
        $this->get('/pengadaan/hutang')->assertOk()->assertSee('UD Berkah Pangan')->assertSee('1 hari lagi')->assertDontSee('Data contoh');
        $this->get('/pengadaan')->assertSee(route('pengadaan.hutang'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/hutang')->assertForbidden();
    }

    public function test_filters_and_summary(): void
    {
        $c = Livewire::test(Payables::class);
        $this->assertSame(['outstanding' => 259_200 + 166_400, 'overdue' => 0, 'week' => 259_200, 'paid_month' => 696_000 + 260_000], $c->instance()->summary);
        $this->assertSame(['belum-lunas' => 2, 'lewat' => 0, 'minggu-ini' => 1, 'lunas' => 1, 'semua' => 3], $c->instance()->counts);
        $this->assertSame([$this->bill('belum')->id, $this->bill('sebagian')->id], array_column($c->instance()->payables, 'id'));

        $c->call('setFilter', 'lunas');
        $this->assertSame([$this->bill('lunas')->id], array_column($c->instance()->payables, 'id'));
        $sinar = Supplier::where('name', 'CV Sinar Ilmu')->value('id');
        $c->call('setFilter', 'semua')->set('supplier', (string) $sinar);
        $this->assertCount(2, $c->instance()->payables);
        $c->set('supplier', '')->set('from', '2026-11-01')->set('to', '2026-11-30');
        $this->assertCount(2, $c->instance()->payables);
        $c->call('resetFilters')->assertSet('filter', 'belum-lunas');

        $this->travel(5)->days();
        $this->assertSame(1, Livewire::test(Payables::class)->instance()->counts['lewat']);
    }

    public function test_record_partial_then_full_payment(): void
    {
        $bill = $this->bill('belum');
        $c = Livewire::test(Payables::class)->call('pay', $bill->id)->assertSet('amount', 259_200)->assertSee('Bayar hutang ke UD Berkah Pangan');

        $c->set('amount', 300_000)->call('savePayment')->assertHasErrors('amount');
        $c->set('amount', 100_000)->set('paidAt', '2026-10-20')->call('savePayment')->assertHasErrors('paidAt');
        $c->set('paidAt', '2026-10-14')->set('reference', 'TRF-1')->call('savePayment')->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')->assertSet('payingId', null);
        $this->assertSame(['sebagian', 100_000], [$bill->fresh()->status, $bill->fresh()->paid]);

        $c->call('pay', $bill->id)->assertSet('amount', 159_200)->set('method', 'tunai')->call('savePayment');
        $this->assertSame('lunas', $bill->fresh()->status);
        $c->call('pay', $bill->id)->assertSet('payingId', null);
        $c->call('showHistory', $bill->id);
        $this->assertCount(2, $c->instance()->history['payments']);
        $c->assertSee('TRF-1');
        $this->assertSame(2, ActivityLog::where('event', 'payable.paid')->count());
    }

    public function test_endpoints_and_reminders(): void
    {
        $this->getJson('/pengadaan/hutang/data')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.week', 259_200);
        $this->getJson('/pengadaan/hutang/data?status=lunas')->assertJsonCount(1, 'data');
        $this->getJson('/pengadaan/hutang/'.$this->bill('sebagian')->id.'/data')->assertOk()->assertJsonPath('data.payments.0.reference', 'TRF-55120');

        $this->artisan('hutang:ingatkan')->expectsOutputToContain('1 notifikasi baru')->assertExitCode(0);
        $this->artisan('hutang:ingatkan')->expectsOutputToContain('0 notifikasi baru');
        $n = Notification::where('type', 'sistem')->where('title', 'like', 'Hutang jatuh tempo%')->sole();
        $this->assertStringContainsString('1 hari lagi', $n->message);
        $this->travel(5)->days();
        $this->artisan('hutang:ingatkan')->expectsOutputToContain('1 notifikasi baru');
        $this->assertTrue(Notification::where('title', 'like', 'Hutang lewat jatuh tempo%')->exists());
    }

    public function test_cashier_cannot_read_payables(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $this->getJson('/pengadaan/hutang/data')->assertForbidden();
    }
}
