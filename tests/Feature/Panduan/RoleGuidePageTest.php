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
}
