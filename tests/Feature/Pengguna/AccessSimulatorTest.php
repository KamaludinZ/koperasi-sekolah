<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\AccessSimulator;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\AccessPreview;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccessSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->role($roles[0])->create();
        foreach (array_slice($roles, 1) as $r) {
            $u->roles()->attach(Role::named($r)->id, ['created_at' => now()]);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_simulation_matches_a_real_account_with_the_same_roles(): void
    {
        $real = $this->user('kasir', 'pengurus');
        $sim = (new AccessPreview)->simulate(['pengurus', 'kasir', 'kasir', 'bukan-peran']);

        $this->assertSame(['pengurus', 'kasir'], $sim['roles']); // urut prioritas, tanpa duplikat/asing
        $this->assertSame('pengurus', $sim['primary']);
        $this->assertEqualsCanonicalizing($real->permissions(), $sim['permissions']);
        $this->assertSame(['kasir'], $sim['sources']['kasir']);
        $this->assertSame(['pengurus'], $sim['sources']['pinjaman']);
        $this->assertSame(['pengurus', 'kasir'], $sim['sources']['transaksi']);
        $this->assertSame('laporan', $sim['home']); // izin peran utama (pengurus) dulu
        foreach (AccessPreview::ABILITIES as $ability => $_) {
            $this->assertSame(\Gate::forUser($real)->allows($ability), $sim['abilities'][$ability]['allowed'], $ability);
        }
        $this->assertTrue($sim['abilities']['kelola-shift']['allowed']);   // dari menu kasir
        $this->assertFalse($sim['abilities']['kelola-shift']['combined']); // kasir sendiri sudah punya
        $this->assertFalse($sim['abilities']['ubah-pengaturan']['allowed']);
    }

    public function test_combined_flag_marks_rights_no_single_role_grants(): void
    {
        // pengurus tanpa menu kasir; kasir tanpa peran pengelola → setujui retur hanya muncul bila digabung
        app(UserService::class)->updatePermissions(Role::named('pengurus'), ['laporan', 'audit']);
        $sim = (new AccessPreview)->simulate(['kasir', 'pengurus']);

        $this->assertTrue($sim['abilities']['setujui-retur']['allowed']);
        $this->assertTrue($sim['abilities']['setujui-retur']['combined']);
        $this->assertFalse((new AccessPreview)->simulate(['kasir'])['abilities']['setujui-retur']['allowed']);
        $this->assertFalse((new AccessPreview)->simulate(['pengurus'])['abilities']['setujui-retur']['allowed']);
    }

    public function test_pages_follow_real_route_middleware(): void
    {
        $pages = collect((new AccessPreview)->simulate(['kasir'])['pages'])->keyBy('name');

        $this->assertTrue($pages['kasir']['allowed']);
        $this->assertFalse($pages['pengguna']['allowed']);
        $this->assertStringContainsString('Pengguna', $pages['pengguna']['reason']);
        $this->assertFalse($pages['audit']['allowed']);
        $this->assertSame(['admin', 'pengurus'], $pages['audit']['roles']);
        $this->assertSame('/pengguna/hak-akses', $pages['pengguna.akses']['uri']);

        // klaim simulasi = kenyataan HTTP untuk akun nyata dengan peran yang sama
        $kasir = $this->user('kasir');
        $this->actingAs($kasir);
        $this->get('/kasir')->assertOk();
        $this->get('/pengguna')->assertForbidden();
        $this->get('/audit')->assertForbidden();

        $both = collect((new AccessPreview)->simulate(['kasir', 'pengurus'])['pages'])->keyBy('name');
        $this->assertTrue($both['audit']['allowed']);
        $this->assertTrue($both['kasir']['allowed']);
        $this->actingAs($this->user('kasir', 'pengurus'));
        $this->get('/audit')->assertOk();
        $this->get('/kasir')->assertOk();
    }

    public function test_empty_combination_opens_nothing(): void
    {
        $sim = (new AccessPreview)->simulate([]);
        $this->assertNull($sim['primary']);
        $this->assertSame([], $sim['permissions']);
        $this->assertSame([], $sim['menu']);
        $this->assertTrue(collect($sim['pages'])->every(fn ($p) => ! $p['allowed']));
        $this->assertTrue(collect($sim['abilities'])->every(fn ($a) => ! $a['allowed']));
    }

    public function test_page_renders_and_reacts_to_role_selection(): void
    {
        $this->user('kasir', 'pengurus');
        $this->get('/pengguna/hak-akses')->assertOk()->assertSeeText('Hak akses gabungan')->assertSeeText('Kombinasi yang dipakai akun');

        Livewire::test(AccessSimulator::class)
            ->set('roles', ['kasir'])
            ->assertSet('roles', ['kasir'])
            ->assertSeeHtml('data-menu-row="kasir"')
            ->assertSeeHtmlInOrder(['data-page="pengguna"', 'data-allowed="0"'])
            ->set('roles', ['kasir', 'pengurus', 'admin-palsu'])
            ->assertSet('roles', ['pengurus', 'kasir'])
            ->assertSeeText('Pengurus + Kasir')
            ->call('useCombination', ['admin', ['x'], 'admin'])
            ->assertSet('roles', ['admin'])
            ->set('pageFilter', 'tertutup')
            ->assertSeeText('Tidak ada halaman.')
            ->set('pageFilter', 'ngawur')
            ->assertSet('pageFilter', 'semua');
    }

    public function test_load_combination_from_account_and_url(): void
    {
        $u = $this->user('kasir', 'pengurus');
        Livewire::test(AccessSimulator::class)->call('loadUser', $u->id)->assertSet('roles', ['pengurus', 'kasir'])
            ->call('loadUser', 999999)->assertSet('roles', ['pengurus', 'kasir']);

        Livewire::withQueryParams(['peran' => ['kasir', 'palsu']])->test(AccessSimulator::class)->assertSet('roles', ['kasir']);
        Livewire::withQueryParams(['peran' => 'kasir,pengurus,x'])->test(AccessSimulator::class)->assertSet('roles', ['pengurus', 'kasir'])->assertSet('peran', 'pengurus,kasir')
            ->set('roles', ['admin'])->assertSet('peran', 'admin');
        $this->get('/pengguna/hak-akses?peran=kasir')->assertOk();
        $this->get('/pengguna/hak-akses?peran[0][x]=1&halaman=zzz')->assertOk();
    }

    public function test_only_admin_can_open_the_simulator(): void
    {
        $this->actingAs($this->user('pengurus'));
        $this->get('/pengguna/hak-akses')->assertForbidden();
        $this->actingAs($this->user('kasir'));
        $this->get('/pengguna/hak-akses')->assertForbidden();
        auth()->logout();
        $this->get('/pengguna/hak-akses')->assertRedirect(route('login'));
    }
}
