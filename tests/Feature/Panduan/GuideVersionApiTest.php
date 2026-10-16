<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Endpoint daftar versi panduan per menu. */
class GuideVersionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'title', 'Judul kas versi kami', $this->admin, 'Istilah lokal'); // v2
        app(GuideService::class)->clearOverride($kas, 'title', $this->admin);                                     // v3 (= v1)
    }

    public function test_versions_for_one_menu(): void
    {
        $this->actingAs($this->admin, 'web');
        $res = $this->getJson('/panduan/versi/data/kas')->assertOk();
        $this->assertSame(['kas', 3, 3, 3], [$res->json('meta.guide.key'), $res->json('meta.active_version'), $res->json('meta.latest_version'), $res->json('meta.total')]);
        $this->assertSame([3, 2, 1], array_column($res->json('data'), 'version'));
        $this->assertSame([true, false, false], array_column($res->json('data'), 'active'));
        $v2 = $res->json('data.1');
        $this->assertSame(['override', 'Istilah lokal', $this->admin->name, ['title', 'overridden'], false], [$v2['type'], $v2['note'], $v2['by'], $v2['changed'], $v2['first']]);
        $this->assertTrue($res->json('data.2.first'));
        $this->assertSame(['generate' => 1, 'override' => 1, 'revert' => 1, 'restore' => 0], $res->json('meta.type_counts'));
        $this->assertSame([2], array_column($this->getJson('/panduan/versi/data/kas?jenis=override')->json('data'), 'version'));
        $this->getJson('/panduan/versi/data/kas?jenis=aneh')->assertUnprocessable();
        $this->getJson('/panduan/versi/data/tidak-ada')->assertNotFound();
    }

    public function test_single_version_with_snapshot_and_all_guides_index(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->getJson('/panduan/versi/data/kas/2')->assertOk()->assertJsonPath('data.snapshot.title', 'Judul kas versi kami')
            ->assertJsonPath('data.active', false)->assertJsonPath('data.changed', ['title', 'overridden']);
        $this->getJson('/panduan/versi/data/kas/99')->assertNotFound();
        $this->getJson('/panduan/versi/data/barang/2')->assertNotFound();                           // versi milik panduan lain tidak bocor
        $all = $this->getJson('/panduan/versi/data?per=5')->assertOk();
        $this->assertSame(GuideVersion::count(), $all->json('meta.total'));
        $this->assertSame('kas', $all->json('data.0.guide.key'));
    }

    public function test_access(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->getJson('/panduan/versi/data/kas')->assertOk();
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $this->getJson('/panduan/versi/data')->assertForbidden();
        $this->getJson('/panduan/versi/data/kas/1')->assertForbidden();
    }

    public function test_compare_two_versions_and_with_current(): void
    {
        $this->actingAs($this->admin, 'web');
        $d = $this->getJson('/panduan/versi/data/kas/banding?dari=1&ke=2')->assertOk()->json('data');
        $kas = GuideEntry::where('key', 'kas')->first();
        $this->assertSame([1, 2, false, ['title']], [$d['from']['version'], $d['to']['version'], $d['identical'], $d['changed']]);
        $this->assertSame([$kas->title, 'Judul kas versi kami'], [$d['fields']['title']['old'], $d['fields']['title']['new']]);
        $this->assertSame(['added' => ['title'], 'removed' => []], $d['overridden']);
        $this->assertSame($this->admin->name, $d['to']['by']);

        $this->assertTrue($this->getJson('/panduan/versi/data/kas/banding?dari=1&ke=3')->json('data.identical'));
        $this->assertTrue($this->getJson('/panduan/versi/data/kas/banding?dari=3&ke=sekarang')->json('data.identical'));
        $now = $this->getJson('/panduan/versi/data/kas/banding?dari=2&ke=sekarang')->json('data');
        $this->assertSame([null, 'Isi sekarang', ['title']], [$now['to']['version'], $now['to']['label'], $now['changed']]);

        $this->getJson('/panduan/versi/data/kas/banding?dari=1&ke=99')->assertNotFound();
        $this->getJson('/panduan/versi/data/kas/banding?dari=1')->assertUnprocessable();
        $this->getJson('/panduan/versi/data/kas/banding?dari=1&ke=kemarin')->assertUnprocessable();
        $this->getJson('/panduan/versi/data/barang/banding?dari=1&ke=2')->assertNotFound();      // barang hanya punya v1
    }
}
