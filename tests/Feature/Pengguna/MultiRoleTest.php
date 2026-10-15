<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class MultiRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pivot_exists_and_existing_accounts_have_their_role(): void
    {
        $admin = $this->loginAs('admin');
        $this->assertTrue(Schema::hasColumns('user_roles', ['user_id', 'role_id', 'created_at']));
        $this->assertSame(['admin'], $admin->fresh()->roleNames());
        $this->assertSame(User::count(), DB::table('user_roles')->count()); // setiap akun seeder punya baris pivot
        $kasir = User::factory()->role('kasir')->create();
        $this->assertSame(['kasir'], $kasir->roleNames());
    }

    public function test_admin_creates_account_with_several_roles(): void
    {
        $this->loginAs('admin');
        Livewire::test(Index::class)->call('create')
            ->assertSeeHtml('data-role-option="admin"')->assertSeeHtml('data-role-option="pengurus"')
            ->set('form.name', 'Pak Rangkap')->set('form.email', 'rangkap@koperasi.test')
            ->set('form.password', 'rahasia123')->set('form.password_confirmation', 'rahasia123')
            ->set('form.roles', ['kasir', 'pengurus'])
            ->assertSeeHtml('data-union-menus')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Akun Pak Rangkap dibuat sebagai Pengurus + Kasir.')
            ->assertSeeHtml('data-user-roles="pengurus,kasir"')
            ->assertSeeHtml('data-multi-count');

        $u = User::where('email', 'rangkap@koperasi.test')->sole();
        $this->assertSame(['pengurus', 'kasir'], $u->roleNames());
        $this->assertSame('pengurus', $u->role->name); // peran utama = prioritas tertinggi
        $log = ActivityLog::where('event', 'user.created')->latest('id')->first();
        $this->assertSame(['pengurus', 'kasir'], $log->properties['roles']);
    }

    public function test_at_least_one_role_required_and_unknown_rejected(): void
    {
        $this->loginAs('admin');
        Livewire::test(Index::class)->call('create')
            ->set('form.name', 'Tanpa Peran')->set('form.email', 'kosong@koperasi.test')
            ->set('form.password', 'rahasia123')->set('form.password_confirmation', 'rahasia123')
            ->set('form.roles', [])->assertSeeHtml('data-roles-empty')
            ->call('save')->assertHasErrors('form.roles')
            ->set('form.roles', ['kasir', 'superadmin'])->call('save')->assertHasErrors('form.roles.1');
        $this->assertFalse(User::where('email', 'kosong@koperasi.test')->exists());
    }

    public function test_edit_adds_and_removes_roles_with_audit(): void
    {
        $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create(['name' => 'Bu Kasir']);
        Livewire::test(Index::class)->call('edit', $u->id)->assertSet('form.roles', ['kasir'])
            ->set('form.roles', ['kasir', 'pengurus'])->call('save')->assertHasNoErrors()
            ->assertSee('Akun Bu Kasir diperbarui (Pengurus + Kasir).');
        $this->assertSame(['pengurus', 'kasir'], $u->fresh()->roleNames());

        $log = ActivityLog::where('event', 'user.role_changed')->latest('id')->first();
        $this->assertSame([['pengurus'], []], [$log->properties['added'], $log->properties['removed']]);

        Livewire::test(Index::class)->call('edit', $u->id)->set('form.roles', ['pengurus'])->call('save')->assertHasNoErrors();
        $this->assertSame(['pengurus'], $u->fresh()->roleNames());
        $this->assertSame(['kasir'], ActivityLog::where('event', 'user.role_changed')->latest('id')->first()->properties['removed']);
    }

    public function test_admin_protection_counts_any_admin_role(): void
    {
        $admin = $this->loginAs('admin');
        // satu-satunya admin tidak bisa mencabut peran admin dari dirinya walau tetap pengurus
        Livewire::test(Index::class)->call('edit', $admin->id)->set('form.roles', ['pengurus'])->call('save')->assertHasErrors('form.roles');
        // menambah peran lain ke diri sendiri tetap boleh
        Livewire::test(Index::class)->call('edit', $admin->id)->set('form.roles', ['admin', 'pengurus'])->call('save')->assertHasNoErrors();
        $this->assertSame(['admin', 'pengurus'], $admin->fresh()->roleNames());
    }

    public function test_role_filter_and_counts_use_all_roles(): void
    {
        $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create(['name' => 'Zaki Rangkap']);
        $u->roles()->attach(Role::named('pengurus')->id, ['created_at' => now()]);
        $c = Livewire::test(Index::class)->set('role', 'pengurus')->assertSee('Zaki Rangkap')
            ->set('role', 'kasir')->assertSee('Zaki Rangkap')
            ->set('role', 'admin')->assertDontSee('Zaki Rangkap');
        $stats = $c->get('stats');
        $this->assertSame(1, $stats['multi']);
        $this->assertSame(User::active()->get()->filter(fn ($x) => in_array('pengurus', $x->roleNames(), true))->count(), $stats['pengurus']);
    }

    public function test_json_endpoint_accepts_roles_list_and_legacy_single_role(): void
    {
        $this->loginAs('admin');
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods(), true) && $r->uri() === 'pengguna');
        if (! $routes) {
            $this->markTestSkipped('Tidak ada endpoint JSON pembuatan pengguna.');
        }
        $this->postJson('/pengguna', ['name' => 'Via API', 'email' => 'api@koperasi.test', 'roles' => ['admin', 'kasir'], 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertSuccessful()->assertJsonPath('data.roles', ['admin', 'kasir']);
        $this->postJson('/pengguna', ['name' => 'Lama', 'email' => 'lama@koperasi.test', 'role' => 'pengurus', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertSuccessful()->assertJsonPath('data.roles', ['pengurus']);
        $this->postJson('/pengguna', ['name' => 'Kosong', 'email' => 'k@koperasi.test', 'roles' => [], 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertUnprocessable();
    }

    public function test_accounts_without_role_are_flagged_and_can_be_fixed(): void
    {
        $this->loginAs('admin');
        $orphan = User::factory()->create(['name' => 'Akun Yatim', 'role_id' => null]);
        DB::table('user_roles')->where('user_id', $orphan->id)->delete();
        $this->assertSame([], $orphan->fresh()->roleNames());

        $c = Livewire::test(Index::class)->assertSeeHtml('data-no-role-alert')->assertSeeText('1 akun tanpa peran')
            ->assertSeeHtml('data-no-role="'.$orphan->id.'"')->assertSee('Atur peran')->assertSee('Tanpa peran');
        $this->assertSame(1, $c->get('stats')['no_role']);

        $c->set('role', 'tanpa')->assertSee('Akun Yatim')->assertDontSee('Admin Koperasi')
            ->set('role', 'ngawur')->assertSet('role', '');

        // form membuka akun tanpa peran: tidak dicentangkan apa pun, wajib memilih sebelum simpan
        $c->call('edit', $orphan->id)->assertSet('form.roles', [])->assertSeeHtml('data-roles-empty')
            ->call('save')->assertHasErrors('form.roles')
            ->set('form.roles', ['kasir'])->call('save')->assertHasNoErrors()
            ->assertDontSeeHtml('data-no-role-alert');
        $this->assertSame(['kasir'], $orphan->fresh()->roleNames());
    }

    public function test_every_user_row_lists_all_roles_with_labels(): void
    {
        $this->loginAs('admin');
        $u = User::factory()->role('pengurus')->create(['name' => 'Bu Tiga Peran']);
        $u->roles()->syncWithoutDetaching([Role::named('kasir')->id => ['created_at' => now()], Role::named('admin')->id => ['created_at' => now()]]);
        Livewire::test(Index::class)->assertSeeHtml('data-user-roles="admin,pengurus,kasir"')
            ->assertSeeHtml('aria-label="Peran Admin, Pengurus, Kasir"');
    }
}
