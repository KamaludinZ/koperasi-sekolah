<?php

namespace Tests\Feature\Notifikasi;

use App\Jobs\SyncStockAlerts;
use App\Models\Notification;
use App\Models\Product;
use App\Services\CheckoutService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockAlertQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_checkout_queues_one_stock_alert_job_after_commit(): void
    {
        Product::where('code', 'ATK001')->update(['stock' => 6, 'min_stock' => 5]);
        Queue::fake();
        app(CheckoutService::class)->checkout([$this->pid('ATK001') => 2, $this->pid('ATK002') => 1], 'tunai', 50000);

        Queue::assertPushedOn('notifikasi', SyncStockAlerts::class, fn ($job) => collect($job->productIds)->sort()->values()->all() === collect([$this->pid('ATK001'), $this->pid('ATK002')])->sort()->values()->all());
        Queue::assertPushed(SyncStockAlerts::class, 1);
        $this->assertFalse(Notification::where('type', 'stok')->where('product_id', $this->pid('ATK001'))->exists()); // belum diproses

        (new SyncStockAlerts([$this->pid('ATK001')]))->handle(app(\App\Services\NotificationService::class));
        $this->assertTrue(Notification::where('type', 'stok')->where('product_id', $this->pid('ATK001'))->exists());
    }

    public function test_rolled_back_change_does_not_queue(): void
    {
        Queue::fake();
        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                app(\App\Services\StockService::class)->decrease(Product::find($this->pid('ATK001')), 1);
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }
        Queue::assertNotPushed(SyncStockAlerts::class);
    }
}
