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

    public function test_active_version_marker(): void
    {
        $this->actingAs($this->admin, 'web');
        $kas = GuideEntry::where('key', 'kas')->first();
        // v3 (dikembalikan) sama isinya dengan v1 & isi sekarang → v3 aktif (yang terbaru dari yang sama)
        $html = Livewire::withQueryParams(['panduan' => 'kas'])->test(Versions::class)->assertSeeText('versi aktif v3')->html();
        $this->assertMatchesRegularExpression('/data-version="'.$kas->id.':3"\s+data-version-active/', $html);
        $this->assertSame(1, substr_count($html, 'data-version-active'));

        GuideEntry::whereKey($kas->id)->update(['title' => 'Diubah di luar alur versi']);
        Livewire::withQueryParams(['panduan' => 'kas'])->test(Versions::class)->assertSeeHtml('data-versions-no-active')->assertDontSeeHtml('data-version-active');

        $all = Livewire::withQueryParams([])->test(Versions::class)->html();                          // semua panduan: satu aktif per panduan
        $this->assertGreaterThan(0, substr_count($all, 'data-version-active'));
    }

    public function test_compare_two_versions(): void
    {
        $this->actingAs($this->admin, 'web');
        $c = Livewire::withQueryParams(['panduan' => 'kas'])->test(Versions::class)
            ->call('compareWithPrevious', 2)->assertSet('from', 1)->assertSet('to', 2)
            ->assertSeeHtml('data-versions-compare="1-2"')->assertSeeHtml('data-compare-field="title"')->assertDontSeeHtml('data-compare-field="steps"')
            ->assertSeeText('Judul kas versi kami')->assertSeeHtml('data-compare-overridden')->assertSeeText('Mulai disunting manual: judul');
        $c->call('closeCompare')->assertSet('from', 0)->assertDontSeeHtml('data-versions-compare=');
        $c->call('pick', 'ke', 3)->assertSeeHtml('data-compare-pending')->call('pick', 'dari', 1)
            ->assertSeeHtml('data-versions-compare="1-3"')->assertSeeHtml('data-compare-same');               // v3 dikembalikan = v1
        $c->call('pick', 'dari', 3)->call('pick', 'ke', 2)->assertSet('from', 2)->assertSet('to', 3);          // urutan dirapikan
        Livewire::withQueryParams(['panduan' => 'kas', 'dari' => 1, 'ke' => 99])->test(Versions::class)->assertDontSeeHtml('data-versions-compare=');
        Livewire::withQueryParams(['panduan' => '', 'dari' => 1, 'ke' => 2])->test(Versions::class)->assertSet('from', 0);
    }
}
