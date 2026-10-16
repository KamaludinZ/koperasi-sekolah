<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Updates;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pratinjau draf panduan per menu: beda per bagian, suntingan admin, terapkan satu draf saja. */
class GuideDraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    public function test_preview_reports_field_differences(): void
    {
        $gen = app(GuideGenerator::class);
        $same = $gen->preview('kas');
        $this->assertFalse(collect($same['diff'])->contains(fn ($d) => $d['changed']));

        $steps = GuideEntry::where('key', 'kas')->value('steps');
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama', 'steps' => [...array_slice($steps, 1), ['text' => 'Langkah usang']]]);
        $p = $gen->preview('kas');
        $this->assertTrue($p['diff']['title']['changed']);
        $this->assertSame(['Judul lama'], $p['diff']['title']['removed']);
        $this->assertContains('Langkah usang', $p['diff']['steps']['removed']);
        $this->assertCount(1, $p['diff']['steps']['added']);
        $this->assertFalse($p['diff']['tips']['changed']);
        $this->assertNull($gen->preview('tidak-ada'));

        GuideEntry::where('key', 'promo')->delete();
        $new = $gen->preview('promo');
        $this->assertNull($new['stored']);
        $this->assertTrue($new['diff']['title']['changed']);
    }

    public function test_tab_renders_diff_and_override_note(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        app(GuideService::class)->setOverride(GuideEntry::where('key', 'kas')->first(), 'summary', 'Ringkasan suntingan admin untuk kas', null);
        Livewire::withQueryParams(['tab' => 'draf', 'panduan' => 'kas'])->test(Updates::class)
            ->assertSet('draftKey', 'kas')->assertSeeHtml('data-guide-draft')
            ->assertSeeHtml('data-draft-field="title" data-changed="1"')->assertSeeHtml('data-draft-old')
            ->assertSeeHtml('data-draft-field="tips" data-changed="0"')
            ->assertSeeHtml('data-guide-draft-overridden')->assertSeeText('Berubah: judul')
            ->assertSeeHtml('data-guide-draft-apply');
        Livewire::withQueryParams(['tab' => 'draf', 'panduan' => 'tidak-ada'])->test(Updates::class)->assertSet('draftKey', 'kasir');
    }

    public function test_apply_single_draft_only_touches_that_guide(): void
    {
        GuideEntry::whereIn('key', ['kas', 'barang'])->update(['title' => 'Judul lama']);
        $versions = GuideVersion::count();
        Livewire::withQueryParams(['tab' => 'draf', 'panduan' => 'kas'])->test(Updates::class)
            ->call('applyDraft')->assertSet('draftApplied', 'diperbarui')->assertSeeHtml('data-guide-draft-applied')
            ->assertDontSeeHtml('data-guide-draft-apply');
        $this->assertNotSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));
        $this->assertSame('Judul lama', GuideEntry::where('key', 'barang')->value('title'));
        $this->assertSame($versions, GuideVersion::count()); // isi kembali sama dengan versi terakhir → tidak dicatat dua kali
        $this->assertSame(1, ActivityLog::where('event', 'guide.synced')->count());
        $this->assertSame(['barang'], array_keys(app(GuideGenerator::class)->plan()['updated']));
    }

    public function test_manual_guide_draft_not_applied(): void
    {
        GuideEntry::where('key', 'kas')->update(['source' => 'manual', 'title' => 'Versi manual']);
        Livewire::withQueryParams(['tab' => 'draf', 'panduan' => 'kas'])->test(Updates::class)
            ->assertSeeText('Panduan manual')->assertDontSeeHtml('data-guide-draft-apply')
            ->call('applyDraft')->assertSet('draftApplied', 'dilewati (manual)');
        $this->assertSame('Versi manual', GuideEntry::where('key', 'kas')->value('title'));
        $this->assertSame(0, ActivityLog::where('event', 'guide.synced')->count());
    }

    public function test_draft_tab_requires_manager(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $this->get('/panduan/pembaruan?tab=draf&panduan=kas')->assertForbidden();
    }
}
