<?php

namespace Tests\Feature\Database;

use App\Models\Role;
use App\Models\User;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null; // hitungan akun diuji dari nol

    public function test_default_roles_exist_with_menu_permissions(): void
    {
        $this->assertSame(['admin', 'kasir', 'pengurus'], Role::orderBy('name')->pluck('name')->all());
        foreach (Menu::DEFAULT_PERMISSIONS as $name => $menus) {
            $this->assertSame($menus, Role::named($name)->permissions);
        }
        $this->assertTrue(Role::named('pengurus')->allows('audit'));
        $this->assertFalse(Role::named('kasir')->allows('laporan'));
    }

    public function test_user_role_helpers_and_password_hashing(): void
    {
        $kasir = User::factory()->create(['password' => 'Rahasia123']);
        $admin = User::factory()->admin()->create();
        $off = User::factory()->role('pengurus')->inactive()->create();

        $this->assertTrue($kasir->hasRole('kasir'));
        $this->assertTrue($kasir->canOpen('kasir'));
        $this->assertFalse($kasir->canOpen('pengguna'));
        $this->assertTrue($admin->canOpen('pengguna'));
        $this->assertFalse($off->canOpen('laporan')); // nonaktif
        $this->assertTrue(Hash::check('Rahasia123', $kasir->password));
        $this->assertNotSame('Rahasia123', $kasir->getRawOriginal('password'));
        $this->assertSame(2, User::active()->count());
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        User::factory()->create();

        $this->expectException(QueryException::class);
        Role::named('kasir')->delete();
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $this->assertSame(4, User::count());
        $this->assertSame('pengurus', User::firstWhere('email', 'pengurus@koperasi.test')->roleName());
        $this->assertFalse(User::firstWhere('email', 'nonaktif@koperasi.test')->is_active);
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('koperasi:buat-admin', ['--name' => 'Ustadz Hasan', '--email' => 'Hasan@Madrasah.sch.id'])
            ->expectsQuestion('Kata sandi (min. 8 karakter, huruf & angka)', 'Amanah2026')
            ->expectsQuestion('Ulangi kata sandi', 'Amanah2026')
            ->expectsOutputToContain('Akun admin dibuat: hasan@madrasah.sch.id')
            ->assertSuccessful();

        $u = User::firstWhere('email', 'hasan@madrasah.sch.id');
        $this->assertTrue($u->hasRole('admin'));
        $this->assertTrue(Hash::check('Amanah2026', $u->password));

        $this->artisan('koperasi:buat-admin', ['--name' => 'X', '--email' => 'x@y.test'])
            ->expectsQuestion('Kata sandi (min. 8 karakter, huruf & angka)', 'pendek')
            ->expectsQuestion('Ulangi kata sandi', 'pendek')
            ->assertFailed();
    }
}
