<?php

namespace Tests\Feature\Panduan;

use App\Services\GuideDrafter;
use App\Services\GuideScanner;
use App\Support\GuideCatalog;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Layanan penyusun draf panduan otomatis: cakupan halaman, urutan langkah, tips otomatis, kata kunci, isi kurasi. */
class GuideDrafterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    private function drafter(): GuideDrafter
    {
        return app(GuideDrafter::class);
    }

    public function test_every_scanned_page_is_linked_in_steps_or_related_without_duplicates(): void
    {
        foreach (app(GuideScanner::class)->scan() as $key => $f) {
            $d = $this->drafter()->draft($key, $f, GuideCatalog::entries()[$key] ?? null);
            $steps = array_filter(array_column($d['steps'], 'route'));
            $linked = [...$steps, ...array_keys($d['related']), $f['route']];
            if (count($f['pages']) <= GuideDrafter::MAX_RELATED) {
                foreach ($f['pages'] as $p) {
                    $this->assertContains($p['name'], $linked, "{$key}: {$p['name']} tidak tertaut");
                }
            }
            $this->assertSame([], array_intersect(array_keys($d['related']), $steps), "{$key}: tautan ganda");
            $this->assertLessThanOrEqual(GuideDrafter::MAX_RELATED, count($d['related']) - count(GuideCatalog::entries()[$key]['related'] ?? []));
        }
    }

    public function test_uncurated_menu_orders_shared_pages_before_role_only_and_adds_tips(): void
    {
        $f = app(GuideScanner::class)->scan()['barang'];
        $d = $this->drafter()->draft('barang', $f);
        $this->assertSame('Panduan menu Barang & Stok', $d['title']);
        $texts = array_column($d['steps'], 'text');
        $firstOnly = collect($texts)->search(fn ($t) => str_contains($t, '(khusus'));
        $lastShared = collect($texts)->filter(fn ($t, $i) => $i > 0 && ! str_contains($t, '(khusus'))->keys()->last();
        $this->assertNotFalse($firstOnly);
        $this->assertLessThan($firstOnly, $lastShared);                                  // halaman umum dulu
        $this->assertStringContainsString('Impor Barang (Admin / Pengurus)', implode(' ', $d['tips']));
        $this->assertStringContainsString('Terdiri dari', $d['summary']);
        $this->assertContains('Perubahan data di menu ini tercatat di Audit & Riwayat.', $d['tips']);
    }

    public function test_curated_content_kept_and_keywords_include_page_titles(): void
    {
        $f = app(GuideScanner::class)->scan()['kas'];
        $cur = GuideCatalog::entries()['kas'];
        $d = $this->drafter()->draft('kas', $f, $cur);
        $this->assertSame([$cur['title'], $cur['summary'], $cur['steps'], $cur['tips']], [$d['title'], $d['summary'], $d['steps'], $d['tips']]);
        $this->assertStringStartsWith(GuideCatalog::KEYWORDS['kas'], $d['keywords']);
        foreach ($f['pages'] as $p) {
            $this->assertStringContainsString(mb_strtolower($p['title']), $d['keywords']);
        }
    }

    public function test_unassigned_menu_summary_says_so(): void
    {
        $f = app(GuideScanner::class)->scan()['promo'];
        $f['roles'] = [];
        $this->assertStringContainsString('belum diberikan ke peran mana pun', $this->drafter()->draft('promo', $f)['summary']);
    }

    public function test_general_guides_come_from_catalog(): void
    {
        $g = $this->drafter()->general();
        $this->assertArrayHasKey('akun', $g);
        $this->assertArrayHasKey('portal.beranda', $g);
        $this->assertSame([], $g['akun']['required_permissions']);
    }
}
