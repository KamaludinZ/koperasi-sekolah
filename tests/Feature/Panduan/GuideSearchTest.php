<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\User;
use App\Support\GuideCatalog;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GuideSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
    }

    public function test_everyday_synonyms_find_the_right_guide(): void
    {
        foreach (['piutang' => 'anggota', 'password' => 'akun', 'supplier' => 'pengadaan', 'cicilan' => 'pinjaman', 'voucher' => 'promo', 'backup' => 'sistem', 'refund' => 'transaksi'] as $q => $key) {
            Livewire::test(Index::class)->set('search', $q)->assertSeeHtml('data-guide-item="'.$key.'"');
        }
    }

    public function test_every_staff_guide_has_keywords_and_haystack_is_normalized(): void
    {
        foreach (GuideCatalog::forAudience('petugas') as $key => $e) {
            $this->assertArrayHasKey($key, GuideCatalog::KEYWORDS, $key);
            $h = GuideCatalog::haystack($key, $e);
            $this->assertSame(mb_strtolower($h), $h);
            $this->assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $h); // ASCII (aksen dibuang)
        }
    }

    public function test_items_carry_search_text_for_instant_filtering(): void
    {
        $html = $this->get('/panduan')->assertOk()->assertSeeHtml('x-data="guideSearch(')->assertSeeHtml('data-guide-group')->getContent();
        $this->assertSame(count(GuideCatalog::forAudience('petugas')) * 2, substr_count($html, 'data-hay="')); // daftar + kartu
    }

    public function test_url_search_is_applied_on_first_load(): void
    {
        $this->get('/panduan?q=cicilan')->assertOk()->assertSeeHtml('data-guide-item="pinjaman"')->assertDontSeeHtml('data-guide-item="kasir"');
    }
}
