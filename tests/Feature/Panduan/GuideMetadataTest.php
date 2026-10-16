<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideMenuSnapshot;
use App\Models\Role;
use App\Services\GuideGenerator;
use App\Services\GuideMetadataService;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tabel metadata menu & izin (guide_menu_snapshots): garis dasar, sidik jari per aspek, deteksi perubahan. */
class GuideMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function meta(): GuideMetadataService
    {
        return app(GuideMetadataService::class);
    }

    public function test_seeder_stores_baseline_for_every_menu(): void
    {
        $rows = GuideMenuSnapshot::all()->keyBy('menu_key');
        $this->assertEqualsCanonicalizing(array_keys(Menu::ITEMS), $rows->keys()->all());
        $barang = $rows['barang'];
        $this->assertSame('Barang & Stok', $barang->label);
        $this->assertContains('kasir', $barang->roles);
        $this->assertContains('barang.impor', array_column($barang->pages, 'name'));
        foreach (['menu_hash', 'roles_hash', 'pages_hash'] as $h) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $barang->{$h});
        }
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => [], 'first_scan' => false], $this->meta()->diff());
    }

    public function test_detects_permission_change_without_saving_until_capture(): void
    {
        $kasir = Role::named('kasir');
        $kasir->update(['permissions' => [...$kasir->permissions, 'laporan']]);
        $before = GuideMenuSnapshot::where('menu_key', 'laporan')->value('roles_hash');
        $this->assertSame(['laporan' => ['roles']], $this->meta()->diff()['changed']);
        $this->assertSame($before, GuideMenuSnapshot::where('menu_key', 'laporan')->value('roles_hash'));   // diff tidak menyimpan

        $this->travel(5)->minutes();
        $this->assertSame(['laporan' => ['roles']], $this->meta()->capture()['changed']);
        $row = GuideMenuSnapshot::where('menu_key', 'laporan')->first();
        $this->assertNotSame($before, $row->roles_hash);
        $this->assertContains('kasir', $row->roles);
        $this->assertTrue($row->changed_at->equalTo($row->scanned_at));
        $this->assertSame([], $this->meta()->diff()['changed']);
        $this->assertTrue(GuideMenuSnapshot::where('menu_key', 'kasir')->first()->changed_at->lt($row->changed_at)); // menu lain tidak ditandai berubah
    }

    public function test_role_order_does_not_count_as_change(): void
    {
        $rows = GuideMenuSnapshot::all();
        $rows->each(fn ($r) => $r->update(['roles' => array_reverse($r->roles)]));
        $this->assertSame([], $this->meta()->diff()['changed']);
    }

    public function test_detects_page_changes_added_and_removed_menus(): void
    {
        GuideMenuSnapshot::where('menu_key', 'kas')->update(['pages_hash' => str_repeat('0', 64)]);
        GuideMenuSnapshot::where('menu_key', 'promo')->delete();
        GuideMenuSnapshot::create(['menu_key' => 'menu-lama', 'label' => 'Menu lama', 'route' => null, 'roles' => [], 'pages' => [],
            'menu_hash' => str_repeat('a', 64), 'roles_hash' => str_repeat('a', 64), 'pages_hash' => str_repeat('a', 64), 'scanned_at' => now()]);
        $diff = $this->meta()->capture();
        $this->assertSame(['kas' => ['pages']], $diff['changed']);
        $this->assertSame(['promo'], $diff['added']);
        $this->assertSame(['menu-lama'], $diff['removed']);
        $this->assertNull(GuideMenuSnapshot::where('menu_key', 'menu-lama')->first());
        $this->assertNotNull(GuideMenuSnapshot::where('menu_key', 'promo')->first());
    }

    public function test_first_scan_reports_no_changes(): void
    {
        GuideMenuSnapshot::query()->delete();
        $diff = $this->meta()->capture();
        $this->assertTrue($diff['first_scan']);
        $this->assertSame([[], [], []], [$diff['added'], $diff['removed'], $diff['changed']]);
        $this->assertSame(count(Menu::ITEMS), GuideMenuSnapshot::count());
    }

    public function test_hashes_match_scanner_output(): void
    {
        $current = $this->meta()->current();
        $this->assertSame(array_keys(app(GuideGenerator::class)->scan()), array_keys($current));
        $this->assertSame($current['barang']['hashes']['pages'], GuideMenuSnapshot::where('menu_key', 'barang')->value('pages_hash'));
    }
}
