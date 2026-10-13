<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Changes;
use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Services\AuditLog;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChangesPageTest extends TestCase
{
    use RefreshDatabase;

    private Product $pencil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
        $this->pencil = Product::where('code', 'ATK003')->first();
    }

    /** Riwayat contoh: 40 hari lalu kategori dihapus; lalu perubahan barang, anggota, pengaturan. */
    private function seedChanges(): void
    {
        $this->travelTo('2026-09-04 10:00:00');
        Category::create(['name' => 'Aksesoris'])->delete();

        $this->travelTo('2026-10-12 09:00:00');
        $m = Member::create(['member_code' => 'S-2026-021', 'name' => 'Aisyah', 'type' => 'siswa', 'class' => '7C', 'joined_at' => '2026-10-12']);
        $this->loginAs('pengurus@koperasi.test');
        $m->update(['class' => '8C']);

        $this->travelTo('2026-10-14 10:00:00');
        $this->loginAs('admin@koperasi.test');
        AuditLog::withReason('Harga dari pemasok naik', fn () => $this->pencil->update(['price' => 3000, 'cost_price' => 2300]));
        $this->pencil->update(['min_stock' => 20]);
        app(SettingsService::class)->save('tax', ['enabled' => true, 'rate' => 2.5]);
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_admin_and_pengurus_can_open_but_kasir_cannot(): void
    {
        $this->seedChanges();
        $this->get('/audit/perubahan')->assertOk()->assertSee('Perubahan data')->assertSee('ATK003 · Pensil 2B')
            ->assertSee('Harga dari pemasok naik')->assertDontSee('Data contoh');
        $this->get('/audit')->assertOk()->assertSee(route('audit.perubahan'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/audit/perubahan')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/audit/perubahan')->assertForbidden();
    }

    public function test_period_entity_action_and_search_filters(): void
    {
        $this->seedChanges();
        $c = Livewire::test(Changes::class);
        $s = $c->instance()->summary;
        $this->assertSame(5, $s['total']); // kategori 40 hari lalu di luar 30 hari
        $this->assertSame(['barang' => 2, 'kategori' => 0, 'anggota' => 2, 'pengaturan' => 1, 'supplier' => 0, 'retur' => 0], $s['by_entity']);
        $this->assertSame(1, $s['price_changes']);
        $this->assertSame(4, $s['actors']['Admin Koperasi']);
        $this->assertSame(1, $s['actors']['Pak Dedi']);

        $c->call('setPeriod', 'semua');
        $this->assertSame(7, $c->instance()->total);

        $c->call('setEntity', 'barang');
        $this->assertSame(['barang'], array_values(array_unique(array_column($c->instance()->rows, 'entity'))));

        $c->call('setEntity', '')->call('setAction', 'dihapus');
        $this->assertSame(['Aksesoris'], array_column($c->instance()->rows, 'subject_label'));
        $c->call('setAction', 'dihapus');
        $this->assertSame('', $c->get('action'));

        $c->set('search', 'pemasok');
        $this->assertSame(['ATK003 · Pensil 2B'], array_column($c->instance()->rows, 'subject_label'));

        $c->call('resetFilters');
        $this->assertSame('30-hari', $c->get('period'));
    }

    public function test_sensitive_filter_subject_history_and_detail(): void
    {
        $this->seedChanges();
        $c = Livewire::test(Changes::class)->set('sensitiveOnly', true);
        $rows = $c->instance()->rows;
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertTrue(Changes::isSensitive($r));
        }
        $this->assertContains('Pajak & pembulatan', array_column($rows, 'subject_label'));
        $this->assertNotContains('S-2026-021 · Aisyah', array_column($rows, 'subject_label'));

        $c->set('sensitiveOnly', false)->call('followSubject', 'barang', $this->pencil->id);
        $this->assertSame(2, $c->instance()->total);
        $c->assertSee('ATK003 · Pensil 2B');

        $id = $c->instance()->rows[1]['id'];
        $c->call('show', $id)->assertSee('change-detail-title', false)->assertSee('Rp2.500')->assertSee('Rp3.000')
            ->assertSee('Harga dari pemasok naik')->assertSee('Alamat IP');
        $c->call('closeDetail')->assertSet('selectedId', null);

        $this->loginAs('pengurus@koperasi.test');
        Livewire::test(Changes::class)->call('show', $id)->assertDontSee('Alamat IP');
    }

    public function test_load_more_and_value_format(): void
    {
        foreach (range(1, Changes::PER_PAGE + 3) as $i) {
            $this->pencil->update(['min_stock' => $i]);
        }
        $c = Livewire::test(Changes::class);
        $this->assertCount(Changes::PER_PAGE, $c->instance()->rows);
        $c->assertSee('Tampilkan lebih banyak')->call('more');
        $this->assertCount(Changes::PER_PAGE + 3, $c->instance()->rows);

        $this->assertSame('Rp25.000', Changes::format('price', 25000));
        $this->assertSame('Ya', Changes::format('is_active', true));
        $this->assertSame('—', Changes::format('phone', null));
        $this->assertSame('7C', Changes::format('class', '7C'));
    }
}
