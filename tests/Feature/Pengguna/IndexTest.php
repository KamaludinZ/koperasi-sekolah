<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // masuk sebagai Admin Koperasi
        $this->seed(ProductSeeder::class);
    }

    private function user(string $email): User
    {
        return User::with('role')->firstWhere('email', $email);
    }

    public function test_admin_sees_users_and_role_access(): void
    {
        $this->get('/pengguna')
            ->assertOk()
            ->assertSee('Kelola Pengguna')
            ->assertSee('kasir@koperasi.test')
            ->assertSee('Hak akses peran')
            ->assertSee('Nonaktif')
            ->assertDontSee('data tiruan');

        $c = Livewire::test(Index::class)->set('role', 'kasir');
        $this->assertSame(['Bu Rina', 'Pak Galih'], $c->instance()->users->pluck('name')->all());
        $this->assertSame(3, $c->instance()->stats['active']);
    }

    public function test_only_admin_can_open(): void
    {
        $this->loginAs('pengurus');
        $this->get('/pengguna')->assertForbidden();
        $this->getJson('/pengguna/daftar')->assertForbidden();
    }

    public function test_create_user_who_can_then_log_in(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.name', 'Bu Ani')
            ->set('form.email', 'Ani@Koperasi.test')
            ->set('form.role', 'pengurus')
            ->set('form.password', 'Rahasia123')
            ->set('form.password_confirmation', 'Rahasia123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Akun Bu Ani dibuat sebagai Pengurus.');

        $ani = $this->user('ani@koperasi.test');
        $this->assertSame('pengurus', $ani->roleName());
        $this->assertSame('user.created', ActivityLog::latest('id')->value('event'));

        auth()->logout();
        $this->postJson('/masuk', ['email' => 'ani@koperasi.test', 'password' => 'Rahasia123'])->assertOk()->assertJsonPath('data.role', 'pengurus');
    }

    public function test_validation(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.email', 'KASIR@koperasi.test')
            ->set('form.password', 'pendek')
            ->set('form.password_confirmation', 'beda')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.email', 'form.password']);
    }

    public function test_edit_keeps_password_when_blank_and_reset_ends_sessions(): void
    {
        $kasir = $this->user('kasir@koperasi.test');
        DB::table('sessions')->insert(['id' => 'sesi-kasir', 'user_id' => $kasir->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);

        Livewire::test(Index::class)->call('edit', $kasir->id)->set('form.name', 'Bu Rina W.')->call('save')->assertHasNoErrors();
        $this->assertTrue(Hash::check('password', $kasir->fresh()->password));
        $this->assertDatabaseHas('sessions', ['id' => 'sesi-kasir']);

        Livewire::test(Index::class)->call('edit', $kasir->id)
            ->set('form.password', 'Baru12345')->set('form.password_confirmation', 'Baru12345')
            ->call('save')->assertHasNoErrors();
        $this->assertTrue(Hash::check('Baru12345', $kasir->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-kasir']);
        $this->assertSame(1, ActivityLog::where('event', 'user.password_reset')->count());
    }

    public function test_toggle_active_and_self_protection(): void
    {
        $kasir = $this->user('kasir@koperasi.test');
        $admin = $this->user('admin@koperasi.test');

        $c = Livewire::test(Index::class)->call('toggleActive', $kasir->id)->assertSee('Bu Rina dinonaktifkan');
        $this->assertFalse($kasir->fresh()->is_active);
        $c->call('toggleActive', $kasir->id)->assertSee('diaktifkan kembali');

        Livewire::test(Index::class)->call('toggleActive', $admin->id)->assertSee('Anda tidak bisa menonaktifkan akun sendiri.');
        Livewire::test(Index::class)->call('edit', $admin->id)->set('form.role', 'kasir')->call('save')->assertHasErrors('form.role');
        $this->assertSame('admin', $admin->fresh()->roleName());
    }

    public function test_last_active_admin_is_protected(): void
    {
        $other = User::factory()->admin()->create(['name' => 'Admin Kedua']);
        $this->actingAs($other);

        // Admin Koperasi boleh diturunkan selama masih ada admin lain…
        $this->putJson('/pengguna/'.$this->user('admin@koperasi.test')->id, ['name' => 'Admin Koperasi', 'email' => 'admin@koperasi.test', 'role' => 'kasir', 'is_active' => true])->assertOk();
        // …tetapi admin terakhir tidak bisa menonaktifkan / menurunkan dirinya.
        $this->postJson("/pengguna/{$other->id}/status")->assertJsonValidationErrors('is_active');
    }

    public function test_json_endpoints(): void
    {
        $this->getJson('/pengguna/daftar?peran=pengurus')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'pengurus@koperasi.test')->assertJsonPath('meta.stats.admin', 1);

        $id = $this->postJson('/pengguna', ['name' => 'Kasir Baru', 'email' => 'baru@koperasi.test', 'role' => 'kasir', 'password' => 'Kasir2026', 'password_confirmation' => 'Kasir2026'])
            ->assertCreated()->assertJsonPath('data.role', 'kasir')->json('data.id');
        $this->putJson("/pengguna/{$id}", ['name' => 'Kasir Baru', 'email' => 'baru@koperasi.test', 'role' => 'pengurus'])->assertOk()->assertJsonPath('data.role', 'pengurus');
        $this->postJson("/pengguna/{$id}/status")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/pengguna', ['name' => 'X', 'email' => 'x@y.test', 'role' => 'tamu', 'password' => 'Kasir2026', 'password_confirmation' => 'Kasir2026'])->assertJsonValidationErrors('role');
    }

    public function test_role_permissions_can_be_edited(): void
    {
        $this->getJson('/pengguna/peran')->assertOk()->assertJsonPath('data.1.name', 'kasir')->assertJsonPath('menus.audit.label', 'Audit & Riwayat');

        $this->putJson('/pengguna/peran/kasir', ['permissions' => ['kasir', 'transaksi', 'anggota', 'barang', 'laporan']])
            ->assertOk()->assertJsonPath('data.permissions', ['kasir', 'transaksi', 'barang', 'anggota', 'laporan']);
        $this->assertTrue(Role::named('kasir')->allows('laporan'));
        $this->assertStringContainsString('(+Laporan)', ActivityLog::where('event', 'role.permissions_updated')->value('description'));

        $this->putJson('/pengguna/peran/admin', ['permissions' => ['kasir']])->assertJsonValidationErrors('permissions');
        $this->putJson('/pengguna/peran/kasir', ['permissions' => ['gudang']])->assertJsonValidationErrors('permissions.0');

        Livewire::test(Index::class)
            ->call('editPermissions', 'pengurus')
            ->set('permissions.pengurus', ['laporan'])
            ->call('savePermissions')
            ->assertSee('Hak akses peran Pengurus disimpan.');
        $this->assertSame(['laporan'], Role::named('pengurus')->permissions);

        // Kasir (yang kini boleh Laporan) langsung bisa membuka halamannya.
        $this->loginAs('kasir');
        $this->get('/laporan')->assertOk();
    }

    public function test_user_form_shows_permission_matrix_for_selected_role(): void
    {
        $c = \Livewire\Livewire::test(\App\Livewire\Pengguna\Index::class)->call('create')
            ->assertSeeHtml('data-permission-matrix')->assertSee('Menu yang bisa dibuka tiap peran')->assertSee('Kas Harian');
        $c->set('form.role', 'pengurus')->assertSeeHtml('bg-emerald-50');
        $html = $c->html();
        $this->assertGreaterThan(5, substr_count($html, '✓'));
    }
}
