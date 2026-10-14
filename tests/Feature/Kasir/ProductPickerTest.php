<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\ProductPicker;
use Livewire\Livewire;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_filters_by_name_or_code(): void
    {
        Livewire::test(ProductPicker::class)
            ->set('search', 'pulpen')
            ->assertSee('Pulpen Hitam')
            ->assertDontSee('Roti Coklat')
            ->set('search', 'mkn001')
            ->assertSee('Roti Coklat')
            ->assertDontSee('Pulpen Hitam');
    }

    public function test_filters_by_category(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('setCategory', 'Minuman')
            ->assertSee('Teh Kotak')
            ->assertDontSee('Dasi Sekolah');
    }

    public function test_picking_dispatches_event(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('pick', $this->pid('ATK002'))
            ->assertDispatched('product-picked', productId: $this->pid('ATK002'));
    }

    public function test_out_of_stock_cannot_be_picked(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('pick', $this->pid('ATK005'))
            ->assertNotDispatched('product-picked');
    }

    public function test_enter_picks_exact_code_and_clears_search(): void
    {
        Livewire::test(ProductPicker::class)
            ->set('search', 'ATK001')
            ->call('pickFromSearch')
            ->assertDispatched('product-picked', productId: $this->pid('ATK001'))
            ->assertSet('search', '');
    }

    public function test_scan_feedback_for_success_unknown_out_of_stock_and_ambiguous(): void
    {
        $c = Livewire::test(ProductPicker::class)->set('search', 'ATK001')->call('pickFromSearch')
            ->assertSet('scan.type', 'success')->assertNotDispatched('scan-feedback')->assertSee('Ditambahkan:');

        $c->set('search', 'ZZZ-999')->assertSet('scan', null)->call('pickFromSearch')
            ->assertSet('scan.type', 'error')->assertSet('search', 'ZZZ-999')->assertSee('Barcode/kode “ZZZ-999” tidak dikenali.')->assertSee('data-search-manual', false)->assertDispatched('scan-unknown', code: 'ZZZ-999')->assertNotDispatched('product-picked');

        \App\Models\Product::where('code', 'ATK002')->update(['stock' => 0]);
        $c->set('search', 'ATK002')->call('pickFromSearch')->assertSet('scan.type', 'error')->assertSee('stoknya habis');

        $c->set('search', 'ATK')->call('pickFromSearch')->assertSet('scan.type', 'info')->assertSee('barang cocok dengan “ATK”')
            ->assertNotDispatched('scan-unknown')->assertDontSee('data-search-manual', false);
    }

    public function test_camera_scan_adds_exact_code_without_touching_search(): void
    {
        Livewire::test(ProductPicker::class)
            ->set('search', 'pulpen')
            ->call('scanCode', ' atk001 ')
            ->assertDispatched('product-picked', productId: $this->pid('ATK001'))
            ->assertSet('scan.text', 'Ditambahkan: Buku Tulis 38 Lembar')
            ->assertSet('search', 'pulpen');
    }

    public function test_camera_scan_needs_an_exact_code(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('scanCode', 'ATK')
            ->assertNotDispatched('product-picked')
            ->assertSet('scan.type', 'error')
            ->assertSet('scan.unknown', 'ATK')
            ->assertDispatched('scan-unknown', code: 'ATK')
            ->call('scanCode', '   ')
            ->assertSet('scan.type', 'error');
    }

    public function test_page_has_camera_scanner(): void
    {
        $this->get('/kasir')->assertOk()
            ->assertSee('data-camera-scan', false)
            ->assertSee('barcodeScanner', false)
            ->assertSee('data-barcode-reader', false)
            ->assertSee('barcodeReader', false)
            ->assertSee('Pindai barcode');
    }

    public function test_fallback_search_in_camera_panel(): void
    {
        $c = Livewire::test(ProductPicker::class)
            ->assertSee('data-fallback-search', false)
            ->assertDontSee('Hasil pencarian cadangan')
            ->set('search', 'pulpen')
            ->assertSee('Hasil pencarian cadangan')
            ->call('pickFallback', $this->pid('ATK002'))
            ->assertDispatched('product-picked', productId: $this->pid('ATK002'))
            ->assertSet('search', '');

        \App\Models\Product::where('code', 'ATK003')->update(['stock' => 0]);
        $c->call('pickFallback', $this->pid('ATK003'))->assertNotDispatched('product-picked');
        $c->set('search', 'zzzz')->assertSee('Barang tidak ditemukan.');
    }
}
