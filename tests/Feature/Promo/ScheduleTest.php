<?php

namespace Tests\Feature\Promo;

use App\Livewire\Promo\Schedule;
use App\Models\Promo;
use App\Models\TransactionItem;
use App\Services\PromoService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PromoSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, TransactionSeeder::class, PromoSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_timeline_and_impact(): void
    {
        $this->get('/promo/jadwal')->assertOk()
            ->assertSeeText('Jadwal & Dampak Promo')
            ->assertSee('Linimasa promo & kupon', false)
            ->assertSee('Dampak terhadap penjualan')
            ->assertSee('MPLS2026');
    }

    public function test_window_and_bar_positions(): void
    {
        $c = Livewire::test(Schedule::class);
        [$from, $to] = $c->get('window');
        $this->assertSame(['2026-09-14', '2026-12-06'], [$from->toDateString(), $to->toDateString()]);
        $this->assertNotNull($c->get('todayAt'));

        foreach ($c->get('rows') as $r) {
            $this->assertGreaterThanOrEqual(0, $r['left']);
            $this->assertLessThanOrEqual(100.01, $r['left'] + $r['width']);
        }
        // Promo grosir mulai sebelum jendela → batang terpotong di kiri.
        $grosir = collect($c->get('rows'))->firstWhere('name', 'Grosir Buku Tulis (isi 10)');
        $this->assertTrue($grosir['clip_start']);
        $this->assertSame(0.0, (float) $grosir['left']);
    }

    public function test_moving_window_and_reset(): void
    {
        $c = Livewire::test(Schedule::class)->call('move', 1)->assertSet('offset', 4);
        $this->assertSame('2026-10-12', $c->get('window')[0]->toDateString());

        $c->call('move', -1)->call('move', -1)->call('move', -1)->assertSet('offset', -8);
        $this->assertNull($c->get('todayAt')); // hari ini di luar jendela

        $c->call('resetWindow')->assertSet('offset', 0);
    }

    public function test_impact_excludes_scheduled_promos_and_is_consistent(): void
    {
        $impacts = Livewire::test(Schedule::class)->get('impacts');
        $names = array_column($impacts, 'name');

        $this->assertNotContains('Promo Seragam Tahun Ajaran Baru', $names);
        $this->assertContains('Diskon Hari Guru', $names);
        foreach ($impacts as $i) {
            $this->assertSame($i['revenue'] - $i['before_daily'] * $i['days_running'] - $i['discount'], $i['net_effect']);
        }
        $this->assertSame(collect($impacts)->sortByDesc('net_effect')->values()->all(), $impacts);
    }

    public function test_impact_uses_sales_of_covered_items(): void
    {
        $promo = collect(app(PromoService::class)->all())->firstWhere('name', 'Grosir Buku Tulis (isi 10)');
        $impact = app(PromoService::class)->impact($promo);
        $buku = $this->pid('ATK001');

        $during = (int) TransactionItem::where('product_id', $buku)
            ->whereHas('transaction', fn ($q) => $q->whereBetween('created_at', [Carbon::parse($promo['starts_at'])->startOfDay(), today()->endOfDay()]))
            ->sum('subtotal');
        $this->assertSame($during, $impact['revenue']);
        $this->assertSame(61, $impact['days_running']);
    }

    public function test_api_timeline_and_impact(): void
    {
        $this->getJson('/promo/jadwal/data')->assertOk()
            ->assertJsonPath('meta.from', '2026-09-14')
            ->assertJsonPath('meta.to', '2026-12-06')
            ->assertJsonStructure(['data' => [['kind', 'name', 'left', 'width', 'clashes']], 'impacts']);
        $this->getJson('/promo/jadwal/data?dari=2026-10-01&sampai=2025-01-01')->assertJsonValidationErrors('sampai');

        $id = Promo::firstWhere('name', 'Promo Seragam Tahun Ajaran Baru')->id;
        $this->getJson("/promo/{$id}/dampak")->assertOk()->assertJsonPath('data.impact', null);
        $id = Promo::firstWhere('name', 'Diskon Hari Guru')->id;
        $this->getJson("/promo/{$id}/dampak")->assertOk()->assertJsonPath('data.impact.days_running', 3);
    }
}
