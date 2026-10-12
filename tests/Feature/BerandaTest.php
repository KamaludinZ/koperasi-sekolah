<?php

namespace Tests\Feature;

use App\Http\Controllers\BerandaController;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BerandaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_public_home_shows_profile_popular_products_and_hours(): void
    {
        $this->travelTo('2026-10-14 09:00:00'); // Rabu
        Transaction::factory()->withItems([$this->pid('MNM001') => 9])->at('2026-10-13 09:00:00')->create();

        $this->get('/')
            ->assertOk()
            ->assertSee('Kopsis Al-Hikmah')
            ->assertSee('Madrasah Tsanawiyah Negeri 1 Contoh')
            ->assertSee('Sedang buka')
            ->assertSee('Barang populer bulan ini')
            ->assertSee('Air Mineral 600 ml')
            ->assertSee('Jam buka')
            ->assertSee('Masuk petugas')
            ->assertDontSee('Rp0'); // tidak menampilkan data keuangan anggota
    }

    public function test_open_status(): void
    {
        $this->assertTrue(BerandaController::openStatus(Carbon::parse('2026-10-14 13:59'))['open']);
        $this->assertSame('Buka besok pukul 07.00', BerandaController::openStatus(Carbon::parse('2026-10-14 14:00'))['detail']);
        $this->assertSame('Buka hari ini pukul 07.00', BerandaController::openStatus(Carbon::parse('2026-10-14 06:30'))['detail']);
        $this->assertFalse(BerandaController::openStatus(Carbon::parse('2026-10-16 11:30'))['open']); // Jumat tutup 11.00
        $this->assertSame('Buka Senin pukul 07.00', BerandaController::openStatus(Carbon::parse('2026-10-17 13:00'))['detail']); // Sabtu sore
    }
}
