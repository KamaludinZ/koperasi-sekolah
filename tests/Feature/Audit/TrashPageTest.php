<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Trash;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Services\TrashService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrashPageTest extends TestCase
{
    use RefreshDatabase;

    private Product $wafer;

    private Product $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');

        $this->wafer = Product::create(['code' => 'MKN004', 'name' => 'Wafer Stroberi', 'price' => 2500, 'category_id' => Category::where('name', 'Makanan')->value('id')]);
        $this->wafer->trash('Tidak dijual lagi');

        // Spidol dihapus, lalu kodenya dipakai barang baru → bentrok saat dipulihkan.
        $this->marker = Product::create(['code' => 'ATK006', 'name' => 'Spidol Papan Tulis', 'price' => 8000]);
        $this->marker->trash('Salah input');
        Product::create(['code' => 'ATK006', 'name' => 'Spidol Whiteboard Hitam', 'price' => 8500]);

        $this->travelTo('2026-09-17 10:00:00');
        Member::factory()->create(['member_code' => 'S-2023-019', 'name' => 'Rahmat'])->trash('Lulus');
        Category::create(['name' => 'Aksesoris'])->trash('Digabung ke Seragam');
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_admin_and_pengurus_can_open_but_kasir_cannot(): void
    {
        $this->get('/audit/terhapus')->assertOk()->assertSee('Data terhapus')->assertSee('MKN004 · Wafer Stroberi')
            ->assertSee('Tidak dijual lagi')->assertSee('Pulihkan')->assertDontSee('Data contoh');
        $this->get('/audit')->assertSee(route('audit.terhapus'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/audit/terhapus')->assertOk()->assertSee('Pemulihan hanya dapat dilakukan Admin');
        $this->loginAs('kasir@koperasi.test');
        $this->get('/audit/terhapus')->assertForbidden();
    }

    public function test_summary_filter_retention_and_conflicts(): void
    {
        $c = Livewire::test(Trash::class);
        $s = $c->instance()->summary;
        $this->assertSame(4, $s['total']);
        $this->assertSame(['barang' => 2, 'kategori' => 1, 'anggota' => 1, 'supplier' => 0], $s['by_entity']);
        $this->assertSame(2, $s['purge_soon']); // dihapus 27 hari lalu, sisa 3 hari
        $this->assertSame(1, $s['conflicts']);
        $this->assertSame(30, $s['keep_days']);

        $row = collect($c->instance()->items)->firstWhere('label', 'Aksesoris');
        $this->assertSame(3, Trash::daysLeft($row));
        $this->assertSame('Admin Koperasi', $row['deleted_by']);
        $conflict = collect($c->instance()->items)->firstWhere('key', 'barang:'.$this->marker->id)['conflict'];
        $this->assertStringContainsString('Spidol Whiteboard Hitam', $conflict);

        $c->call('setEntity', 'anggota');
        $this->assertSame(['anggota'], array_values(array_unique(array_column($c->instance()->items, 'entity'))));
        $c->call('setEntity', '')->set('search', 'wafer');
        $this->assertSame(['MKN004 · Wafer Stroberi'], array_column($c->instance()->items, 'label'));
    }

    public function test_admin_restores_one_after_confirmation(): void
    {
        $key = 'barang:'.$this->wafer->id;
        $c = Livewire::test(Trash::class)
            ->call('confirmRestore', $key)->assertSet('confirming', [$key])->assertSee('Pulihkan data ini?')
            ->call('cancelRestore')->assertSet('confirming', [])
            ->call('confirmRestore', $key)->call('restore')
            ->assertDispatched('toast', type: 'success', message: 'MKN004 · Wafer Stroberi dipulihkan.');

        $this->assertNotContains($key, array_column($c->instance()->items, 'key'));
        $this->assertNotNull(Product::find($this->wafer->id));
        $this->assertSame('dipulihkan', ActivityLog::where('event', 'product.restored')->value('action'));
    }

    public function test_bulk_restore_skips_conflicts(): void
    {
        $conflict = 'barang:'.$this->marker->id;
        $c = Livewire::test(Trash::class)->call('toggleAll');
        $this->assertCount(3, $c->get('selected'));
        $this->assertNotContains($conflict, $c->get('selected'));

        $c->call('confirmRestore')->assertSee('Pulihkan 3 data?')->call('restore')
            ->assertDispatched('toast', type: 'success', message: '3 data dipulihkan.')
            ->assertSet('selected', []);
        $this->assertSame([$conflict], array_column($c->instance()->items, 'key'));

        $c->call('confirmRestore', $conflict)->call('restore')->assertDispatched('toast', type: 'error');
        $this->assertCount(1, app(TrashService::class)->list());
    }

    public function test_pengurus_cannot_restore(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $key = 'barang:'.$this->wafer->id;
        Livewire::test(Trash::class)
            ->assertDontSee('Pulihkan terpilih')
            ->call('confirmRestore', $key)->assertSet('confirming', [])
            ->assertDispatched('toast', type: 'error')
            ->set('confirming', [$key])->call('restore');
        $this->assertCount(4, app(TrashService::class)->list());
    }

    public function test_endpoints(): void
    {
        $this->getJson('/audit/terhapus/data?jenis=barang')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 4)->assertJsonPath('can_restore', true)
            ->assertJsonPath('data.0.reason', 'Salah input');

        $this->postJson('/audit/terhapus/pulihkan', ['data' => ['barang:'.$this->marker->id]])->assertUnprocessable()
            ->assertJsonPath('failed.0.key', 'barang:'.$this->marker->id);
        $this->postJson('/audit/terhapus/pulihkan', ['data' => ['rahasia:1']])->assertJsonValidationErrors('data.0');
        $this->postJson('/audit/terhapus/pulihkan', ['data' => ['barang:'.$this->wafer->id], 'alasan' => 'Dijual lagi'])->assertOk()
            ->assertJsonPath('restored.0.label', 'MKN004 · Wafer Stroberi');
        $this->assertSame('Dijual lagi', ActivityLog::where('event', 'product.restored')->value('reason'));

        $this->loginAs('pengurus@koperasi.test');
        $this->getJson('/audit/terhapus/data')->assertOk()->assertJsonPath('can_restore', false);
        $this->postJson('/audit/terhapus/pulihkan', ['data' => ['kategori:1']])->assertForbidden();
        $this->loginAs('kasir@koperasi.test');
        $this->getJson('/audit/terhapus/data')->assertForbidden();
    }
}
