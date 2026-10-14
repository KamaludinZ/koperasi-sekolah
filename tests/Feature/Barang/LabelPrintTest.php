<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Labels;
use App\Models\Product;
use App\Support\LabelFormats;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LabelPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_items_parsing_and_sheets(): void
    {
        $a = $this->pid('ATK001');
        $this->assertSame([$a => 3, 7 => 1, 9 => LabelFormats::MAX_COPIES], LabelFormats::parseItems("{$a}:3, 7,x,9:9999"));
        $this->assertSame("{$a}:3", LabelFormats::encodeItems([$a => 3]));

        $s = LabelFormats::sheets([$a => 30, 999999 => 5], 'a4-3x8');
        $this->assertSame(30, $s['total']);
        $this->assertCount(2, $s['pages']);
        $this->assertCount(24, $s['pages'][0]);
        $this->assertSame('ATK001', $s['pages'][0][0]['barcode']);

        $big = LabelFormats::sheets([$a => 200, $this->pid('ATK002') => 200, $this->pid('MKN001') => 200, $this->pid('ATK003') => 200, $this->pid('MKN002') => 200, $this->pid('ATK004') => 200], 'a4-4x10');
        $this->assertSame(LabelFormats::MAX_LABELS, $big['total']);
        $this->assertTrue($big['truncated']);
    }

    public function test_page_builds_list_and_print_link(): void
    {
        $a = $this->pid('ATK001');
        $b = $this->pid('ATK002');
        Product::whereKey($b)->update(['barcode' => '8991112223334']);

        $c = Livewire::withQueryParams(['barang' => "{$a},{$b}:2", 'format' => 'thermal-50x30'])->test(Labels::class)
            ->assertSet('format', 'thermal-50x30')
            ->assertSet('rows', [['id' => $a, 'copies' => 1], ['id' => $b, 'copies' => 2]])
            ->assertSee('3</strong> label', false)
            ->assertSee('data-label="ATK001"', false)->assertSee('8991112223334')->assertSee('lembar 1 dari 3');

        $c->set('search', 'roti')->assertSee('Roti Coklat')->call('add', $this->pid('MKN001'))->assertSet('search', '')
            ->call('add', $a)->assertSet('rows.0.copies', 2)
            ->set('rows.1.copies', 0)->assertSet('rows.1.copies', 1)
            ->call('remove', 2)->set('showPrice', false);
        $this->assertStringContainsString('barang='.urlencode("{$a}:2,{$b}:1"), $c->get('printUrl') ?? $c->instance()->printUrl);
        $this->assertStringContainsString('harga=0', $c->instance()->printUrl);

        $c->set('format', 'a4-3x8')->call('fillSheet')->assertSet('rows.0.copies', 12)->call('clear')->assertSet('rows', []);
    }

    public function test_preview_pages_and_toggles(): void
    {
        $a = $this->pid('ATK001');
        $b = $this->pid('ATK002');
        Livewire::withQueryParams(['barang' => "{$a}:24,{$b}:2"])->test(Labels::class)
            ->assertSee('lembar 1 dari 2')->assertSee('Rp4.000')->assertDontSee('data-label="ATK002"', false)
            ->call('previewTo', 1)->assertSet('previewPage', 1)->assertSee('lembar 2 dari 2')->assertSee('data-label="ATK002"', false)
            ->call('previewTo', 9)->assertSet('previewPage', 1)
            ->assertSee('3.2mm;">Rp', false)->set('showPrice', false)->assertDontSee('3.2mm;">Rp', false)
            ->set('showName', false)->assertDontSee('Pulpen Hitam</p>', false)
            ->set('rows.0.copies', 1)->assertSet('previewPage', 0);
    }

    public function test_print_view(): void
    {
        $a = $this->pid('ATK001');
        $this->get("/barang/barcode/label/cetak?barang={$a}:25&format=a4-3x8&harga=1")
            ->assertOk()
            ->assertSee('size: 210mm 297mm', false)
            ->assertSee('25 label · 2 lembar')
            ->assertSee('data-label="ATK001"', false)
            ->assertSee('Rp4.000')
            ->assertDontSee('window.print())</script>', false);

        $this->get("/barang/barcode/label/cetak?barang={$a}&format=thermal-58&harga=0&otomatis=1")
            ->assertOk()->assertSee('size: 58mm 30mm', false)->assertDontSee('Rp4.000')->assertSee('window.print()', false);

        $this->get("/barang/barcode/label/cetak?barang={$a}:3&format=thermal-80")->assertOk()->assertSee('size: 80mm 30mm', false)->assertSee('3 label · 2 lembar');
        $this->assertSame('thermal-58', LabelFormats::thermalForReceipt());
        app(\App\Services\SettingsService::class)->save('receipt', ['paper' => '80mm']);
        $this->assertSame('thermal-80', LabelFormats::thermalForReceipt());
        $this->get('/barang/barcode/label')->assertSee('sesuai printer struk');

        $this->get('/barang/barcode/label/cetak?barang=abc')->assertSessionHasErrors('barang');
        $this->get('/barang/barcode/label')->assertOk()->assertSee('Belum ada barang');
    }
}
