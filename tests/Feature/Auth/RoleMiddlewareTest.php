<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, UserSeeder::class]);
    }

    private function user(string $email): User
    {
        return User::firstWhere('email', $email);
    }

    public function test_permissions_come_from_roles_table(): void
    {
        $pengurus = $this->user('pengurus@koperasi.test');
        $this->actingAs($pengurus)->get('/laporan')->assertOk();

        $role = Role::named('pengurus');
        $role->update(['permissions' => array_values(array_diff($role->permissions, ['laporan']))]);

        $this->actingAs($pengurus->fresh()); // setiap request nyata memuat ulang akun & peran
        $this->get('/laporan')->assertForbidden();
        $this->get('/transaksi')->assertOk()->assertDontSee(route('laporan'), false); // menu ikut hilang
        $this->assertFalse(Gate::forUser($pengurus->fresh())->allows('menu', 'laporan'));
        $this->assertTrue(Gate::forUser($pengurus->fresh())->allows('menu', 'audit'));
    }

    public function test_json_endpoints_are_guarded_per_menu(): void
    {
        $this->getJson('/barang/daftar')->assertUnauthorized();

        $this->actingAs($this->user('kasir@koperasi.test'));
        $this->getJson('/barang/daftar')->assertOk();
        $this->getJson('/laporan/hari-ini')->assertForbidden();
        $this->getJson('/laporan/unduh?dari=2026-10-01&sampai=2026-10-02')->assertForbidden();

        $this->actingAs($this->user('pengurus@koperasi.test'));
        $this->postJson('/kasir/transaksi', [])->assertForbidden(); // pengurus tidak boleh berjualan
        $this->getJson('/laporan/hari-ini')->assertOk();
    }

    public function test_role_middleware(): void
    {
        Route::middleware(['web', 'peran:admin,pengurus'])->get('/_uji-peran', fn () => 'ok');

        $this->getJson('/_uji-peran')->assertUnauthorized();
        $this->actingAs($this->user('kasir@koperasi.test'))->get('/_uji-peran')
            ->assertForbidden()->assertSee('Halaman ini hanya untuk peran Admin dan Pengurus.');
        $this->actingAs($this->user('pengurus@koperasi.test'))->get('/_uji-peran')->assertOk();

        $this->actingAs($this->user('pengurus@koperasi.test'))->get('/pengguna')->assertForbidden();
        $this->actingAs($this->user('admin@koperasi.test'))->get('/audit')->assertOk();
    }

    public function test_livewire_actions_rerun_route_guards(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(EnsureMenuAccess::class, $persistent);
        $this->assertContains(EnsureRole::class, $persistent);
    }

    public function test_account_pages_need_login_only(): void
    {
        $this->get('/akun/kata-sandi')->assertRedirect(route('login'));
        $this->actingAs($this->user('kasir@koperasi.test'))->get('/akun/sesi')->assertOk();
    }
}
