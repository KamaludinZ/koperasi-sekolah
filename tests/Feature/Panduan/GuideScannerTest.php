<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Updates;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\GuideScanner;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Layanan pemindai menu & izin sistem: fakta menu, izin per peran, temuan konfigurasi, perintah panduan:pindai. */
class GuideScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function scanner(): GuideScanner
    {
        return app(GuideScanner::class);
    }

    public function test_scan_covers_every_menu_and_generator_delegates(): void
    {
        $facts = $this->scanner()->scan();
        $this->assertSame(array_keys(Menu::ITEMS), array_keys($facts));
        $this->assertSame($facts, app(GuideGenerator::class)->scan());
        foreach ($facts as $key => $f) {
            $this->assertSame(Menu::grantingRoles($key), $f['roles'], $key);
        }
    }

    public function test_roles_report_known_and_unknown_permissions(): void
    {
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'gudang-rahasia']]);
        $roles = $this->scanner()->roles();
        $this->assertSame(['admin', 'pengurus', 'kasir'], array_keys($roles));
        $this->assertSame(['gudang-rahasia'], $roles['kasir']['unknown']);
        $this->assertNotContains('gudang-rahasia', $roles['kasir']['menus']);
        $this->assertSame([], $roles['admin']['unknown']);
    }

    public function test_clean_configuration_has_no_issues(): void
    {
        $this->assertSame([], $this->scanner()->issues());
        $s = $this->scanner()->summary();
        $this->assertSame([count(Menu::ITEMS), 3, 0], [$s['menus'], $s['roles'], $s['issues']]);
        $this->assertGreaterThan(0, $s['pages']);
    }

    public function test_issues_for_unknown_permission_and_unassigned_menu(): void
    {
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'gudang-rahasia']]);
        Role::query()->get()->each(fn ($r) => $r->update(['permissions' => array_values(array_diff($r->permissions, ['promo']))]));
        $codes = array_column($this->scanner()->issues(), 'code', 'text');
        $this->assertContains('izin_tidak_dikenal', $codes);
        $this->assertContains('menu_tanpa_peran', $codes);
        $this->assertArrayHasKey('Peran Kasir memuat izin “gudang-rahasia” yang tidak ada di daftar menu.', $codes);

        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
        Livewire::withQueryParams(['tab' => 'pemindaian'])->test(Updates::class)->assertSeeText('gudang-rahasia');
    }

    public function test_command_outputs_summary_json_and_strict_exit_code(): void
    {
        $this->artisan('panduan:pindai')->expectsOutputToContain('menu, ')->assertExitCode(0);
        $this->artisan('panduan:pindai --ketat')->assertExitCode(0);
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'gudang-rahasia']]);
        $this->artisan('panduan:pindai --ketat')->expectsOutputToContain('gudang-rahasia')->assertExitCode(1);
        $this->artisan('panduan:pindai --json')->expectsOutputToContain('"issues"')->assertExitCode(0);
    }
}
