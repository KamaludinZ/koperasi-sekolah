<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $mockRole = null;

    public function test_login_page_renders_with_demo_accounts(): void
    {
        $this->get('/masuk')
            ->assertOk()
            ->assertSee('Masuk petugas')
            ->assertSee('Kata sandi')
            ->assertSee('kasir@koperasi.test')
            ->assertSee('Kembali ke beranda koperasi');
    }

    public function test_successful_login_stores_user_and_redirects(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'KASIR@koperasi.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('kasir'));

        $this->assertSame('kasir', session('mock.user.role'));
    }

    public function test_wrong_password_and_validation(): void
    {
        Livewire::test(Login::class)->call('login')->assertHasErrors(['email' => 'required', 'password' => 'required']);
        Livewire::test(Login::class)->set('email', 'bukan-email')->set('password', 'x')->call('login')->assertHasErrors(['email' => 'email']);

        Livewire::test(Login::class)
            ->set('email', 'admin@koperasi.test')
            ->set('password', 'salah')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Email atau kata sandi salah.')
            ->assertSet('password', '');

        $this->assertNull(session('mock.user'));
    }

    public function test_inactive_account_is_rejected(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'nonaktif@koperasi.test')
            ->set('password', 'password')
            ->call('login')
            ->assertSee('Akun ini dinonaktifkan');
    }

    public function test_locks_after_five_failures(): void
    {
        $c = Livewire::test(Login::class)->set('email', 'admin@koperasi.test');
        foreach (range(1, Login::MAX_ATTEMPTS) as $i) {
            $c->set('password', 'salah')->call('login');
        }

        $c->set('password', 'password')->call('login')->assertSee('Terlalu banyak percobaan');
        $this->assertNull(session('mock.user'));

        $this->travel(Login::LOCK_SECONDS + 1)->seconds();
        $c->set('password', 'password')->call('login')->assertRedirect(route('kasir'));
    }

    public function test_logged_in_user_sees_card_and_can_logout(): void
    {
        $this->seed(ProductSeeder::class);
        session(['mock.user' => ['id' => 2, 'name' => 'Bu Rina', 'email' => 'kasir@koperasi.test', 'role' => 'kasir', 'is_active' => true]]);

        $this->get('/kasir')->assertSee('Bu Rina')->assertSee('Keluar');
        $this->get('/masuk')->assertRedirect(route('kasir'));

        $this->post('/keluar')->assertRedirect(route('login'));
        $this->assertNull(session('mock.user'));
        $this->get('/masuk')->assertSee('Anda sudah keluar');
    }
}
