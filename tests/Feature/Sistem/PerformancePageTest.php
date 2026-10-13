<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Performance;
use App\Support\DatabaseIndexes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PerformancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_in_nav(): void
    {
        $this->get('/sistem')->assertSee(route('sistem.performa'));
        $this->get('/sistem/performa')->assertOk()->assertSee('Cache laporan')->assertSee('Indeks basis data')
            ->assertSee('Semua indeks penting tersedia')->assertSee('Halaman lambat (7 hari)')->assertSee('Tidak ada halaman lambat.');
    }

    public function test_index_report_reads_schema(): void
    {
        $report = collect(DatabaseIndexes::report())->keyBy('table');
        $this->assertTrue(collect($report['transactions']['checks'])->every('ok'));
        $this->assertTrue(collect($report['products']['checks'])->firstWhere('column', 'code')['ok']);
        $this->assertNotNull(collect($report['transactions']['checks'])->firstWhere('column', 'created_at')['index']);
        $this->assertSame(0, Livewire::test(Performance::class)->instance()->missing);
    }

    public function test_cache_stats_clear_and_slow_pages(): void
    {
        app(\App\Services\SalesReport::class)->summary(today(), now());
        app(\App\Services\SalesReport::class)->summary(today(), now());
        $c = Livewire::test(Performance::class);
        $summary = collect($c->instance()->caches)->firstWhere('key', 'laporan:summary');
        $this->assertSame([1, 1], [$summary['hits'], $summary['misses']]);
        $this->assertNotNull($summary['age_min']);

        $v = \App\Support\ReportCache::version();
        $c->call('clearAll')->assertDispatched('toast');
        $this->assertSame($v + 1, \App\Support\ReportCache::version());
        $this->assertNull(collect($c->instance()->caches)->firstWhere('key', 'laporan:summary')['age_min']);
        $this->assertSame(92, Performance::hitRate(['hits' => 412, 'misses' => 38]));

        \Illuminate\Support\Facades\Cache::put(\App\Http\Middleware\TrackSlowRequests::KEY, ['laporan' => ['route' => 'laporan', 'path' => '/laporan', 'count' => 2, 'max_ms' => 1800, 'total_ms' => 3000, 'last_at' => now()->toDateTimeString()]]);
        Livewire::test(Performance::class)->assertSee('/laporan')->assertSee('maks 1800 ms')->assertSee('rata-rata 1500 ms');
    }

    public function test_admin_only(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/sistem/performa')->assertForbidden();
    }
}
