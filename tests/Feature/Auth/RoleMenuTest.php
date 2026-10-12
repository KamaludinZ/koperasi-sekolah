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

        $this->assertSame(['kasir', 'transaksi', 'barang', 'anggota', 'laporan', 'pengaturan'], $keys('admin'));
        $this->assertSame(['kasir', 'transaksi', 'barang', 'anggota'], $keys('kasir'));
        $this->assertSame(['transaksi', 'barang', 'anggota', 'laporan'], $keys('pengurus'));
        $this->assertCount(6, $keys(null)); // mode demo
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

    public function test_demo_mode_without_login_shows_all_menus(): void
    {
        $this->get('/laporan')->assertOk()->assertSee('Mode demo');
        $this->get('/')->assertOk(); // beranda publik tidak terpengaruh
    }
}
