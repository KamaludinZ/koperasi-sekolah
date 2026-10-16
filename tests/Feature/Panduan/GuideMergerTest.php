<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\GuideEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GuideMerger;
use App\Services\GuideService;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Service gabungan multi-peran: satu panduan per menu, rincian per peran, menu bersama, isi tanpa kembaran. */
class GuideMergerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function viewer(string ...$roles): array
    {
        $u = User::where('email', 'kasir@koperasi.test')->first();
        $u->roles()->sync(Role::whereIn('name', $roles)->pluck('id'));

        return AuthService::toArray($u->fresh());
    }

    public function test_merge_union_per_role_breakdown_and_shared_menus(): void
    {
        $m = app(GuideMerger::class)->merge($this->viewer('kasir', 'pengurus'), app(GuideService::class)->all('petugas'));
        $kasir = Role::named('kasir')->permissions;
        $pengurus = Role::named('pengurus')->permissions;
        $union = array_values(array_unique([...$kasir, ...$pengurus]));

        $this->assertSame(array_values(array_unique($m['keys'])), $m['keys']);                  // tanpa duplikat
        $this->assertEqualsCanonicalizing([...$union, 'akun'], $m['keys']);
        $this->assertSame(array_values(array_filter(array_keys(Menu::ITEMS), fn ($k) => in_array($k, $union, true))), array_slice($m['keys'], 0, count($union)));
        $this->assertEqualsCanonicalizing($kasir, $m['by_role']['kasir']);
        $this->assertEqualsCanonicalizing($pengurus, $m['by_role']['pengurus']);
        $this->assertEqualsCanonicalizing(array_intersect($kasir, $pengurus), $m['shared']);
        $this->assertSame(count(array_intersect($kasir, $pengurus)), $m['duplicates']);
        $this->assertSame(count($kasir) + count($pengurus) - $m['duplicates'], count($m['keys']) - 1); // tanpa panduan umum
    }

    public function test_single_role_has_no_shared_menus(): void
    {
        $m = app(GuideMerger::class)->merge($this->viewer('kasir'), app(GuideService::class)->all('petugas'));
        $this->assertSame([], $m['shared']);
        $this->assertSame(0, $m['duplicates']);
        $this->assertSame(['kasir'], array_keys($m['by_role']));
    }

    public function test_all_three_roles_each_menu_once(): void
    {
        $viewer = $this->viewer('admin', 'kasir', 'pengurus');
        $m = app(GuideMerger::class)->merge($viewer, app(GuideService::class)->all('petugas'));
        $this->assertSame([...array_keys(Menu::ITEMS), 'akun'], $m['keys']);
        $this->assertContains('transaksi', $m['shared']);
        $copies = array_sum(array_map(fn ($from) => count($from) - 1, Menu::sourcesFor($viewer)));
        $this->assertSame($copies, $m['duplicates']);
    }

    public function test_dedupe_removes_twin_steps_tips_and_related_already_linked(): void
    {
        $g = app(GuideMerger::class)->dedupe([
            'menu' => 'kas', 'title' => 'T', 'summary' => 'S',
            'steps' => [['text' => 'Buka shift', 'route' => 'kas'], ['text' => '  buka   SHIFT ', 'route' => 'kas'], ['text' => 'Buka shift', 'route' => 'kas.rekap'], ['text' => 'Tutup shift']],
            'tips' => ['Hitung uang', 'hitung  uang', 'Simpan struk'],
            'related' => ['kas' => 'Kas', 'kas.rekap' => 'Rekap', 'laporan' => 'Laporan'],
        ]);
        $this->assertCount(3, $g['steps']);
        $this->assertSame(['Hitung uang', 'Simpan struk'], $g['tips']);
        $this->assertSame(['laporan' => 'Laporan'], $g['related']);
        $this->assertSame('T', $g['title']);
    }

    public function test_effective_guides_and_page_show_no_twin_links(): void
    {
        foreach (app(GuideService::class)->all('petugas') as $key => $g) {
            $this->assertSame([], array_intersect_key($g['related'], array_flip(array_filter(array_column($g['steps'], 'route')))), $key);
        }
        $entry = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($entry, 'tips', ['Satu tips', 'satu  TIPS', 'Dua tips'], null);
        $this->assertSame(['Satu tips', 'Dua tips'], app(GuideService::class)->all('petugas')['kas']['tips']);

        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
        $html = Livewire::test(Index::class)->call('open', 'kas')->html();
        $this->assertSame(1, substr_count($html, 'href="'.route('kas.shift').'"'));
    }

    public function test_api_meta_reports_shared_menus(): void
    {
        $u = User::where('email', 'kasir@koperasi.test')->first();
        $u->roles()->syncWithoutDetaching([Role::named('pengurus')->id]);
        $this->actingAs($u->fresh(), 'web');
        $meta = $this->getJson('/panduan/data')->assertOk()->json('meta');
        $this->assertEqualsCanonicalizing(array_values(array_intersect(Role::named('kasir')->permissions, Role::named('pengurus')->permissions)), $meta['shared']);
        $this->assertSame(count($meta['shared']), $meta['duplicates_merged']);
    }
}
