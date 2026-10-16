<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API gabungan hasil generate dengan lapisan override (asal tiap bagian). */
class GuideMergedApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin, 'web');
    }

    public function test_single_guide_sections_provenance(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'summary', 'Ringkasan kas versi koperasi kami', $this->admin, 'Istilah lokal');
        $d = $this->getJson('/panduan/pembaruan/gabung/kas')->assertOk()->json('data');
        $this->assertSame('Ringkasan kas versi koperasi kami', $d['merged']['summary']);
        $this->assertSame($kas->title, $d['merged']['title']);
        $s = $d['sections']['summary'];
        $this->assertSame(['override', 'Ringkasan kas versi koperasi kami', $kas->summary, $this->admin->name, 'Istilah lokal', false],
            [$s['source'], $s['value'], $s['auto'], $s['updated_by'], $s['note'], $s['auto_changed']]);
        $this->assertSame(['auto', null, null], [$d['sections']['title']['source'], $d['sections']['title']['override'], $d['sections']['title']['auto_changed']]);
        $this->assertSame(['summary'], $d['overridden']);
        $this->assertSame(['title', 'summary', 'steps', 'tips'], array_keys($d['sections']));

        $kas->update(['summary' => 'Isi otomatis baru']);
        $this->assertTrue($this->getJson('/panduan/pembaruan/gabung/kas')->json('data.sections.summary.auto_changed'));
        $this->getJson('/panduan/pembaruan/gabung/tidak-ada')->assertNotFound();
    }

    public function test_index_with_filters(): void
    {
        app(GuideService::class)->setOverride(GuideEntry::where('key', 'kas')->first(), 'title', 'Judul kas versi kami', $this->admin);
        $res = $this->getJson('/panduan/pembaruan/gabung')->assertOk();
        $this->assertSame(GuideEntry::count(), $res->json('data.total'));
        $this->assertSame(1, $res->json('data.overridden_guides'));
        $only = $this->getJson('/panduan/pembaruan/gabung?disunting=1')->json('data.guides');
        $this->assertSame([['kas', 'Judul kas versi kami', ['title'], []]], array_map(fn ($g) => [$g['key'], $g['title'], $g['overridden'], $g['auto_changed']], $only));
        $this->assertSame(['anggota'], array_values(array_unique(array_column($this->getJson('/panduan/pembaruan/gabung?audience=anggota')->json('data.guides'), 'audience'))));
        $this->getJson('/panduan/pembaruan/gabung?audience=aneh')->assertUnprocessable();
    }

    public function test_managers_only(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $this->getJson('/panduan/pembaruan/gabung')->assertForbidden();
        $this->getJson('/panduan/pembaruan/gabung/kas')->assertForbidden();
    }
}
