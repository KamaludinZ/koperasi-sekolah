<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Transaksi\OfflineSales;
use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\Notification;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\User;
use App\Services\OfflineCatalogService;
use App\Services\OfflineSaleService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Halaman Transaksi → Penjualan offline: daftar kiriman, saringan, tinjauan Admin/Pengurus (audit) & notifikasi. */
class OfflineReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->freezeTime();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        CashShift::factory()->create(['user_id' => $this->kasir->id, 'opened_at' => now()->subHour()]);
    }

    /** Buat satu kiriman offline: $kind = ok | short (stok kurang, tanpa shift) | tampered (ditolak). */
    private function send(string $kind): OfflineSale
    {
        $p = Product::factory()->create(['price' => 2000, 'stock' => $kind === 'short' ? 0 : 10, 'is_active' => true]);
        $catalog = app(OfflineCatalogService::class)->issue($this->kasir, 'perangkat-uji-01');
        $this->travel(5)->minutes();

        return app(OfflineSaleService::class)->ingest($this->kasir, [
            'client_ref' => (string) Str::uuid(), 'catalog_id' => $catalog['catalog_id'], 'device_id' => 'perangkat-uji-01', 'local_number' => 'OFF-UJI-'.$kind,
            'sold_at' => now()->toIso8601String(), 'payment_method' => 'tunai', 'paid' => 5000, 'total' => 2000,
            'items' => [['product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'price' => $kind === 'tampered' ? 1 : 2000]],
        ]);
    }

    public function test_problem_sales_listed_and_notified(): void
    {
        $ok = $this->send('ok');
        $short = $this->send('short');
        $bad = $this->send('tampered');
        $this->assertSame(['diterima', 'perlu_dicek', 'ditolak'], [$ok->status, $short->status, $bad->status]);
        $this->assertSame(2, Notification::where('dedupe_key', 'like', 'offline-perlu-tinjau-%')->count());   // admin & pengurus, satu per hari
        $this->assertStringContainsString('2 kiriman', Notification::where('target', 'admin')->where('dedupe_key', 'like', 'offline-%')->value('message'));

        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
        $this->get('/transaksi/offline')->assertOk()->assertSeeText('Penjualan Offline');
        Livewire::test(OfflineSales::class)
            ->assertSeeHtml('data-offline-sale="'.$short->id.'"')->assertSeeHtml('data-offline-sale="'.$bad->id.'"')->assertDontSeeHtml('data-offline-sale="'.$ok->id.'"')
            ->assertSeeHtml('data-offline-flag="stok_kurang"')->assertSeeHtml('data-offline-reason')->assertSeeText('Perlu ditinjau (2)')
            ->set('filter', 'diterima')->assertSeeHtml('data-offline-sale="'.$ok->id.'"')->assertDontSeeHtml('data-offline-sale="'.$bad->id.'"')
            ->set('filter', 'aneh')->assertSet('filter', 'bermasalah');
    }

    public function test_review_requires_note_and_is_audited(): void
    {
        $short = $this->send('short');
        $admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($admin, 'web');
        Livewire::test(OfflineSales::class)->call('startReview', $short->id)->set('note', 'ok')->call('review')->assertHasErrors('note')
            ->set('note', 'Stok opname roti sudah disesuaikan')->call('review')->assertHasNoErrors()
            ->assertSeeHtml('data-offline-empty')
            ->set('filter', 'ditinjau')->assertSeeText('Stok opname roti sudah disesuaikan');
        $short->refresh();
        $this->assertSame([$admin->id, 'Stok opname roti sudah disesuaikan'], [$short->reviewed_by, $short->review_note]);
        $this->assertSame('Stok opname roti sudah disesuaikan', ActivityLog::where('event', 'sale.offline_reviewed')->value('reason'));
    }

    public function test_cashier_sees_only_own_sales_and_cannot_review(): void
    {
        $short = $this->send('short');
        $this->actingAs($this->kasir, 'web');
        Livewire::test(OfflineSales::class)->assertSeeHtml('data-offline-sale="'.$short->id.'"')->assertDontSeeHtml('data-start-review=')
            ->call('startReview', $short->id)->assertForbidden();

        $other = User::factory()->create(['role_id' => $this->kasir->role_id, 'is_active' => true]);
        $other->roles()->sync([$this->kasir->role_id]);
        $this->actingAs($other, 'web');
        Livewire::test(OfflineSales::class)->assertDontSeeHtml('data-offline-sale="'.$short->id.'"')->assertSeeHtml('data-offline-empty');
    }
}
