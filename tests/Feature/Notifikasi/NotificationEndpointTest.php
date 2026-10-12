<?php

namespace Tests\Feature\Notifikasi;

use App\Models\Notification;
use App\Models\Product;
use App\Services\NotificationService;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        $this->seed([ProductSeeder::class, NotificationSeeder::class]); // 4 stok + 3 pengumuman/2 sistem
    }

    public function test_list_returns_latest_first_with_counts(): void
    {
        $res = $this->getJson('/notifikasi/data')->assertOk()
            ->assertJsonPath('meta.counts', ['semua' => 9, 'belum' => 9, 'stok' => 4, 'pengumuman' => 3, 'sistem' => 2])
            ->assertJsonPath('meta.unread', 9)
            ->assertJsonStructure(['data' => [['id', 'type', 'type_label', 'title', 'message', 'target', 'target_label', 'url', 'is_read', 'author', 'created_at']]]);

        $dates = array_column($res->json('data'), 'created_at');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);
        $this->assertContains('Stok Penggaris 30 cm habis', array_column($res->json('data'), 'title'));
    }

    public function test_filters_and_limit(): void
    {
        $this->assertCount(4, $this->getJson('/notifikasi/data?tampil=stok')->json('data'));
        $this->assertSame(['sistem', 'sistem'], array_column($this->getJson('/notifikasi/data?tampil=sistem')->json('data'), 'type'));
        $this->assertCount(2, $this->getJson('/notifikasi/data?batas=2')->json('data'));
        $this->getJson('/notifikasi/data?tampil=rahasia')->assertJsonValidationErrors('tampil');
        $this->getJson('/notifikasi/data?batas=1000')->assertJsonValidationErrors('batas');

        app(NotificationService::class)->mark(auth()->user(), [Notification::first()->id]);
        $this->getJson('/notifikasi/data?tampil=belum')->assertJsonCount(8, 'data')->assertJsonPath('meta.unread', 8);
    }

    public function test_latest_for_bell(): void
    {
        $this->getJson('/notifikasi/terbaru')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.unread', 9);
        $this->getJson('/notifikasi/terbaru?batas=2')->assertJsonCount(2, 'data');
    }

    public function test_role_targeting(): void
    {
        $this->loginAs('kasir'); // tidak menerima peringatan stok (untuk pengurus) & info sistem pengurus
        $titles = array_column($this->getJson('/notifikasi/data')->json('data'), 'title');
        $this->assertNotContains('Stok Penggaris 30 cm habis', $titles);
        $this->assertNotContains('Rekap penjualan bulan lalu siap', $titles);
        $this->assertContains('Seragam baru datang', $titles);

        $this->loginAs('pengurus');
        $this->assertNotContains('Seragam baru datang', array_column($this->getJson('/notifikasi/data')->json('data'), 'title'));
    }

    public function test_read_state_is_per_user(): void
    {
        $this->loginAs('pengurus');
        app(NotificationService::class)->markAll(auth()->user());
        $this->getJson('/notifikasi/terbaru')->assertJsonPath('meta.unread', 0);

        $this->loginAs('admin');
        $this->getJson('/notifikasi/terbaru')->assertJsonPath('meta.unread', 9);
    }

    public function test_cannot_mark_notifications_outside_own_inbox(): void
    {
        $this->loginAs('kasir');
        $stock = Notification::where('type', 'stok')->first();

        $this->assertSame([], app(NotificationService::class)->mark(auth()->user(), [$stock->id]));
        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_stock_alerts_sync_once_and_clear_when_restocked(): void
    {
        $service = app(NotificationService::class);
        $this->assertSame(0, $service->syncStockAlerts()); // sudah ada, tidak dobel

        $penggaris = Product::where('name', 'Penggaris 30 cm')->first();
        $penggaris->update(['stock' => 100]); // otomatis (ProductObserver)
        $this->assertFalse(Notification::where('product_id', $penggaris->id)->exists());

        $penggaris->update(['stock' => 0]);
        $this->assertSame(0, $service->syncStockAlerts()); // sudah dibuat otomatis
        $this->assertSame(['Stok Penggaris 30 cm habis'], Notification::where('product_id', $penggaris->id)->pluck('title')->all());
    }

    public function test_requires_login(): void
    {
        auth()->logout();
        $this->getJson('/notifikasi/data')->assertUnauthorized();
        $this->getJson('/notifikasi/terbaru')->assertUnauthorized();
    }
}
