<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_found_page_is_themed(): void
    {
        $this->get('/tidak-ada')
            ->assertNotFound()
            ->assertSee('Halaman tidak ditemukan')
            ->assertSee('Kopsis Al-Hikmah')
            ->assertSee('Ke beranda');
    }

    public function test_server_and_forbidden_pages(): void
    {
        config()->set('app.debug', false);
        Route::get('/_uji/{code}', fn (int $code) => abort($code));

        $this->get('/_uji/500')->assertStatus(500)->assertSee('Terjadi kesalahan');
        $this->get('/_uji/403')->assertForbidden()->assertSee('Akses ditolak');
        $this->get('/_uji/419')->assertStatus(419)->assertSee('Sesi sudah berakhir');
        $this->get('/_uji/429')->assertStatus(429)->assertSee('Terlalu banyak permintaan');
    }

    public function test_maintenance_page(): void
    {
        $html = view('errors.503')->render();

        $this->assertStringContainsString('Sedang dalam perawatan', $html);
        $this->assertStringContainsString('http-equiv="refresh"', $html);
    }

    public function test_reloading_post_only_address_with_get_redirects_instead_of_405(): void
    {
        // Chrome di HP yang berganti mode Desktop/Seluler memuat ulang alamat asli POST /keluar sebagai GET.
        $admin = auth()->user();
        $this->get('/keluar')->assertRedirect(route('login')); // /masuk meneruskan petugas yang masih masuk
        $this->assertAuthenticatedAs($admin); // GET tidak pernah mengeluarkan
        $this->get('/impersonasi/selesai')->assertRedirect('/');

        auth()->logout();
        $this->get('/keluar')->assertRedirect(route('login'));
        $this->get('/portal/keluar')->assertRedirect(route('portal'));

        $this->getJson('/keluar')->assertStatus(405);
    }

    public function test_other_4xx_codes_use_themed_fallback(): void
    {
        config()->set('app.debug', false);
        Route::get('/_uji/{code}', fn (int $code) => abort($code));

        $this->put('/keluar')->assertStatus(405)->assertSee('Cara membuka halaman tidak sesuai')->assertDontSee('Oops');
        $this->get('/_uji/413')->assertStatus(413)->assertSee('Berkas terlalu besar');
        $this->get('/_uji/400')->assertStatus(400)->assertSee('Permintaan tidak dapat diproses');
        $this->get('/_uji/502')->assertStatus(502)->assertSee('Terjadi kesalahan');
    }
}
