<?php

namespace Tests\Feature\Kas;

use App\Livewire\Kas\Shifts;
use App\Models\CashShift;
use Database\Seeders\CashShiftSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ShiftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00'); // shift siang hari ini masih berjalan
        $this->seed([ProductSeeder::class, TransactionSeeder::class, CashShiftSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_lists_shifts_of_last_seven_days(): void
    {
        $this->get('/kas/shift')->assertOk()->assertSee('Riwayat & Selisih Shift', false)->assertSee('Selisih per petugas');

        $c = Livewire::test(Shifts::class);
        $all = $c->get('all');
        $this->assertSame(12, count($all)); // 6 hari buka × 2 shift (Minggu tutup)
        $this->assertSame('2026-10-14', $all[0]['date']);
        $this->assertSame('open', $all[0]['status']);
        $this->assertSame($all, $c->get('shifts'));
    }

    public function test_filter_by_cashier_and_status(): void
    {
        $c = Livewire::test(Shifts::class)->call('setPeriod', '30-hari');
        $name = $c->get('cashiers')[0];

        $c->set('cashier', $name);
        $this->assertNotEmpty($c->get('shifts'));
        $this->assertSame([$name], array_values(array_unique(array_column($c->get('shifts'), 'cashier'))));

        $c->call('setStatus', 'kurang');
        foreach ($c->get('shifts') as $s) {
            $this->assertLessThan(0, $s['difference']);
            $this->assertSame($name, $s['cashier']);
        }

        $c->call('setStatus', 'berjalan')->set('cashier', '');
        $this->assertSame(['open'], array_values(array_unique(array_column($c->get('shifts'), 'status'))));

        $c->call('resetFilters')->assertSet('cashier', '')->assertSet('status', '');
        $c->call('setStatus', 'ngawur')->assertSet('status', '');
    }

    public function test_per_cashier_summary_adds_up(): void
    {
        $c = Livewire::test(Shifts::class)->call('setPeriod', 'bulan-lalu');
        $closed = collect($c->get('all'))->where('status', 'closed');

        $this->assertSame(count($c->get('all')), array_sum(array_column($c->get('perCashier'), 'shifts')));
        $this->assertSame($closed->sum('difference'), array_sum(array_column($c->get('perCashier'), 'difference')));
        $this->assertSame($closed->count(), collect($c->get('perCashier'))->sum(fn ($p) => $p['short'] + $p['over'] + $p['exact']));
    }

    public function test_query_string_and_access(): void
    {
        $this->get('/kas/shift?status=lebih&periode=30-hari')->assertOk()->assertSee('Lebih');
        $this->loginAs('kasir');
        $this->get('/kas/shift')->assertOk()->assertSee('Riwayat shift');
    }

    public function test_cashier_sees_only_own_shifts(): void
    {
        $this->loginAs('kasir');
        $mine = CashShift::where('user_id', auth()->id())->where('opened_at', '>=', '2026-10-08')->count();

        $all = Livewire::test(Shifts::class)->get('all');
        $this->assertCount($mine, $all);
        $this->assertSame([auth()->user()->name], array_values(array_unique(array_column($all, 'cashier'))));
    }
}
