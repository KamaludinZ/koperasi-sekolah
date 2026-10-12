<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Index;
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
        parent::setUp(); // admin
        $this->seed(ProductSeeder::class);
    }

    public function test_settings_overview_renders(): void
    {
        $this->get('/pengaturan')
            ->assertOk()
            ->assertSee('Pengaturan Koperasi')
            ->assertSee('Koperasi Siswa Al-Hikmah')
            ->assertSee('Struk pembelian')
            ->assertSee('Pajak & pembulatan')
            ->assertSee('Tidak dipungut')
            ->assertSee('Kelengkapan profil')
            ->assertSee('4 dari 5 sudah lengkap'); // logo belum ada
    }

    public function test_menu_is_admin_only(): void
    {
        $this->get('/kasir')->assertSee(route('pengaturan'), false);

        $this->loginAs('pengurus');
        $this->get('/pengaturan')->assertForbidden();
        $this->loginAs('kasir');
        $this->get('/pengaturan')->assertForbidden();
    }

    public function test_tax_and_rounding_example(): void
    {
        MockData::saveSettings('tax', ['enabled' => true, 'rate' => 10, 'rounding' => 'up', 'rounding_unit' => 500]);

        $ex = Livewire::test(Index::class)->assertSee('10%')->assertSee('Selalu ke atas')->instance()->example;
        $this->assertSame(['subtotal' => 12340, 'tax' => 1234, 'total' => 13574, 'rounded' => 14000], $ex);

        MockData::saveSettings('tax', ['rounding' => 'nearest', 'rounding_unit' => 100]);
        $this->assertSame(13600, Livewire::test(Index::class)->instance()->example['rounded']);

        MockData::saveSettings('tax', ['included' => true, 'rounding' => 'down', 'rounding_unit' => 1000]);
        $this->assertSame(['subtotal' => 12340, 'tax' => 0, 'total' => 12340, 'rounded' => 12000], Livewire::test(Index::class)->instance()->example);
    }

    public function test_shows_last_editor(): void
    {
        MockData::saveSettings('receipt', ['footer' => 'Barakallah']);

        $this->get('/pengaturan')->assertSee('Barakallah')->assertSee('oleh Admin Koperasi');
    }
}
