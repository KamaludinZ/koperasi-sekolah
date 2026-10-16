<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Updates;
use App\Models\GuideEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tab hasil pemindaian menu & izin di panel pembaruan panduan. */
class GuideScanPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    public function test_scanner_reports_pages_with_role_and_ability_guards(): void
    {
        $scan = app(GuideGenerator::class)->scan();
        $this->assertSame(array_keys(Menu::ITEMS), array_keys($scan));
        $impor = collect($scan['barang']['pages'])->firstWhere('name', 'barang.impor');
        $this->assertSame(['admin', 'pengurus'], $impor['roles']);
        $this->assertArrayHasKey('ability', $impor);
        $this->assertGreaterThan(0, array_sum(array_column($scan, 'skipped_pages')));    // halaman berparameter dicatat
        foreach ($scan as $f) {
            foreach ($f['pages'] as $p) {
                $this->assertStringNotContainsString('{', $p['uri']);
            }
        }
    }

    public function test_tab_shows_totals_matrix_and_pages(): void
    {
        $c = Livewire::withQueryParams(['tab' => 'pemindaian'])->test(Updates::class)->assertSet('tab', 'pemindaian')
            ->assertSeeHtml('data-guide-scan')->assertSeeHtml('data-scan-matrix')->assertSeeHtml('data-scan-menu="barang"')
            ->assertSeeHtml('data-scan-page="barang.impor"')->assertSeeText('khusus Admin');
        foreach (Role::named('kasir')->permissions as $menu) {
            $c->assertSeeHtml('data-scan-cell="'.$menu.':kasir"');
        }
        $html = $c->html();
        $this->assertMatchesRegularExpression('/data-scan-cell="pengguna:kasir">\s*(<!--\[if BLOCK\]><!\[endif\]-->)?<span class="text-stone-300"/', $html);
        $this->assertMatchesRegularExpression('/data-scan-cell="kasir:kasir">\s*(<!--\[if BLOCK\]><!\[endif\]-->)?<span class="text-emerald-700"/', $html);
        $this->assertSame(count(Menu::ITEMS), $c->instance()->scan['totals']['menus']);
        Livewire::withQueryParams(['tab' => 'aneh'])->test(Updates::class)->assertSet('tab', 'ringkasan')->assertDontSeeHtml('data-scan-matrix');
    }

    public function test_warnings_for_unassigned_menu_missing_guide_and_uncovered_pages(): void
    {
        Role::query()->get()->each(fn ($r) => $r->update(['permissions' => array_values(array_diff($r->permissions, ['promo']))]));
        GuideEntry::where('key', 'kas')->delete();
        GuideEntry::where('key', 'barang')->update(['related' => [], 'steps' => [['text' => 'Buka menu Barang', 'route' => 'barang']]]);
        $scan = Livewire::test(Updates::class)->set('tab', 'pemindaian')
            ->assertSeeHtml('data-scan-warning="promo"')->assertSeeText('Menu Diskon & Promo belum diberikan ke peran mana pun.')
            ->assertSeeHtml('data-scan-warning="kas"')->assertSeeText('belum punya panduan')
            ->assertSeeHtml('data-scan-uncovered="barang"')->assertSeeHtml('data-scan-warning="barang"')
            ->instance()->scan;
        $this->assertGreaterThan(0, $scan['totals']['uncovered']);
        $this->assertSame([], $scan['menus']['promo']['roles']);
    }

    public function test_scan_tab_is_admin_only(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->get('/panduan/pembaruan?tab=pemindaian')->assertForbidden();
    }
}
