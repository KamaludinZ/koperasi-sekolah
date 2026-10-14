<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Support\BarcodeImage;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BarcodeDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_svg_is_scalable_and_rejects_bad_values(): void
    {
        $svg = BarcodeImage::svg('ATK001');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0', $svg);
        $this->assertStringContainsString('aria-label="Barcode ATK001"', $svg);
        $this->assertStringNotContainsString('id="bars"', $svg);
        $this->assertStringNotContainsString(' width="', explode('>', $svg)[0]);

        $this->assertNull(BarcodeImage::svg(''));
        $this->assertNull(BarcodeImage::svg(str_repeat('A', 65)));
        $this->assertSame('ATK001', BarcodeImage::value(['code' => 'ATK001']));
        $this->assertSame('8991234', BarcodeImage::value(['code' => 'ATK001', 'barcode' => '8991234']));
    }

    public function test_detail_panel_shows_barcode(): void
    {
        $id = $this->pid('ATK001');

        Livewire::test(Index::class)
            ->call('show', $id)
            ->assertSet('viewing', $id)
            ->assertSee('Buku Tulis 38 Lembar')
            ->assertSee('data-barcode="ATK001"', false)
            ->assertSee('mengikuti kode barang')
            ->assertSee('Riwayat stok')
            ->call('edit', $id)
            ->assertSet('viewing', null)
            ->assertSet('editing', true);

        Livewire::test(Index::class)->call('show', 999999)->assertDontSee('data-barcode', false);
    }

    public function test_detail_is_linkable(): void
    {
        $this->get('/barang?lihat='.$this->pid('ATK001'))->assertOk()->assertSee('data-barcode="ATK001"', false);
    }
}
