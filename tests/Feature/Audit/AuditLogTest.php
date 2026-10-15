<?php

namespace Tests\Feature\Audit;

use App\Livewire\Auth\Login;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\MemberService;
use App\Services\ProductService;
use App\Services\SavingsService;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function last(string $event): ?ActivityLog
    {
        return ActivityLog::where('event', $event)->latest('id')->first();
    }

    public function test_sale_is_logged_with_actor_items_and_amount(): void
    {
        $tx = app(CheckoutService::class)->checkout([$this->pid('MNM001') => 2], 'tunai', 10000);

        $log = $this->last('sale.created');
        $this->assertSame('transaksi', $log->category);
        $this->assertSame('Bu Rina', $log->actor_name);
        $this->assertSame('kasir', $log->actor_role);
        $this->assertSame($tx->invoice_number, $log->subject_label);
        $this->assertSame(7000, $log->amount);
        $this->assertSame([['name' => 'Air Mineral 600 ml', 'qty' => 2, 'subtotal' => 7000]], $log->properties['items']);
        $this->assertSame('127.0.0.1', $log->ip_address);
    }

    public function test_failed_sale_leaves_no_log(): void
    {
        try {
            app(CheckoutService::class)->checkout([$this->pid('ATK005') => 1], 'tunai', 10000); // stok habis
        } catch (\Throwable) {
        }
        $this->assertSame(0, ActivityLog::where('event', 'sale.created')->count());
    }

    public function test_product_change_records_before_and_after(): void
    {
        $p = Product::where('code', 'ATK001')->first();
        app(ProductService::class)->update($p, [
            'code' => 'ATK001', 'name' => $p->name, 'category' => 'Alat Tulis', 'price' => 4500, 'cost_price' => 3200,
            'stock' => 100, 'min_stock' => 20, 'unit' => 'pcs', 'is_active' => true,
        ]);

        $this->assertEquals(['from' => '4000.00', 'to' => '4500.00'], $this->last('product.updated')->properties['changes']['price']);
        $stock = $this->last('stock.adjusted');
        $this->assertSame(['before' => 120, 'after' => 100], collect($stock->properties)->only('before', 'after')->all());
    }

    public function test_member_and_savings_are_logged(): void
    {
        $m = app(MemberService::class)->create(['name' => 'Andi', 'type' => 'siswa', 'class' => '7A']);
        app(SavingsService::class)->deposit($m, 5000);
        app(SavingsService::class)->withdraw($m, 2000);

        $this->assertStringContainsString('Anggota baru Andi (7A)', $this->last('member.created')->description);
        $this->assertSame(5000, $this->last('savings.deposit')->amount);
        $this->assertSame(3000, $this->last('savings.withdraw')->properties['balance_after']);
    }

    public function test_every_savings_mutation_has_an_audit_entry(): void
    {
        $m = Member::factory()->create();
        $savings = app(SavingsService::class);
        $payments = app(\App\Services\SavingsPaymentService::class);

        $savings->deposit($m, 50000);
        $savings->withdraw($m, 5000);
        $payments->charge($m->id, 4000, \App\Models\Transaction::factory()->create(['member_id' => $m->id]), null);
        $payments->debitForCredit($m->id, 3000, 77, 'Pelunasan bon PLN-TES');
        $payments->refund($m->id, 2000, 'return', 5, 'Retur RTR-TES');

        $logs = ActivityLog::where('category', 'tabungan')->get()->keyBy(fn ($l) => $l->properties['savings_id'] ?? null);
        foreach ($m->savings()->get() as $entry) {
            $log = $logs->get($entry->id);
            $this->assertNotNull($log, "Mutasi #{$entry->id} ({$entry->note}) tidak tercatat di audit.");
            $this->assertSame((int) $entry->amount, $log->amount);
            $this->assertSame((int) $entry->balance_before, $log->properties['balance_before']);
            $this->assertSame((int) $entry->balance_after, $log->properties['balance_after']);
        }
        $this->assertSame(5, $m->savings()->count());
        $this->assertSame(77, $this->last('savings.credit')->properties['payment_id']);
    }

    public function test_login_logout_and_failures_are_logged(): void
    {
        auth()->logout();
        Livewire::test(Login::class)->set('email', 'kasir@koperasi.test')->set('password', 'salah')->call('login');
        $failed = $this->last('auth.failed');
        $this->assertSame('Bu Rina', $failed->actor_name);
        $this->assertSame(1, $failed->properties['attempt']);

        Livewire::test(Login::class)->set('email', 'nonaktif@koperasi.test')->set('password', 'password')->call('login');
        $this->assertNotNull($this->last('auth.inactive'));

        Livewire::test(Login::class)->set('email', 'kasir@koperasi.test')->set('password', 'password')->call('login');
        $this->assertSame('Bu Rina masuk sebagai Kasir', $this->last('auth.login')->description);

        $this->post('/keluar');
        $this->assertSame('Bu Rina keluar', $this->last('auth.logout')->description);
    }

    public function test_logs_are_append_only(): void
    {
        app(MemberService::class)->create(['name' => 'Siti', 'type' => 'siswa', 'class' => '8B']);
        $log = ActivityLog::first();

        $log->description = 'diubah';
        $this->assertFalse($log->save());
        $this->assertFalse($log->delete());
        $this->assertSame(1, ActivityLog::count());

        if (DB::getDriverName() === 'pgsql') {
            $this->expectException(QueryException::class);
            DB::table('activity_logs')->where('id', $log->id)->delete();
        }
    }

    public function test_user_management_is_logged(): void
    {
        $this->loginAs('admin@koperasi.test'); // admin
        $id = fn (string $email) => \App\Models\User::where('email', $email)->value('id');
        Livewire::test(\App\Livewire\Pengguna\Index::class)->call('edit', $id('kasir@koperasi.test'))->set('form.roles', ['pengurus'])->call('save');
        Livewire::test(\App\Livewire\Pengguna\Index::class)->call('toggleActive', $id('pengurus@koperasi.test'));

        $role = $this->last('user.role_changed');
        $this->assertSame('Admin Koperasi', $role->actor_name);
        $this->assertSame(['from' => 'kasir', 'to' => 'pengurus'], $role->properties['changes']['roles']);
        $this->assertSame([['pengurus'], ['kasir']], [$role->properties['added'], $role->properties['removed']]);
        $this->assertNotNull($this->last('user.deactivated'));
    }
}
