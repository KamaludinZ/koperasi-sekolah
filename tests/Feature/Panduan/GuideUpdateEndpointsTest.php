<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Endpoint hasil pemindaian & draf panduan: isi, perubahan metadata, rencana, draf tertunda, pratinjau, akses. */
class GuideUpdateEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    public function test_scan_endpoint_returns_facts_roles_issues_and_changes(): void
    {
        $res = $this->getJson('/panduan/pembaruan/pemindaian')->assertOk();
        $this->assertSame(array_keys(Menu::ITEMS), array_keys($res->json('data.menus')));
        $this->assertSame(['admin', 'pengurus', 'kasir'], array_keys($res->json('data.roles')));
        $this->assertSame([], $res->json('data.issues'));
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => [], 'first_scan' => false], $res->json('data.changes'));
        $this->assertSame(count(Menu::ITEMS), $res->json('data.summary.menus'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'laporan']]);
        $this->assertSame(['laporan' => ['roles']], $this->getJson('/panduan/pembaruan/pemindaian')->json('data.changes.changed'));
    }

    public function test_drafts_endpoint_returns_plan_and_pending_drafts(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        GuideEntry::where('key', 'barang')->update(['source' => 'manual', 'title' => 'Barang versi manual']);
        app(GuideGenerator::class)->sync();                                                // menyusun draf untuk panduan manual
        $res = $this->getJson('/panduan/pembaruan/draf')->assertOk();
        $this->assertSame([], $res->json('data.plan.updated'));                             // kas sudah disusun ulang
        $this->assertContains('barang', $res->json('data.plan.skipped_manual'));
        $this->assertSame(1, $res->json('data.counts.drafts'));
        $d = $res->json('data.drafts.0');
        $this->assertSame(['barang', 'Barang versi manual', ['title'], false], [$d['key'], $d['title'], $d['changed_fields'], $d['outdated']]);
        $this->getJson('/panduan/pembaruan/draf?status=applied')->assertOk()->assertJsonPath('data.counts.drafts', 0);
        $this->getJson('/panduan/pembaruan/draf?status=aneh')->assertUnprocessable();
    }

    public function test_single_draft_preview(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        $this->getJson('/panduan/pembaruan/draf/kas')->assertOk()
            ->assertJsonPath('data.key', 'kas')->assertJsonPath('data.diff.title.changed', true)->assertJsonPath('data.diff.title.removed', ['Judul lama']);
        $this->getJson('/panduan/pembaruan/draf/tidak-ada')->assertNotFound();
        $this->getJson('/panduan/pembaruan/draf/..%2Fetc')->assertNotFound();
        $this->assertSame(0, GuideDraft::count());                                          // pratinjau tidak menyimpan
    }

    public function test_endpoints_are_for_guide_managers_only(): void
    {
        foreach (['kasir@koperasi.test', 'pengurus@koperasi.test'] as $email) {
            $this->actingAs(User::where('email', $email)->first(), 'web');
            $this->getJson('/panduan/pembaruan/pemindaian')->assertForbidden();
            $this->getJson('/panduan/pembaruan/draf')->assertForbidden();
            $this->getJson('/panduan/pembaruan/draf/kas')->assertForbidden();
        }
        auth('web')->logout();
        $this->getJson('/panduan/pembaruan/pemindaian')->assertUnauthorized();
    }
}
