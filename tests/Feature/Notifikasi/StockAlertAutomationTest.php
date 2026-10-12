<?php

namespace Tests\Feature\Notifikasi;

use App\Models\Notification;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\MinStockService;
use App\Services\NotificationService;
use App\Services\ProductService;
use App\Services\StockService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Peringatan stok dibuat/dihapus otomatis mengikuti perubahan stok, batas minimum, dan status barang. */
class StockAlertAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class); // 1 habis + 3 menipis
    }

    private function alerts(Product $p): array
    {
        return Notification::where('type', 'stok')->where('product_id', $p->id)->pluck('title')->all();
    }

    private function product(string $code): Product
    {
        return Product::where('code', $code)->firstOrFail();
    }

    public function test_seeded_low_stock_products_already_have_alerts(): void
    {
        $this->assertSame(4, Notification::where('type', 'stok')->count());
    }

    public function test_sale_that_drops_stock_below_minimum_notifies_pengurus(): void
    {
        $buku = $this->product('ATK001'); // stok 120, minimum 20
        $this->assertSame([], $this->alerts($buku));

        app(CheckoutService::class)->checkout([$buku->id => 105], 'qris', null);
        $this->assertSame(['Stok Buku Tulis 38 Lembar menipis'], $this->alerts($buku));

        app(CheckoutService::class)->checkout([$buku->id => 15], 'qris', null);
        $this->assertSame(['Stok Buku Tulis 38 Lembar habis'], $this->alerts($buku)); // menipis → habis: diganti

        $this->loginAs('pengurus');
        $this->getJson('/notifikasi/terbaru')->assertJsonPath('data.0.title', 'Stok Buku Tulis 38 Lembar habis');
        $this->loginAs('kasir');
        $this->assertNotContains('Stok Buku Tulis 38 Lembar habis', array_column($this->getJson('/notifikasi/data')->json('data'), 'title'));
    }

    public function test_restock_clears_alert_and_new_shortage_is_unread_again(): void
    {
        $penggaris = $this->product('ATK005'); // habis
        $id = Notification::where('product_id', $penggaris->id)->value('id');
        $this->loginAs('pengurus');
        app(NotificationService::class)->mark(auth()->user(), [$id]);

        app(StockService::class)->increase($penggaris, 50, 'Belanja grosir');
        $this->assertSame([], $this->alerts($penggaris));

        app(StockService::class)->adjustTo($penggaris, 0, 'Stok opname');
        $fresh = auth()->user()->inbox()->where('product_id', $penggaris->id)->first();
        $this->assertSame('Stok Penggaris 30 cm habis', $fresh->title);
        $this->assertFalse($fresh->is_read);
    }

    public function test_min_stock_changes_create_and_clear_alerts(): void
    {
        $buku = $this->product('ATK001'); // stok 120

        app(MinStockService::class)->updateMany([$buku->id => 150]);
        $this->assertSame(['Stok Buku Tulis 38 Lembar menipis'], $this->alerts($buku));

        app(MinStockService::class)->applyToCategory(null, 0); // semua barang batas 0
        $this->assertSame([], $this->alerts($buku));
        $this->assertSame(['Stok Penggaris 30 cm habis'], Notification::where('type', 'stok')->pluck('title')->all()); // habis tetap
    }

    public function test_deactivating_or_deleting_product_removes_alert(): void
    {
        $penggaris = $this->product('ATK005');
        $penggaris->update(['is_active' => false]);
        $this->assertSame([], $this->alerts($penggaris));

        $penggaris->update(['is_active' => true]);
        $this->assertCount(1, $this->alerts($penggaris));

        $penggaris->delete();
        $this->assertSame(0, Notification::where('type', 'stok')->whereNull('product_id')->count());
    }

    public function test_new_product_without_stock_is_flagged(): void
    {
        $data = ['code' => 'MKN099', 'name' => 'Roti Sobek', 'category' => 'Makanan', 'price' => 6000, 'cost_price' => 4500, 'stock' => 0, 'min_stock' => 5, 'unit' => 'pcs'];
        $product = app(ProductService::class)->create($data);

        $this->assertSame(['Stok Roti Sobek habis'], $this->alerts($product));
    }

    public function test_rolled_back_change_does_not_notify(): void
    {
        $buku = $this->product('ATK001');

        try {
            DB::transaction(function () use ($buku) {
                app(StockService::class)->decrease($buku, 110, 'Uji');
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(120, $buku->fresh()->stock);
        $this->assertSame([], $this->alerts($buku));

        app(StockService::class)->decrease($buku, 1, 'Jual'); // antrean tetap berjalan sesudahnya
        $this->assertSame([], $this->alerts($buku));
    }

    public function test_scheduled_command_resyncs(): void
    {
        Notification::where('type', 'stok')->delete();

        $this->artisan('notifikasi:stok')->expectsOutputToContain('4 notifikasi baru')->assertSuccessful();
        $this->assertSame(4, Notification::where('type', 'stok')->count());
    }
}
