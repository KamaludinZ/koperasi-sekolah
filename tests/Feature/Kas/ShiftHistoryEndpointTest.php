<?php

namespace Tests\Feature\Kas;

use App\Models\CashShift;
use App\Models\User;
use Database\Seeders\CashShiftSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftHistoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->seed([ProductSeeder::class, TransactionSeeder::class, CashShiftSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_history_lists_newest_first_with_per_cashier_summary(): void
    {
        $res = $this->getJson('/kas/shift/riwayat')->assertOk();

        $this->assertSame(12, $res->json('meta.total')); // 6 hari buka × 2 shift (7 hari, Minggu tutup)
        $this->assertSame('open', $res->json('data.0.status'));
        $this->assertSame('2026-10-14', substr($res->json('data.0.opened_at'), 0, 10));

        $per = collect($res->json('per_cashier'));
        $closed = CashShift::where('status', 'closed')->where('opened_at', '>=', '2026-10-08');
        $this->assertSame(12, $per->sum('shifts'));
        $this->assertSame((int) $closed->sum('difference'), $per->sum('difference'));
        $this->assertSame($closed->count(), $per->sum(fn ($p) => $p['short'] + $p['over'] + $p['exact']));
        $this->assertSame($per->sortBy('difference')->values()->all(), $per->all());
        $this->assertNotEmpty($res->json('cashiers'));
    }

    public function test_filter_by_cashier_and_status(): void
    {
        $userId = CashShift::value('user_id');

        $rows = $this->getJson("/kas/shift/riwayat?periode=30-hari&petugas={$userId}")->assertOk()->json('data');
        $name = User::find($userId)->name;
        $this->assertSame([$name], array_values(array_unique(array_column($rows, 'cashier'))));

        foreach ($this->getJson('/kas/shift/riwayat?periode=30-hari&status=kurang')->json('data') as $r) {
            $this->assertLessThan(0, $r['difference']);
        }
        foreach ($this->getJson('/kas/shift/riwayat?periode=30-hari&status=pas')->json('data') as $r) {
            $this->assertSame(0, $r['difference']);
        }
        $this->assertSame(['open'], array_column($this->getJson('/kas/shift/riwayat?status=berjalan')->json('data'), 'status'));

        $this->getJson('/kas/shift/riwayat?status=ngawur&petugas=99999')->assertJsonValidationErrors(['status', 'petugas']);
    }

    public function test_pagination(): void
    {
        $res = $this->getJson('/kas/shift/riwayat?periode=30-hari')->assertOk();
        $this->assertSame(24, $res->json('meta.total'));
        $this->assertCount(24, $res->json('data')); // muat satu halaman (25)

        $res = $this->getJson('/kas/shift/riwayat?dari=2026-10-01&sampai=2026-10-14&page=2')->assertOk();
        $this->assertSame(2, $res->json('meta.current_page'));
        $this->assertCount(0, $res->json('data'));
    }

    public function test_shift_detail_has_movements_and_sales(): void
    {
        $shift = CashShift::where('status', 'closed')->has('movements')->first();

        $res = $this->getJson("/kas/shift/{$shift->id}/rincian")->assertOk();
        $this->assertSame($shift->movements()->count(), count($res->json('data.cash_movements')));
        $this->assertSame($shift->transactions()->count(), count($res->json('data.sales')));
        $this->assertSame($shift->cashSales(), $res->json('data.cash_sales'));
        $this->getJson('/kas/shift/99999/rincian')->assertNotFound();
    }
}
