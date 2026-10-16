<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Editor;
use App\Livewire\Panduan\Updates;
use App\Livewire\Panduan\Versions;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Halaman utama riwayat versi panduan. */
class GuideVersionsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'title', 'Judul kas versi kami', $this->admin, 'Istilah lokal');
        app(GuideService::class)->clearOverride($kas, 'title', $this->admin);
    }

    public function test_all_guides_latest_versions(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get('/panduan/versi')->assertOk()->assertSeeText('Riwayat Versi Panduan');
        Livewire::test(Versions::class)->assertSeeHtml('data-versions-open="kas"')
            ->assertSeeText('Semua ('.GuideVersion::count().')')->assertSeeText('Disunting manual (1)');
    }

    public function test_single_guide_versions_with_changed_fields(): void
    {
        $this->actingAs($this->admin, 'web');
        $kasId = GuideEntry::where('key', 'kas')->value('id');
        Livewire::withQueryParams(['panduan' => 'kas'])->test(Versions::class)
            ->assertSeeHtml('data-versions-entry')->assertSeeText('3 versi')
            ->assertSeeHtml('data-version="'.$kasId.':3"')->assertSeeHtml('data-version="'.$kasId.':1"')
            ->assertSeeText('Versi awal')->assertSeeText('Berubah: judul, penanda suntingan')->assertSeeText('Istilah lokal')
            ->set('type', 'override')->assertSeeHtml('data-version="'.$kasId.':2"')->assertDontSeeHtml('data-version="'.$kasId.':3"')
            ->set('type', 'aneh')->assertSet('type', '')
            ->set('key', 'tidak-ada')->assertSet('key', '');
    }

    public function test_links_and_access(): void
    {
        $this->actingAs($this->admin, 'web');
        Livewire::withQueryParams(['panduan' => 'kas'])->test(Editor::class)->assertSeeHtml('data-editor-all-versions');
        Livewire::test(Updates::class)->assertSeeHtml('data-updates-versions-link');

        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');      // pemegang menu Audit: baca saja
        $this->get('/panduan/versi')->assertOk()->assertDontSeeHtml('data-versions-editor-link');
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $this->get('/panduan/versi')->assertForbidden();
    }
}
