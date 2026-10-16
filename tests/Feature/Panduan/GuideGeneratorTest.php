<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\UserService;
use App\Support\GuideCatalog;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GuideGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private GuideGenerator $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->gen = app(GuideGenerator::class);
    }

    public function test_scan_reads_menus_pages_and_roles_from_the_system(): void
    {
        $facts = $this->gen->scan();
        $this->assertSame(array_keys(Menu::ITEMS), array_keys($facts));
        $this->assertSame(['admin', 'pengurus'], $facts['pinjaman']['roles']);
        $this->assertContains('Cadangan Data', array_column($facts['sistem']['pages'], 'title'));
        $this->assertContains('/audit/peran-impersonasi', array_column($facts['audit']['pages'], 'uri'));
        foreach ($facts as $f) {
            foreach ($f['pages'] as $p) {
                $this->assertStringNotContainsString('{', $p['uri']);
            }
        }
    }

    public function test_drafts_merge_curated_content_with_discovered_pages(): void
    {
        $d = $this->gen->drafts();
        $this->assertSame(GuideCatalog::entries()['kasir']['steps'], $d['kasir']['steps']);
        $this->assertSame('Uji Pemulihan', $d['sistem']['related']['sistem.restore']);
        $this->assertSame(['kasir'], $d['kasir']['required_permissions']);
        $this->assertSame([], $d['akun']['required_permissions']);
        $this->assertArrayHasKey('portal.belanja', $d);
        foreach ($d as $key => $g) {
            foreach (array_keys($g['related']) as $r) {
                $this->assertTrue(Route::has($r), "{$key}: {$r}");
            }
        }
    }

    public function test_menu_without_curated_content_still_gets_a_usable_draft(): void
    {
        // simulasikan menu yang belum punya isi kurasi
        $method = new \ReflectionMethod($this->gen, 'stepsFor');
        $summary = new \ReflectionMethod($this->gen, 'summaryFor');
        $f = $this->gen->scan()['pengadaan'];
        $steps = $method->invoke($this->gen, $f);
        $this->assertSame('Buka menu Pengadaan di menu samping.', $steps[0]['text']);
        $this->assertSame('pengadaan', $steps[0]['route']);
        $this->assertTrue(collect($steps)->contains(fn ($s) => str_contains($s['text'], 'Supplier')));
        $this->assertLessThanOrEqual(GuideGenerator::MAX_PAGES, count($steps));
        $this->assertStringContainsString('dapat dibuka peran Admin dan Pengurus', $summary->invoke($this->gen, $f));
    }

    public function test_sync_creates_then_only_updates_what_changed_and_versions_it(): void
    {
        $s = $this->gen->sync();
        $this->assertCount(count(GuideCatalog::entries()), $s['created']);
        $this->assertSame(GuideEntry::count(), GuideVersion::count());

        $again = $this->gen->sync();
        $this->assertSame([[], [], count(GuideCatalog::entries())], [$again['created'], $again['updated'], count($again['unchanged'])]);
        $this->assertSame(GuideEntry::count(), GuideVersion::count()); // tak ada versi baru

        // isi otomatis di tabel menyimpang (mis. diubah langsung) → disusun ulang ke draf terbaru
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul usang']);
        $third = $this->gen->sync();
        $this->assertSame(['kas'], $third['updated']);
        $this->assertSame(GuideCatalog::entries()['kas']['title'], GuideEntry::where('key', 'kas')->value('title'));
        $this->assertSame(1, GuideEntry::where('key', 'kas')->first()->versions()->count()); // isi efektif sama dengan versi terakhir → tak ada versi baru
    }

    public function test_manual_guides_are_never_overwritten(): void
    {
        $this->gen->sync();
        GuideEntry::where('key', 'kasir')->update(['title' => 'Judul manual', 'source' => 'manual']);
        $s = $this->gen->sync();
        $this->assertSame(['kasir'], $s['skipped_manual']);
        $this->assertSame('Judul manual', GuideEntry::where('key', 'kasir')->value('title'));
    }

    public function test_artisan_command(): void
    {
        $this->artisan('panduan:susun')->expectsOutputToContain(count(GuideCatalog::entries()).' baru')->assertSuccessful();
        $this->artisan('panduan:susun')->expectsOutputToContain('0 baru, 0 diperbarui')->assertSuccessful();
    }

    public function test_generated_guides_follow_role_permission_changes_in_api(): void
    {
        $this->gen->sync();
        app(UserService::class)->updatePermissions(Role::named('kasir'), [...Role::named('kasir')->permissions, 'laporan']);
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first());
        $this->assertContains('laporan', array_column($this->getJson('/panduan/data')->json('data'), 'key'));
    }
}
