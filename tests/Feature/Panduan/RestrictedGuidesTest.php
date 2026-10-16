<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestrictedGuidesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    private function kasir(): User
    {
        return User::where('email', 'kasir@koperasi.test')->first();
    }

    public function test_default_list_contains_only_menus_the_user_can_open(): void
    {
        $this->actingAs($this->kasir());
        $c = Livewire::test(Index::class);
        foreach (Role::named('kasir')->permissions as $menu) {
            $c->assertSeeHtml('data-guide-card="'.$menu.'"');
        }
        $c->assertSeeHtml('data-guide-card="akun"')                       // panduan umum selalu ada
            ->assertDontSeeHtml('data-guide-card="pinjaman"')->assertDontSeeHtml('data-guide-card="pengguna"')
            ->assertSeeText('Tampilkan menu terbatas ('.(14 - count(Role::named('kasir')->permissions)).')');
    }

    public function test_restricted_menus_are_marked_and_show_no_steps(): void
    {
        $this->actingAs($this->kasir());
        Livewire::test(Index::class)->set('showRestricted', true)
            ->assertSeeHtml('data-restricted-card="pinjaman"')->assertSeeHtml('data-restricted="pinjaman"')
            ->assertDontSeeHtml('data-restricted="kasir"')
            ->call('open', 'pinjaman')
            ->assertSeeHtml('data-restricted-panel')
            ->assertSeeText('Menu Pinjaman tidak termasuk peran Anda')
            ->assertSeeText('Admin dan Pengurus')
            ->assertDontSeeHtml('data-guide-steps')
            ->assertDontSeeText('Catat pengajuan pinjaman');
    }

    public function test_restricted_guide_cannot_be_opened_while_hidden_and_deep_link_shows_panel(): void
    {
        $this->actingAs($this->kasir());
        Livewire::test(Index::class)->call('open', 'pinjaman')->assertSet('selected', '');
        $this->get('/panduan?menu=pinjaman')->assertOk()->assertSeeHtml('data-restricted-panel')->assertDontSeeText('Catat pengajuan pinjaman');
    }

    public function test_multi_role_user_gets_union_without_duplicates(): void
    {
        $u = $this->kasir();
        $u->roles()->attach(Role::named('pengurus')->id);
        $this->actingAs($u->fresh(['role', 'roles']));
        $html = $this->get('/panduan')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-guide-card="pinjaman"'));  // dari pengurus
        $this->assertSame(1, substr_count($html, 'data-guide-card="kasir"'));     // dari kasir
        $this->assertStringNotContainsString('data-guide-card="pengguna"', $html); // tak satu pun
    }

    public function test_admin_sees_everything_without_toggle(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        Livewire::test(Index::class)->assertDontSeeHtml('data-restricted-toggle')->assertSeeHtml('data-guide-card="pengguna"')->assertSet('showRestricted', false);
    }
}
