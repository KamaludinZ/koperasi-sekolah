<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Barcodes;
use App\Models\ActivityLog;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BarcodesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_page_lists_barcode_status_and_filters(): void
    {
        Product::whereKey($this->pid('ATK002'))->update(['barcode' => '8991112223334']);

        $this->get('/barang/barcode')->assertOk()->assertSee('Barcode & label')->assertSee('data-barcode="ATK001"', false)
            ->assertSee('data-barcode="8991112223334"', false);

        Livewire::test(Barcodes::class)->set('status', 'khusus')
            ->assertSee('8991112223334')->assertDontSee('data-barcode="ATK001"', false)
            ->set('status', '')->set('search', '8991112')->assertSee('Pulpen Hitam')->assertDontSee('Buku Tulis 38 Lembar');
    }

    public function test_bulk_generate_skips_custom_unless_overwrite(): void
    {
        $a = $this->pid('ATK001');
        $b = $this->pid('ATK002');
        Product::whereKey($b)->update(['barcode' => '8991112223334']);

        Livewire::test(Barcodes::class)
            ->set('selected', [(string) $a, (string) $b])
            ->assertSet('selected', [$a, $b])
            ->call('generate')
            ->assertSet('selected', []);

        $this->assertMatchesRegularExpression('/^2\d{12}$/', Product::find($a)->barcode);
        $this->assertSame('8991112223334', Product::find($b)->barcode);
        $bulk = ActivityLog::where('event', 'product.barcode_bulk')->latest('id')->first();
        $this->assertSame(['changed' => 1, 'skipped' => 1], collect($bulk->properties)->only('changed', 'skipped')->all());

        Livewire::test(Barcodes::class)->set('selected', [$b])->set('overwrite', true)->call('generate');
        $this->assertNotSame('8991112223334', Product::find($b)->barcode);

        Livewire::test(Barcodes::class)->set('selected', [$a, $b])->call('resetToCode');
        $this->assertSame(0, Product::whereNotNull('barcode')->count());
    }

    public function test_select_helpers(): void
    {
        $c = Livewire::test(Barcodes::class)->call('togglePage');
        $this->assertCount(Product::count(), $c->get('selected'));
        $c->call('togglePage')->assertSet('selected', [])
            ->set('category', 'Makanan')->call('selectAllMatching');
        $this->assertSame(Product::whereHas('category', fn ($q) => $q->where('name', 'Makanan'))->count(), count($c->get('selected')));
        $c->call('clearSelection')->assertSet('selected', [])->call('generate');
        $this->assertSame(0, Product::whereNotNull('barcode')->count());
    }

    public function test_kasir_cannot_change_barcodes(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $res = $this->get('/barang/barcode');
        if ($res->status() === 200) {
            Livewire::test(Barcodes::class)->set('selected', [$this->pid('ATK001')])->call('generate')->assertForbidden();
        } else {
            $res->assertForbidden();
        }
        $this->assertNull(Product::find($this->pid('ATK001'))->barcode);
    }
}
