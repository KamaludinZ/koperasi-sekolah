<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\Member;
use App\Models\User;
use App\Support\GuideCatalog;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class GuideCenterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
    }

    public function test_catalog_covers_every_menu_with_valid_content_and_routes(): void
    {
        $entries = GuideCatalog::entries();
        foreach (array_keys(Menu::ITEMS) as $menu) {
            $this->assertArrayHasKey($menu, $entries, "panduan untuk menu {$menu}");
        }
        foreach ($entries as $key => $e) {
            $this->assertNotSame('', trim($e['title']), $key);
            $this->assertGreaterThanOrEqual(40, mb_strlen($e['summary']), $key);
            $this->assertGreaterThanOrEqual(3, count($e['steps']), $key);
            foreach ($e['steps'] as $s) {
                if (isset($s['route'])) {
                    $this->assertTrue(Route::has($s['route']), "{$key}: rute {$s['route']} tidak ada");
                }
            }
            foreach (array_keys($e['related']) as $r) {
                $this->assertTrue(Route::has($r), "{$key}: rute terkait {$r} tidak ada");
            }
        }
    }

    public function test_page_lists_guides_grouped_and_shows_first_guide(): void
    {
        $this->get('/panduan')->assertOk()
            ->assertSeeText('Pusat Panduan')
            ->assertSeeText('Layanan harian')->assertSeeText('Data koperasi')->assertSeeText('Umum')
            ->assertSeeHtml('data-guide="kasir"')
            ->assertSeeText('Melayani penjualan di layar Kasir')
            ->assertSeeHtmlInOrder(['data-guide-steps', 'Buka shift'])
            ->assertSeeHtml('data-guide-tips');
    }

    public function test_open_guide_and_deep_link(): void
    {
        Livewire::test(Index::class)->call('open', 'pinjaman')
            ->assertSet('selected', 'pinjaman')->assertSeeText('Siklus pinjaman anggota')
            ->call('open', 'bukan-menu')->assertSet('selected', '');
        $this->get('/panduan?menu=simpanan')->assertOk()->assertSeeHtml('data-guide="simpanan"');
        $this->get('/panduan?menu=ngawur&q[]=x')->assertOk();
    }

    public function test_search_matches_title_steps_and_tips_case_and_accent_insensitive(): void
    {
        Livewire::test(Index::class)
            ->set('search', 'TUTUP SHIFT')->assertSeeHtml('data-guide-item="kas"')->assertSeeHtml('data-guide-item="kasir"')
            ->assertDontSeeHtml('data-guide-item="pinjaman"')
            ->set('search', 'barcode')->assertSeeHtml('data-guide-item="barang"')
            ->set('search', 'tidakadakatainisamasekali')->assertSeeHtml('data-guide-empty');
    }

    public function test_open_links_only_for_reachable_pages(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first());
        Livewire::test(Index::class)->call('open', 'pinjaman')
            ->assertSeeText('Siklus pinjaman anggota')
            ->assertDontSeeHtml('href="'.route('pinjaman.pengajuan').'"'); // kasir tak punya menu Pinjaman
        Livewire::test(Index::class)->call('open', 'kasir')->assertSeeHtml('href="'.route('kasir').'"');
    }

    public function test_requires_staff_login(): void
    {
        auth()->logout();
        $this->get('/panduan')->assertRedirect(route('login'));
        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->actingAs($m->fresh(), 'member')->get('/panduan')->assertRedirect(route('login'));
    }
}
