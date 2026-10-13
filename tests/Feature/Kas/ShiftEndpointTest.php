<?php

namespace Tests\Feature\Kas;

use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Services\CheckoutService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    public function test_open_shift_and_read_current(): void
    {
        $this->getJson('/kas/shift/aktif')->assertOk()->assertExactJson(['data' => null]);

        $this->postJson('/kas/shift/buka', ['name' => 'Shift pagi', 'opening_cash' => 200000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_cash', 200000)
            ->assertJsonPath('data.expected', 200000)
            ->assertJsonPath('data.cashier', auth()->user()->name);

        $this->getJson('/kas/shift/aktif')->assertOk()->assertJsonPath('data.name', 'Shift pagi');
        $log = ActivityLog::where('event', 'shift.opened')->sole();
        $this->assertSame(['kas', 200000], [$log->category, $log->amount]);
    }

    public function test_open_validation_and_single_open_shift(): void
    {
        $this->postJson('/kas/shift/buka', ['name' => 'Shift malam'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'opening_cash']);
        $this->postJson('/kas/shift/buka', ['name' => 'Shift pagi', 'opening_cash' => -1])->assertJsonValidationErrors('opening_cash');

        CashShift::factory()->create();
        $this->postJson('/kas/shift/buka', ['name' => 'Shift siang', 'opening_cash' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'Masih ada shift berjalan']);
        $this->assertSame(1, CashShift::count());
    }

    public function test_close_computes_expected_from_sales_and_movements(): void
    {
        $this->seed(ProductSeeder::class);
        $shift = CashShift::factory()->create(['user_id' => auth()->id(), 'opening_cash' => 100000]);
        app(CheckoutService::class)->checkout([$this->pid('ATK001') => 2], 'tunai', 10000); // Rp8.000 tunai
        app(CheckoutService::class)->checkout([$this->pid('MNM001') => 1], 'qris', null);   // non-tunai, tidak masuk laci
        CashMovement::factory()->for($shift, 'shift')->create(['amount' => 50000]);
        CashMovement::factory()->for($shift, 'shift')->out(20000)->create();

        $this->getJson('/kas/shift/aktif')->assertJsonPath('data.expected', 138000)->assertJsonPath('data.transactions', 2);

        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 137000])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 137000, 'note' => 'Salah kembalian'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected', 138000)
            ->assertJsonPath('data.counted', 137000)
            ->assertJsonPath('data.difference', -1000)
            ->assertJsonPath('data.closed_by', auth()->user()->name);

        $shift->refresh();
        $this->assertSame([138000, -1000, 'Salah kembalian'], [(int) $shift->expected_cash, (int) $shift->difference, $shift->close_note]);
        $this->assertSame(-1000, ActivityLog::where('event', 'shift.closed')->sole()->properties['difference']);
        $this->getJson('/kas/shift/aktif')->assertExactJson(['data' => null]);
    }

    public function test_exact_count_needs_no_note_and_cannot_close_twice(): void
    {
        $shift = CashShift::factory()->create(['user_id' => auth()->id(), 'opening_cash' => 50000]);

        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 50000])->assertOk()->assertJsonPath('data.difference', 0);
        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 50000])
            ->assertUnprocessable()->assertJsonValidationErrors(['counted_cash' => 'sudah ditutup']);
        $this->postJson('/kas/shift/999/tutup', ['counted_cash' => 0])->assertNotFound();
    }

    public function test_checkout_links_sale_to_open_shift(): void
    {
        $this->seed(ProductSeeder::class);
        $before = app(CheckoutService::class)->checkout([$this->pid('ATK002') => 1], 'qris', null);
        $shift = CashShift::factory()->create();
        $during = app(CheckoutService::class)->checkout([$this->pid('ATK002') => 1], 'qris', null);

        $this->assertNull($before->cash_shift_id);
        $this->assertSame($shift->id, $during->cash_shift_id);
    }

    public function test_pengurus_can_view_but_not_operate(): void
    {
        $this->loginAs('pengurus');
        $shift = CashShift::factory()->create();

        $this->getJson('/kas/shift/aktif')->assertOk();
        $this->postJson('/kas/shift/buka', ['name' => 'Shift pagi', 'opening_cash' => 0])->assertForbidden();
        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 0])->assertForbidden();
    }
}
