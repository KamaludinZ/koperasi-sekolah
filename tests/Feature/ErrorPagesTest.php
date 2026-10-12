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
        $this->get('/_uji/403')->assertForbidden()->assertSee('Akses tidak diizinkan');
        $this->get('/_uji/419')->assertStatus(419)->assertSee('Sesi sudah berakhir');
        $this->get('/_uji/429')->assertStatus(429)->assertSee('Terlalu banyak permintaan');
    }

    public function test_maintenance_page(): void
    {
        $html = view('errors.503')->render();

        $this->assertStringContainsString('Sedang dalam perawatan', $html);
        $this->assertStringContainsString('http-equiv="refresh"', $html);
    }
}
