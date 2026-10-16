<?php

namespace Tests\Feature\Kasir;

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\OfflineSale;
use App\Models\PosOfflineCatalog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RetentionService;
use App\Services\SettingsService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Kasir offline: salinan katalog bertanda, sinkron penjualan idempoten, penolakan harga/total, tanda perlu dicek. */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'perangkat-kasir-01';

    private User $kasir;

    private Product $teh;

    private Product $roti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->freezeTime();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->teh = Product::factory()->create(['name' => 'Teh Botol', 'code' => 'TEH1', 'price' => 5000, 'stock' => 10, 'is_active' => true]);
        $this->roti = Product::factory()->create(['name' => 'Roti', 'code' => 'ROTI1', 'price' => 3500, 'stock' => 1, 'is_active' => true]);
        CashShift::factory()->create(['user_id' => $this->kasir->id, 'opened_at' => now()->subHour()]);
        $this->actingAs($this->kasir, 'web');
    }

    /** Unduh salinan katalog lalu waktu maju 15 menit (perangkat baru menjual setelah offline). */
    private function catalog(): array
    {
        $c = $this->getJson('/kasir/offline/katalog?perangkat='.self::DEVICE)->assertOk()->json('data');
        $this->travel(15)->minutes();

        return $c;
    }

    private function sale(string $catalogId, array $lines, array $over = []): array
    {
        $items = [];
        $subtotal = 0;
        foreach ($lines as [$p, $qty, $price]) {
            $items[] = ['product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty, 'price' => $price ?? (int) $p->price];
            $subtotal += ($price ?? (int) $p->price) * $qty;
        }

        return $over + ['client_ref' => (string) Str::uuid(), 'catalog_id' => $catalogId, 'device_id' => self::DEVICE, 'local_number' => 'OFF-01-0001',
            'sold_at' => now()->subMinutes(10)->toIso8601String(), 'payment_method' => 'tunai', 'paid' => 20000, 'total' => $subtotal, 'items' => $items];
    }

    private function sync(array ...$sales): TestResponse
    {
        return $this->postJson('/kasir/offline/sinkron', ['penjualan' => $sales]);
    }

    public function test_catalog_snapshot_is_recorded_and_complete(): void
    {
        $c = $this->catalog();
        $this->assertSame(26, strlen($c['catalog_id']));
        $this->assertSame(['tunai', 'qris'], array_keys($c['payment_methods']));
        $this->assertNotNull($c['shift']);
        $teh = collect($c['products'])->firstWhere('id', $this->teh->id);
        $this->assertSame([5000, 10, 'TEH1'], [$teh['price'], $teh['stock'], $teh['barcode']]);
        $row = PosOfflineCatalog::find($c['catalog_id']);
        $this->assertSame([$this->kasir->id, self::DEVICE, 5000], [$row->user_id, $row->device_id, $row->prices[$this->teh->id]]);
        $this->assertSame(7 * 86400, (int) $row->issued_at->diffInSeconds($row->expires_at));
        $this->getJson('/kasir/offline/katalog?perangkat=x')->assertUnprocessable();
    }

    public function test_sale_syncs_once_with_offline_source_and_original_date(): void
    {
        $c = $this->catalog();
        $sale = $this->sale($c['catalog_id'], [[$this->teh, 2, null]]);
        $res = $this->sync($sale)->assertOk()->json('data.0');
        $this->assertSame('diterima', $res['status']);
        $tx = Transaction::find($res['transaction_id']);
        $this->assertSame(['offline', 'tunai', 10000, 20000, 10000, $this->kasir->id], [$tx->source, $tx->payment_method, (int) $tx->total, (int) $tx->paid, (int) $tx->change, $tx->user_id]);
        $this->assertTrue($tx->created_at->equalTo(now()->subMinutes(10)->startOfSecond()));
        $this->assertNotNull($tx->synced_at);
        $this->assertSame('INV-'.now()->subMinutes(10)->format('Ymd').'-0001', $tx->invoice_number);
        $this->assertSame(8, $this->teh->fresh()->stock);
        $this->assertNotNull($tx->cash_shift_id);

        $again = $this->sync($sale)->assertOk()->json('data.0');                       // kirim ulang → sama, tidak dobel
        $this->assertSame($res['invoice_number'], $again['invoice_number']);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(8, $this->teh->fresh()->stock);
        $this->assertNotNull(ActivityLog::where('event', 'sale.created')->first());
    }

    public function test_tampered_price_or_total_is_rejected_without_transaction(): void
    {
        $c = $this->catalog();
        $r = $this->sync(
            $this->sale($c['catalog_id'], [[$this->teh, 1, 100]]),                        // harga diubah di perangkat
            $this->sale($c['catalog_id'], [[$this->teh, 1, null]], ['total' => 4000]),    // total tidak cocok
            $this->sale($c['catalog_id'], [[$this->teh, 1, null]], ['paid' => 1000]),     // bayar kurang
        )->assertOk()->json('data');
        $this->assertSame(['ditolak', 'ditolak', 'ditolak'], array_column($r, 'status'));
        $this->assertStringContainsString('Harga Teh Botol', $r[0]['reason']);
        $this->assertSame(0, Transaction::count());
        $this->assertSame(3, OfflineSale::where('status', 'ditolak')->count());
        $this->assertSame(10, $this->teh->fresh()->stock);
        $this->assertSame(3, ActivityLog::where('event', 'sale.offline_rejected')->count());
    }

    public function test_price_changed_on_server_after_download_still_uses_snapshot_price(): void
    {
        $c = $this->catalog();
        $this->teh->update(['price' => 6000]);
        $r = $this->sync($this->sale($c['catalog_id'], [[$this->teh, 1, 5000]]))->json('data.0');
        $this->assertSame('diterima', $r['status']);
        $this->assertSame(5000, (int) Transaction::first()->total);
    }

    public function test_catalog_of_other_user_device_or_expired_is_rejected(): void
    {
        $c = $this->catalog();
        $this->assertSame('ditolak', $this->sync($this->sale($c['catalog_id'], [[$this->teh, 1, null]], ['device_id' => 'perangkat-lain-99']))->json('data.0.status'));
        $this->travel(8)->days();                                                              // salinan kedaluwarsa
        $this->flushSession();                                                                 // sesi lama habis → masuk lagi
        $this->actingAs($this->kasir, 'web');
        $this->assertSame('ditolak', $this->sync($this->sale($c['catalog_id'], [[$this->teh, 1, null]]))->json('data.0.status'));
        $this->travelBack();

        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
        $this->assertSame('ditolak', $this->sync($this->sale($c['catalog_id'], [[$this->teh, 1, null]]))->json('data.0.status'));
    }

    public function test_stock_shortage_and_missing_shift_are_flagged_but_recorded(): void
    {
        $c = $this->catalog();
        CashShift::query()->delete();                                                          // tidak ada shift pada jam penjualan
        $r = $this->sync($this->sale($c['catalog_id'], [[$this->roti, 3, null]], ['paid' => 10500]))->json('data.0');
        $this->assertSame('perlu_dicek', $r['status']);
        $sale = OfflineSale::first();
        $this->assertSame(['tanpa_shift', 'stok_kurang'], array_keys($sale->flags));
        $this->assertSame(2, $sale->flags['stok_kurang'][0]['shortage']);
        $this->assertSame(0, $this->roti->fresh()->stock);                               // tidak minus
        $this->assertSame(1, StockMovement::where('product_id', $this->roti->id)->value('quantity'));
        $this->assertSame(3, Transaction::first()->items()->value('quantity'));           // penjualan tetap utuh
        $this->assertNull(Transaction::first()->cash_shift_id);
    }

    public function test_invalid_shape_is_not_stored_and_batch_continues(): void
    {
        $c = $this->catalog();
        $r = $this->sync(['client_ref' => 'x'], $this->sale($c['catalog_id'], [[$this->teh, 1, null]]),
            $this->sale($c['catalog_id'], [[$this->teh, 1, null]], ['sold_at' => now()->addHour()->toIso8601String()]))->assertOk()->json('data');
        $this->assertSame(['tidak_sah', 'diterima', 'tidak_sah'], array_column($r, 'status'));
        $this->assertSame(1, OfflineSale::count());
        $this->postJson('/kasir/offline/sinkron', ['penjualan' => array_fill(0, 51, [])])->assertUnprocessable();
    }

    public function test_tax_and_rounding_from_snapshot_and_qris_exact(): void
    {
        app(SettingsService::class)->save('tax', ['enabled' => true, 'rate' => 10, 'included' => false, 'rounding' => 'up', 'rounding_unit' => 500, 'cash_only' => true]);
        $c = $this->catalog();
        app(SettingsService::class)->save('tax', ['enabled' => false]);                    // berubah setelah unduh → tetap pakai salinan
        $r = $this->sync(
            $this->sale($c['catalog_id'], [[$this->roti, 1, null]], ['total' => 4000, 'paid' => 5000]),                         // 3500 + 350 = 3850 → bulat ke 4000 (tunai)
            $this->sale($c['catalog_id'], [[$this->teh, 1, null]], ['payment_method' => 'qris', 'total' => 5500, 'paid' => 5500]), // non-tunai tanpa pembulatan
        )->json('data');
        $this->assertSame(['diterima', 'diterima'], array_column($r, 'status'));
        $tx = Transaction::where('payment_method', 'tunai')->first();
        $this->assertSame([350, 150, 4000], [(int) $tx->tax, (int) $tx->rounding, (int) $tx->total]);
    }

    public function test_requires_cashier_menu(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->getJson('/kasir/offline/katalog?perangkat='.self::DEVICE)->assertForbidden();
        $this->postJson('/kasir/offline/sinkron', ['penjualan' => [[]]])->assertForbidden();
    }

    public function test_expired_catalog_snapshots_are_purged_by_retention(): void
    {
        $old = $this->catalog()['catalog_id'];
        $this->travel(40)->days();
        $this->flushSession();
        $this->actingAs($this->kasir, 'web');
        $fresh = $this->catalog()['catalog_id'];
        $this->assertSame(1, app(RetentionService::class)->run()['katalog_offline']);
        $this->assertNull(PosOfflineCatalog::find($old));
        $this->assertNotNull(PosOfflineCatalog::find($fresh));
    }
}
