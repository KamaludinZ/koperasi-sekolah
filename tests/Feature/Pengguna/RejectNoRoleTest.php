<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RejectNoRoleTest extends TestCase
{
    use RefreshDatabase;

    private function orphan(array $attrs = []): User
    {
        $u = User::factory()->create(['email' => 'yatim@koperasi.test', 'password' => 'Rahasia2026', 'role_id' => null] + $attrs);
        DB::table('user_roles')->where('user_id', $u->id)->delete();

        return $u->fresh(['role', 'roles']);
    }

    public function test_login_is_rejected_with_clear_message_and_audited(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);
        auth()->logout();
        $this->orphan();
        $this->postJson('/masuk', ['email' => 'yatim@koperasi.test', 'password' => 'Rahasia2026'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Akun ini belum memiliki peran. Minta admin koperasi menetapkan perannya.');
        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.no_role']);

        // akun nonaktif tetap mendapat pesan nonaktif
        User::factory()->role('kasir')->create(['email' => 'mati@koperasi.test', 'password' => 'Rahasia2026', 'is_active' => false]);
        $this->postJson('/masuk', ['email' => 'mati@koperasi.test', 'password' => 'Rahasia2026'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Akun ini dinonaktifkan. Hubungi admin koperasi.');
    }

    public function test_session_ends_when_all_roles_are_removed(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);
        $u = User::factory()->role('kasir')->create();
        $this->actingAs($u);
        $this->get(route('kasir'))->assertOk();

        DB::table('user_roles')->where('user_id', $u->id)->delete();
        DB::table('users')->where('id', $u->id)->update(['role_id' => null]);
        $this->actingAs($u->fresh()); // permintaan berikutnya memuat akun dari database (seperti di produksi)
        $this->get(route('kasir'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(ActivityLog::where('event', 'auth.forced_logout')->get()->contains(fn ($l) => ($l->properties['reason'] ?? null) === 'tanpa_peran'));
    }

    public function test_activating_account_without_role_is_rejected(): void
    {
        $this->loginAs('admin');
        $o = $this->orphan(['is_active' => false, 'name' => 'Yatim']);
        Livewire::test(Index::class)->call('toggleActive', $o->id)->assertSee('Tetapkan minimal satu peran untuk Yatim sebelum mengaktifkannya.');
        $this->assertFalse($o->fresh()->is_active);
        $this->postJson(route('pengguna.toggle', $o))->assertUnprocessable()->assertJsonValidationErrors('roles');

        app(UserService::class)->updateRoles($o, ['kasir'], auth()->user());
        $this->postJson(route('pengguna.toggle', $o))->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_every_write_path_refuses_empty_roles(): void
    {
        $admin = $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create();
        $svc = app(UserService::class);
        foreach ([
            fn () => $svc->create(['name' => 'X', 'email' => 'x@koperasi.test', 'roles' => [], 'password' => 'Rahasia2026']),
            fn () => $svc->update($u, ['name' => $u->name, 'email' => $u->email, 'roles' => []], $admin),
            fn () => $svc->updateRoles($u, [], $admin),
            fn () => $svc->removeRole($u, 'kasir', $admin),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Jalur #{$i} seharusnya menolak akun tanpa peran.");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('roles', $e->errors());
            }
        }
        $this->assertSame(['kasir'], $u->fresh()->roleNames());
        $this->assertFalse(User::where('email', 'x@koperasi.test')->exists());
    }

    public function test_create_admin_command_gives_role_in_pivot(): void
    {
        $this->artisan('koperasi:buat-admin', ['--name' => 'Admin Baru', '--email' => 'admin.baru@koperasi.test'])
            ->expectsQuestion('Kata sandi (min. 8 karakter, huruf & angka)', 'Rahasia2026')
            ->expectsQuestion('Ulangi kata sandi', 'Rahasia2026');
        $this->assertSame(['admin'], User::where('email', 'admin.baru@koperasi.test')->firstOrFail()->roleNames());
    }
}
