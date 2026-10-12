<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Auth\Login;
use App\Livewire\Pengguna\Index;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        session(['mock.user' => MockData::findUser(1)]); // Admin Koperasi
    }

    public function test_admin_sees_users_and_role_access(): void
    {
        $this->get('/pengguna')
            ->assertOk()
            ->assertSee('Kelola Pengguna')
            ->assertSee('kasir@koperasi.test')
            ->assertSee('Hak akses peran')
            ->assertSee('Nonaktif');

        $c = Livewire::test(Index::class)->set('role', 'kasir');
        $this->assertSame(['Bu Rina', 'Pak Galih'], array_column($c->instance()->users, 'name'));
        $this->assertSame(3, $c->instance()->stats['active']);
    }

    public function test_only_admin_can_open(): void
    {
        session(['mock.user' => MockData::findUser(2)]); // kasir
        $this->get('/pengguna')->assertForbidden();
    }

    public function test_create_user_who_can_then_log_in(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.name', 'Bu Ani')
            ->set('form.email', 'Ani@Koperasi.test')
            ->set('form.role', 'pengurus')
            ->set('form.password', 'rahasia123')
            ->set('form.password_confirmation', 'rahasia123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Akun Bu Ani dibuat sebagai Pengurus.');

        $this->assertSame('ani@koperasi.test', collect(MockData::users())->firstWhere('name', 'Bu Ani')['email']);

        session()->forget('mock.user');
        Livewire::test(Login::class)->set('email', 'ani@koperasi.test')->set('password', 'rahasia123')
            ->call('login')->assertRedirect(route('laporan'));
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

    public function test_edit_keeps_password_when_blank_and_resets_when_filled(): void
    {
        Livewire::test(Index::class)->call('edit', 2)->set('form.name', 'Bu Rina W.')->call('save')->assertHasNoErrors();
        $this->assertNotNull(MockData::attempt('kasir@koperasi.test', 'password'));

        Livewire::test(Index::class)->call('edit', 2)
            ->set('form.password', 'baru12345')->set('form.password_confirmation', 'baru12345')
            ->call('save')->assertHasNoErrors();
        $this->assertNull(MockData::attempt('kasir@koperasi.test', 'password'));
        $this->assertSame('Bu Rina W.', MockData::attempt('kasir@koperasi.test', 'baru12345')['name']);
    }

    public function test_toggle_active_and_self_protection(): void
    {
        $c = Livewire::test(Index::class)->call('toggleActive', 2)->assertSee('Bu Rina dinonaktifkan');
        $this->assertFalse(MockData::findUser(2)['is_active']);
        $c->call('toggleActive', 2)->assertSee('diaktifkan kembali');

        Livewire::test(Index::class)->call('toggleActive', 1)->assertSee('Anda tidak bisa menonaktifkan akun sendiri.');
        Livewire::test(Index::class)->call('edit', 1)->set('form.role', 'kasir')->call('save')
            ->assertHasErrors('form.role');
        $this->assertSame('admin', MockData::findUser(1)['role']);
    }

    public function test_last_login_is_recorded(): void
    {
        session()->forget('mock.user');
        $this->travelTo('2026-10-14 08:00:00');
        Livewire::test(Login::class)->set('email', 'nonaktif@koperasi.test')->set('password', 'password')->call('login');
        $this->assertNull(MockData::findUser(4)['last_login_at']);

        Livewire::test(Login::class)->set('email', 'pengurus@koperasi.test')->set('password', 'password')->call('login');
        $this->assertSame('2026-10-14 08:00:00', MockData::findUser(3)['last_login_at']);
    }
}
