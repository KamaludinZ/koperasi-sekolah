<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

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

        $this->assertSame('kasir', auth()->user()->roleName());
        $this->assertNotNull(auth()->user()->last_login_at);
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

        $this->assertGuest();
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
        $this->assertGuest();

        $this->travel(Login::LOCK_SECONDS + 1)->seconds();
        $c->set('password', 'password')->call('login')->assertRedirect(route('kasir'));
    }

    public function test_logged_in_user_sees_card_and_can_logout(): void
    {
        $this->seed(ProductSeeder::class);
        $this->loginAs('kasir');

        $this->get('/kasir')->assertSee('Bu Rina')->assertSee('Keluar');
        $this->get('/masuk')->assertRedirect(route('kasir'));

        $this->post('/keluar')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/masuk')->assertSee('Anda sudah keluar');
    }

    public function test_json_login_endpoint(): void
    {
        $this->postJson('/masuk', ['email' => 'pengurus@koperasi.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.role', 'pengurus')
            ->assertJsonPath('redirect', route('laporan'))
            ->assertJsonMissingPath('data.password');
        $this->assertAuthenticated();

        $this->post('/keluar');
        $this->assertGuest();

        $this->postJson('/masuk', ['email' => 'pengurus@koperasi.test', 'password' => 'salah'])
            ->assertJsonValidationErrors(['email' => 'Email atau kata sandi salah.']);
        $this->postJson('/masuk', ['email' => 'nonaktif@koperasi.test', 'password' => 'password'])
            ->assertJsonValidationErrors(['email' => 'Akun ini dinonaktifkan. Hubungi admin koperasi.']);
        $this->postJson('/masuk', [])->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_form_login_redirects_to_intended_page(): void
    {
        $this->get('/anggota')->assertRedirect(route('login'));
        $this->post('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password'])->assertRedirect(url('/anggota'));
    }

    public function test_real_session_drives_menu_and_guard(): void
    {
        $this->post('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password']);

        $this->get('/kasir')->assertOk()->assertSee('Bu Rina');
        $this->get('/laporan')->assertForbidden();
    }

    public function test_warns_remaining_attempts_then_shows_countdown_panel(): void
    {
        $c = Livewire::test(Login::class)->set('email', 'admin@koperasi.test');
        $c->set('password', 'salah')->call('login')
            ->assertSet('attemptsLeft', Login::MAX_ATTEMPTS - 1)->assertSee('Email atau kata sandi salah')->assertDontSee('kali percobaan sebelum');
        foreach (range(2, Login::MAX_ATTEMPTS - 2) as $i) {
            $c->set('password', 'salah')->call('login');
        }
        $c->assertSet('attemptsLeft', 2)->assertSee('Sisa 2 kali percobaan sebelum masuk dikunci sementara.');

        $c->set('password', 'salah')->call('login')->set('password', 'salah')->call('login')
            ->assertSet('attemptsLeft', 0)
            ->assertSee('Terlalu banyak percobaan masuk')
            ->assertDontSee('Email atau kata sandi salah')
            ->assertSeeHtml('disabled');
        $this->assertGreaterThan(50, $c->get('lock')['seconds']);
        $this->assertFalse($c->get('lock')['manual']);

        // Ganti email: panel kunci hilang (kunci per email + IP).
        $c->set('email', 'kasir@koperasi.test')->assertSet('lock', null);
        $c->set('email', 'admin@koperasi.test')->call('refreshLock')->assertNotSet('lock', null);

        $this->travel(Login::LOCK_SECONDS + 1)->seconds();
        // Kunci sementara habis; hitungan tetap berjalan menuju tingkat 2 (2× ambang).
        $c->call('lockExpired')->assertSet('lock', null)->assertSet('attemptsLeft', Login::MAX_ATTEMPTS)->assertDontSee('Terlalu banyak percobaan');
        $c->set('password', 'password')->call('login')->assertRedirect(route('kasir'));
    }

    public function test_manual_lock_panel_tells_to_contact_admin(): void
    {
        Livewire::test(Login::class)->set('lock', ['seconds' => null, 'manual' => true])
            ->assertSee('hanya bisa dibuka oleh admin koperasi')->assertDontSee('coba lagi dalam');
    }
}
