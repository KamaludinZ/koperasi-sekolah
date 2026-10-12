<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Support\Menu;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function actingAsMock(string $role): void
    {
        session(['mock.user' => ['id' => 9, 'name' => 'Uji '.ucfirst($role), 'email' => "{$role}@koperasi.test", 'role' => $role, 'is_active' => true]]);
    }

    public function test_menu_per_role(): void
    {
        $keys = fn (?string $role) => collect(Menu::for($role))->flatMap(fn ($g) => array_keys($g['items']))->all();

        $this->assertSame(['kasir', 'transaksi', 'barang', 'anggota', 'laporan', 'pengguna', 'pengaturan'], $keys('admin'));
        $this->assertSame(['kasir', 'transaksi', 'barang', 'anggota'], $keys('kasir'));
        $this->assertSame(['transaksi', 'barang', 'anggota', 'laporan'], $keys('pengurus'));
        $this->assertCount(7, $keys(null)); // mode demo
        $this->assertSame('laporan', Menu::homeRoute('pengurus'));
    }

    public function test_cashier_sidebar_hides_reports_and_page_is_forbidden(): void
    {
        $this->actingAsMock('kasir');

        $this->get('/kasir')->assertOk()->assertSee('Layanan harian')->assertDontSee(route('laporan'), false);
        $this->get('/laporan')->assertForbidden()->assertSee('Akses tidak diizinkan');
        $this->getJson('/laporan/hari-ini')->assertForbidden();
    }

    public function test_pengurus_cannot_open_cashier_and_lands_on_reports(): void
    {
        $this->actingAsMock('pengurus');
        $this->get('/kasir')->assertForbidden();
        $this->get('/laporan')->assertOk()->assertSee('Pengurus');

        session()->forget('mock.user');
        Livewire::test(Login::class)
            ->set('email', 'pengurus@koperasi.test')->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('laporan'));
    }

    public function test_guest_is_sent_to_login_and_back(): void
    {
        session()->forget('mock.user');

        $this->get('/akun/kata-sandi')->assertRedirect(route('login'));
        $this->getJson('/laporan/hari-ini')->assertUnauthorized();
        $this->get('/laporan?periode=7-hari')->assertRedirect(route('login'));
        $this->get('/masuk')->assertSee('Silakan masuk dulu untuk membuka halaman itu.');
        $this->get('/')->assertOk(); // beranda publik tetap terbuka

        Livewire::test(Login::class)
            ->set('email', 'admin@koperasi.test')->set('password', 'password')
            ->call('login')
            ->assertRedirect(url('/laporan?periode=7-hari'));
    }

    public function test_denied_page_explains_allowed_roles(): void
    {
        $this->actingAsMock('kasir');

        $this->get('/laporan')
            ->assertForbidden()
            ->assertSee('Menu Laporan hanya untuk peran Admin dan Pengurus.')
            ->assertSee('Uji Kasir')
            ->assertSee('Ke halaman saya')
            ->assertSee(route('kasir'), false);

        $this->get('/pengguna')->assertForbidden()->assertSee('Menu Pengguna hanya untuk peran Admin.');
        $this->assertSame('Menu Barang &amp; Stok hanya untuk peran Admin, Kasir dan Pengurus.', e(Menu::deniedMessage('barang')));
    }
}
