<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use App\Support\BarcodeImage;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeImageEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_svg_and_png(): void
    {
        $id = $this->pid('ATK001');
        $svg = $this->get("/barang/{$id}/barcode.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<?xml', $svg->getContent());
        $this->assertStringContainsString('<desc>ATK001</desc>', $svg->getContent());

        $png = $this->get("/barang/{$id}/barcode.png?modul=3&tinggi=80")->assertOk()->assertHeader('Content-Type', 'image/png');
        [$w, $h] = getimagesizefromstring($png->getContent());
        $this->assertSame(80, $h);
        $this->assertGreaterThan(200, $w);

        $etag = $svg->headers->get('ETag');
        $this->get("/barang/{$id}/barcode.svg", ['If-None-Match' => $etag])->assertStatus(304);
        Product::whereKey($id)->update(['barcode' => '2001234567893']);
        $this->get("/barang/{$id}/barcode.svg", ['If-None-Match' => $etag])->assertOk()->assertSee('2001234567893', false);

        $this->get("/barang/{$id}/barcode.gif")->assertNotFound();
        $this->get('/barang/999999/barcode.svg')->assertNotFound();
        $this->assertNull(BarcodeImage::png(''));
    }

    public function test_requires_staff_login(): void
    {
        auth()->logout();
        $this->get('/barang/'.$this->pid('ATK001').'/barcode.svg')->assertRedirect('/masuk');
    }
}
