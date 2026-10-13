<?php

namespace Tests\Feature\Laporan;

use App\Services\CheckoutService;
use App\Services\SalesReport;
use App\Services\TransactionReturnService;
use App\Support\ReportCache;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function revenue(): int
    {
        return app(SalesReport::class)->summary(today(), now()->endOfDay())['revenue'];
    }

    public function test_cached_and_invalidated_by_sales_and_returns(): void
    {
        $tx = app(CheckoutService::class)->checkout([$this->pid('ATK001') => 2], 'tunai', 10000);
        $this->assertSame(8000, $this->revenue());

        DB::enableQueryLog();
        $this->assertSame(8000, $this->revenue());
        $this->assertCount(0, DB::getQueryLog()); // dari cache
        DB::disableQueryLog();

        app(CheckoutService::class)->checkout([$this->pid('ATK001') => 1], 'tunai', 5000);
        $this->assertSame(12000, $this->revenue()); // versi naik → hitung ulang

        $r = app(TransactionReturnService::class)->requestReturn($tx, [$tx->items->first()->id => 1], 'rusak', false, 'tunai', null, null);
        app(TransactionReturnService::class)->approve($r, auth()->user());
        $this->assertSame(8000, $this->revenue());

        $stats = ReportCache::stats()['summary'];
        $this->assertSame([1, 3], [$stats['hits'], $stats['misses']]);
        $this->assertNotNull($stats['at']);
    }

    public function test_rolled_back_sale_does_not_bump_version(): void
    {
        $v = ReportCache::version();
        try {
            DB::transaction(function () {
                app(CheckoutService::class)->checkout([$this->pid('ATK001') => 1], 'tunai', 5000);
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($v, ReportCache::version());
    }
}
