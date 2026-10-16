<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Halaman panduan petugas sesuai peran: ringkasan peran, saring per peran (multi-peran), penanda asal peran. */
class RoleGuidePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    private function kasirPengurus(): User
    {
        $u = User::where('email', 'kasir@koperasi.test')->first();
        $u->roles()->syncWithoutDetaching([Role::named('pengurus')->id]);

        return $u->fresh();
    }

    public function test_single_role_sees_role_summary_without_filter_or_badges(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        Livewire::test(Index::class)
            ->assertSeeHtml('data-guide-roles')->assertSeeText('Panduan untuk peran Kasir')
            ->assertSeeText(count(Role::named('kasir')->permissions).' menu')
            ->assertDontSeeHtml('data-guide-role=')->assertDontSeeHtml('data-guide-via=')
            ->set('role', 'kasir')->assertSet('role', ''); // satu peran: saringan diabaikan
    }

    public function test_multi_role_union_without_duplicates_and_via_badges(): void
    {
        $this->actingAs($this->kasirPengurus(), 'web');
        $c = Livewire::test(Index::class)->assertSeeText('Panduan untuk peran Pengurus & Kasir')
            ->assertSeeHtml('data-guide-role="kasir"')->assertSeeHtml('data-guide-role="pengurus"');
        $html = $c->html();
        $this->assertSame(1, substr_count($html, 'data-guide-card="transaksi"'));     // menu milik kedua peran: sekali
        $this->assertStringContainsString('data-guide-card="pinjaman"', $html);       // dari Pengurus
        $this->assertStringContainsString('data-guide-card="kasir"', $html);          // dari Kasir
        $c->assertSeeHtml('data-guide-via="kasir"')->assertSeeHtml('data-guide-via="pinjaman"')
            ->assertDontSeeHtml('data-guide-via="transaksi"')                            // dimiliki semua perannya
            ->call('open', 'pinjaman')->assertSeeHtml('data-guide-article-via')->assertSeeText('lewat peran Pengurus');
    }

    public function test_filter_by_role_hides_other_role_menus_and_keeps_general_guides(): void
    {
        $this->actingAs($this->kasirPengurus(), 'web');
        Livewire::test(Index::class)->set('role', 'kasir')->assertSet('role', 'kasir')
            ->assertSeeHtml('data-guide-card="kasir"')->assertSeeHtml('data-guide-card="transaksi"')
            ->assertSeeHtml('data-guide-card="akun"')
            ->assertDontSeeHtml('data-guide-card="pinjaman"')->assertDontSeeHtml('data-guide-card="laporan"')
            ->set('role', 'admin')->assertSet('role', '')                              // peran yang tidak dimiliki ditolak
            ->assertSeeHtml('data-guide-card="pinjaman"');
        $this->get('/panduan?peran=pengurus')->assertOk()->assertDontSeeHtml('data-guide-card="kasir"')->assertSeeHtml('data-guide-card="laporan"');
    }

    public function test_prev_next_skip_hidden_guides(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $html = Livewire::test(Index::class)->call('open', 'anggota')->html();
        preg_match_all('/data-guide-(?:prev|next)="([^"]+)"/', $html, $m);
        foreach ($m[1] as $k) {
            $this->assertContains($k, [...Role::named('kasir')->permissions, 'akun'], "tautan ke panduan tersembunyi: {$k}");
        }
    }

    private function admin(): User
    {
        return User::where('email', 'admin@koperasi.test')->first();
    }

    public function test_admin_previews_list_with_mock_role_permissions(): void
    {
        $this->actingAs($this->admin(), 'web');
        $c = Livewire::test(Index::class)->assertSeeHtml('data-guide-preview-controls')->assertDontSeeHtml('data-guide-preview-banner')
            ->assertSeeHtml('data-guide-card="pengguna"')
            ->call('togglePreview', 'kasir')->assertSet('preview', 'kasir')
            ->assertSeeHtml('data-guide-preview-banner')->assertSeeText('Panduan untuk peran Kasir');
        foreach (Role::named('kasir')->permissions as $menu) {
            $c->assertSeeHtml('data-guide-card="'.$menu.'"');
        }
        $c->assertDontSeeHtml('data-guide-card="pengguna"')->assertDontSeeHtml('data-guide-card="pinjaman"')
            ->assertSeeText('Tampilkan menu terbatas')
            ->call('togglePreview', 'pengurus')->assertSet('preview', 'pengurus,kasir')                   // gabungan, urut prioritas
            ->assertSeeHtml('data-guide-card="pinjaman"')->assertSeeHtml('data-guide-via="kasir"')
            ->assertSeeHtml('data-guide-role="pengurus"')
            ->call('endPreview')->assertSet('preview', '')->assertSeeHtml('data-guide-card="pengguna"');
    }

    public function test_preview_follows_current_role_permissions_and_ignores_unknown_names(): void
    {
        $kasir = Role::named('kasir');
        $kasir->update(['permissions' => ['kasir', 'transaksi']]);
        $this->actingAs($this->admin(), 'web');
        Livewire::withQueryParams(['sebagai' => 'kasir,superuser,kasir'])->test(Index::class)
            ->assertSet('preview', 'kasir')
            ->assertSeeHtml('data-guide-card="transaksi"')->assertDontSeeHtml('data-guide-card="barang"');
        $this->get('/panduan?sebagai=superuser')->assertOk()->assertDontSeeHtml('data-guide-preview-banner');
    }

    public function test_non_admin_cannot_preview_other_roles(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        Livewire::withQueryParams(['sebagai' => 'admin'])->test(Index::class)
            ->assertSet('preview', '')->assertDontSeeHtml('data-guide-preview-controls')
            ->assertDontSeeHtml('data-guide-card="pengguna"')
            ->call('togglePreview', 'admin')->assertSet('preview', '')
            ->assertDontSeeHtml('data-guide-card="pengguna"');
    }

    public function test_step_links_follow_previewed_permissions(): void
    {
        $this->actingAs($this->admin(), 'web');
        $c = Livewire::test(Index::class)->call('togglePreview', 'kasir');
        $c->set('showRestricted', true)->call('open', 'pinjaman')->assertSeeHtml('data-restricted-panel')->assertDontSeeHtml('data-guide-steps');
    }
}
