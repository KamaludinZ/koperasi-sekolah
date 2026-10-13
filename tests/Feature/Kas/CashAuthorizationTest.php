<?php

namespace Tests\Feature\Kas;

use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    private function otherCashier(): User
    {
        $this->seed(UserSeeder::class);

        return User::factory()->create(['role_id' => auth()->user()->role_id, 'name' => 'Kasir Lain']);
    }

    public function test_cashier_cannot_close_or_record_on_someone_elses_shift(): void
    {
        $shift = CashShift::factory()->create(['user_id' => $this->otherCashier()->id]);

        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 200000])
            ->assertForbidden()->assertJsonPath('message', 'Shift hanya dapat ditutup oleh kasir yang membukanya atau admin.');
        $this->postJson('/kas/mutasi', ['type' => 'in', 'category' => 'kembalian', 'amount' => 5000, 'reason' => 'Tambah'])
            ->assertForbidden();

        $this->assertTrue($shift->fresh()->isOpen());
        $this->assertSame(0, CashMovement::count());
        $denied = ActivityLog::where('event', 'shift.denied')->get();
        $this->assertSame(['close', 'recordMovement'], $denied->pluck('properties.ability')->all());
        $this->assertSame('kas', $denied->first()->category);
    }

    public function test_admin_can_close_any_shift(): void
    {
        $shift = CashShift::factory()->create(['user_id' => $this->otherCashier()->id, 'opening_cash' => 100000]);
        $this->loginAs('admin');

        $this->postJson('/kas/mutasi', ['type' => 'out', 'category' => 'bendahara', 'amount' => 40000, 'reason' => 'Setor ke bendahara'])->assertCreated();
        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 60000])->assertOk()->assertJsonPath('data.closed_by', 'Admin Koperasi');
    }

    public function test_cashier_sees_only_own_history_and_detail(): void
    {
        $mine = CashShift::factory()->closed()->create(['user_id' => auth()->id(), 'opened_at' => now()->subDay(), 'closed_at' => now()->subDay()->addHours(3)]);
        $theirs = CashShift::factory()->create(['user_id' => $this->otherCashier()->id]);

        $res = $this->getJson('/kas/shift/riwayat?petugas='.$theirs->user_id)->assertOk();
        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
        $this->assertSame([auth()->id()], array_column($res->json('per_cashier'), 'user_id'));
        $this->assertSame([auth()->id()], array_column($res->json('cashiers'), 'id'));

        $this->getJson("/kas/shift/{$mine->id}/rincian")->assertOk();
        $this->getJson("/kas/shift/{$theirs->id}/rincian")->assertForbidden();

        $this->loginAs('pengurus');
        $this->assertCount(2, $this->getJson('/kas/shift/riwayat')->json('data'));
        $this->getJson("/kas/shift/{$theirs->id}/rincian")->assertOk();
    }

    public function test_large_difference_notifies_pengurus_once(): void
    {
        $shift = CashShift::factory()->create(['user_id' => auth()->id(), 'opening_cash' => 100000]);

        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 85000, 'note' => 'Uang hilang'])->assertOk();

        $n = Notification::where('dedupe_key', "kas:selisih:{$shift->id}")->sole();
        $this->assertSame(['sistem', 'pengurus', 'Selisih kas Shift pagi kurang Rp15.000'], [$n->type, $n->target, $n->title]);
        $this->assertStringContainsString('Catatan: Uang hilang', $n->message);
        $this->assertTrue(ActivityLog::where('event', 'shift.closed')->sole()->properties['flagged']);
    }

    public function test_small_difference_does_not_notify(): void
    {
        $shift = CashShift::factory()->create(['user_id' => auth()->id(), 'opening_cash' => 100000]);
        $this->postJson("/kas/shift/{$shift->id}/tutup", ['counted_cash' => 99000, 'note' => 'Salah kembalian'])->assertOk();

        $this->assertSame(0, Notification::where('type', 'sistem')->count());
        $this->assertNull(ActivityLog::where('event', 'shift.closed')->sole()->properties['flagged'] ?? null);
    }
}
