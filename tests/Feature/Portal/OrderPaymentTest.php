<?php

namespace Tests\Feature\Portal;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->loginAs('kasir@koperasi.test');
        $this->andi = Member::factory()->create(['name' => 'Andi', 'password' => 'rahasia123']);
    }

    private function place(string $method = 'tunai'): Order
    {
        return app(OrderService::class)->place($this->andi, [$this->pid('ATK001') => 2], ['fulfillment' => 'ambil', 'payment_method' => $method]);
    }

    public function test_cash_payment_goes_into_open_shift(): void
    {
        $shift = CashShift::factory()->create(['user_id' => auth()->id()]);
        $o = $this->place();
        $this->postJson("/pesanan/{$o->id}/bayar", ['metode' => 'tunai'])->assertOk()->assertJsonPath('data.payment_status', 'lunas');
        $mv = CashMovement::where('cash_shift_id', $shift->id)->latest('id')->first();
        $this->assertSame([8000, CashMovement::TYPE_IN], [(int) $mv->amount, $mv->type]);
        $this->assertStringContainsString($o->code, $mv->reason);
        $this->postJson("/pesanan/{$o->id}/bayar", ['metode' => 'tunai'])->assertJsonValidationErrors('order');
    }

    public function test_qris_recalculates_without_cash_rounding_and_balance_orders_rejected(): void
    {
        app(SettingsService::class)->save('tax', ['enabled' => true, 'rate' => 11, 'included' => false, 'label' => 'PPN', 'rounding' => 'up', 'rounding_unit' => 500, 'cash_only' => true]);
        $o = $this->place(); // dibuat tunai: Rp8.880 → dibulatkan Rp9.000
        $this->assertSame(9000, (int) $o->total);
        $this->postJson("/pesanan/{$o->id}/bayar", ['metode' => 'qris'])->assertOk()->assertJsonPath('data.total', 8880)->assertJsonPath('data.payment_method', 'qris');

        app(\App\Services\SavingsService::class)->deposit($this->andi, 20000);
        $saldo = $this->place('tabungan');
        $this->postJson("/pesanan/{$saldo->id}/bayar", ['metode' => 'tunai'])->assertJsonValidationErrors('order');
        $this->postJson("/pesanan/{$o->id}/bayar", ['metode' => 'utang'])->assertJsonValidationErrors('metode');
    }

    public function test_requires_transaksi_menu(): void
    {
        $o = $this->place();
        auth()->logout();
        $this->postJson("/pesanan/{$o->id}/bayar", ['metode' => 'tunai'])->assertUnauthorized();
    }

    public function test_status_transitions_and_history(): void
    {
        $o = $this->place();
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'siap'])->assertJsonValidationErrors('status'); // lompat
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'diproses', 'catatan' => 'Sedang disiapkan'])->assertOk()->assertJsonPath('data.status', 'diproses');
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'siap'])->assertOk();
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'selesai'])->assertJsonValidationErrors('status'); // belum dibayar
        $this->postJson("/pesanan/{$o->id}/bayar", ['metode' => 'tunai'])->assertOk();
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'selesai'])->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'menunggu'])->assertJsonValidationErrors('status');
        $this->postJson("/pesanan/{$o->id}/batal", ['alasan' => 'Telat'])->assertJsonValidationErrors('reason');

        $o->refresh();
        $this->assertNotNull($o->completed_at);
        $logs = $o->statusLogs()->get();
        $this->assertSame(['menunggu', 'diproses', 'siap', 'siap', 'selesai'], $logs->pluck('to_status')->all());
        $this->assertSame('Sedang disiapkan', $logs[1]->note);
        $this->assertSame(auth()->user()->name, $logs[4]->actor_name);
        $this->assertSame(3, \App\Models\ActivityLog::where('event', 'order.status')->count());
    }

    public function test_staff_cancel_endpoint(): void
    {
        $o = $this->place();
        $this->postJson("/pesanan/{$o->id}/status", ['status' => 'dibatalkan'])->assertJsonValidationErrors('status');
        $this->postJson("/pesanan/{$o->id}/batal", [])->assertJsonValidationErrors('alasan');
        $this->postJson("/pesanan/{$o->id}/batal", ['alasan' => 'Stok rusak'])->assertOk()->assertJsonPath('data.status', 'dibatalkan');
    }
}
