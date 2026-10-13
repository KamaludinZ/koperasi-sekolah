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
            ->assertSee('Semua indeks penting tersedia')->assertSee('Halaman lambat minggu ini');
    }

    public function test_index_report_reads_schema(): void
    {
        $report = collect(DatabaseIndexes::report())->keyBy('table');
        $this->assertTrue(collect($report['transactions']['checks'])->every('ok'));
        $this->assertTrue(collect($report['products']['checks'])->firstWhere('column', 'code')['ok']);
        $this->assertNotNull(collect($report['transactions']['checks'])->firstWhere('column', 'created_at')['index']);
        $this->assertSame(0, Livewire::test(Performance::class)->instance()->missing);
    }

    public function test_clear_cache_simulated(): void
    {
        $c = Livewire::test(Performance::class)->call('clear', 'laporan:grafik')->assertDispatched('toast');
        $this->assertNull(collect($c->instance()->caches)->firstWhere('key', 'laporan:grafik')['age_min']);
        $c->call('clearAll');
        $this->assertSame([null], array_values(array_unique(array_column($c->instance()->caches, 'age_min'))));
        $this->assertSame(92, Performance::hitRate(['hits' => 412, 'misses' => 38]));
        $this->assertSame(0, Performance::hitRate(['hits' => 0, 'misses' => 0]));
    }

    public function test_admin_only(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/sistem/performa')->assertForbidden();
    }
}
