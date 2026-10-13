<?php

namespace Tests\Feature\Kas;

use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    public function test_record_in_and_out_on_open_shift(): void
    {
        $shift = CashShift::factory()->create(['user_id' => auth()->id(), 'opening_cash' => 100000]);

        $this->postJson('/kas/mutasi', ['type' => 'in', 'category' => 'kembalian', 'amount' => 50000, 'reason' => 'Tambah uang kembalian'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'in')
            ->assertJsonPath('data.category_label', 'Uang kembalian')
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('shift.cash_in', 50000)
            ->assertJsonPath('shift.expected', 150000);

        $this->postJson('/kas/mutasi', ['type' => 'out', 'category' => 'bendahara', 'amount' => 120000, 'reason' => 'Setor ke bendahara'])
            ->assertCreated()->assertJsonPath('shift.expected', 30000);

        $log = ActivityLog::where('event', 'cash.out')->sole();
        $this->assertSame(['kas', 120000, 'Setor ke bendahara'], [$log->category, $log->amount, $log->properties['reason']]);
    }

    public function test_withdrawal_cannot_exceed_drawer(): void
    {
        CashShift::factory()->create(['user_id' => auth()->id(), 'opening_cash' => 20000]);

        $this->postJson('/kas/mutasi', ['type' => 'out', 'category' => 'operasional', 'amount' => 25000, 'reason' => 'Beli galon'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Uang di laci tidak cukup (seharusnya Rp20.000)']);
        $this->assertSame(0, CashMovement::count());
    }

    public function test_requires_open_shift_and_valid_input(): void
    {
        $this->postJson('/kas/mutasi', ['type' => 'in', 'category' => 'kembalian', 'amount' => 5000, 'reason' => 'Tambah'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Buka shift dulu']);

        CashShift::factory()->create();
        $this->postJson('/kas/mutasi', ['type' => 'x', 'category' => 'arisan', 'amount' => 100, 'reason' => 'a'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'category', 'amount', 'reason']);
    }

    public function test_list_by_date_with_totals(): void
    {
        $shift = CashShift::factory()->create();
        CashMovement::factory()->for($shift, 'shift')->create(['amount' => 30000]);
        CashMovement::factory()->for($shift, 'shift')->out(10000)->create();
        CashMovement::factory()->for($shift, 'shift')->out(5000, 'Bayar galon')->create(['category' => 'operasional']);

        $this->getJson('/kas/mutasi')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('totals.in', 30000)
            ->assertJsonPath('totals.out', 15000)
            ->assertJsonPath('totals.net', 15000)
            ->assertJsonPath('totals.by_category.operasional.out', 5000);

        $this->getJson('/kas/mutasi?tanggal='.today()->subDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/kas/mutasi?tanggal='.today()->addDay()->toDateString())->assertUnprocessable();
    }

    public function test_pengurus_can_read_but_not_record(): void
    {
        $this->loginAs('pengurus');
        CashShift::factory()->create();

        $this->getJson('/kas/mutasi')->assertOk();
        $this->postJson('/kas/mutasi', ['type' => 'in', 'category' => 'kembalian', 'amount' => 5000, 'reason' => 'Tambah'])->assertForbidden();
    }
}
