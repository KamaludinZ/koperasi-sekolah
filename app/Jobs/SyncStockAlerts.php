<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Selaraskan notifikasi stok menipis/habis untuk barang-barang tertentu di antrean `notifikasi`,
 * agar penjualan di kasir tidak menunggu pembaruan notifikasi.
 */
class SyncStockAlerts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30];

    /** @param  list<int>  $productIds */
    public function __construct(public array $productIds)
    {
        $this->onQueue(config('koperasi.queues.notifikasi', 'notifikasi'));
    }

    public function handle(NotificationService $notifications): void
    {
        $notifications->syncStockAlerts(Product::whereKey($this->productIds)->get(), $this->productIds);
    }
}
