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

    public function test_staff_list_and_detail_endpoints(): void
    {
        $a = $this->place();
        $b = $this->place();
        app(OrderService::class)->cancel($b, 'Batal uji');

        $this->getJson('/pesanan/data')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.code', $a->code)
            ->assertJsonPath('meta.counts.menunggu', 1)->assertJsonPath('meta.counts.dibatalkan', 1);
        $this->getJson('/pesanan/data?status=dibatalkan')->assertJsonPath('meta.total', 1);
        $this->getJson('/pesanan/data?q=andi')->assertJsonPath('meta.total', 2);
        $this->getJson("/pesanan/{$a->id}/data")->assertOk()->assertJsonPath('data.member.name', 'Andi')
            ->assertJsonPath('data.next', ['diproses'])->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_staff_page_full_flow(): void
    {
        $o = $this->place();
        $this->get('/pesanan')->assertOk()->assertSee($o->code)->assertSee('Pesanan anggota');
        $c = \Livewire\Livewire::test(\App\Livewire\Transaksi\MemberOrders::class)->call('show', $o->id)->assertSee('data-order-actions', false)
            ->set('note', 'Disiapkan Bu Rina')->call('advance', 'diproses')->assertHasNoErrors()
            ->call('advance', 'siap')->call('advance', 'selesai')->assertHasErrors('action')->assertSee('Catat pembayaran dulu')
            ->call('markPaid', 'tunai')->call('advance', 'selesai')->assertHasNoErrors()->assertDontSee('data-order-actions', false);
        $this->assertSame(['selesai', 'lunas'], [$o->fresh()->status, $o->fresh()->payment_status]);
        $this->assertSame('Disiapkan Bu Rina', $o->statusLogs()->where('to_status', 'diproses')->value('note'));

        $x = $this->place();
        $c->call('show', $x->id)->set('cancelling', true)->set('reason', 'Stok habis')->call('cancel')->assertHasNoErrors();
        $this->assertSame('dibatalkan', $x->fresh()->status);
    }

    public function test_completed_order_recorded_as_sale_once(): void
    {
        $shift = CashShift::factory()->create(['user_id' => auth()->id()]);
        $stockBefore = \App\Models\Product::find($this->pid('ATK001'))->stock;
        $o = $this->place(); // stok langsung dicadangkan: −2
        $svc = app(OrderService::class);
        foreach (['diproses', 'siap'] as $to) {
            $svc->transition($o, $to, auth()->user());
        }
        $svc->markPaid($o, 'tunai', auth()->user());
        $svc->transition($o, 'selesai', auth()->user());

        $tx = \App\Models\Transaction::with('items')->sole();
        $this->assertSame($tx->id, $o->fresh()->transaction_id);
        $this->assertSame(['tunai', $this->andi->id, 8000, null], [$tx->payment_method, $tx->member_id, (int) $tx->total, $tx->cash_shift_id]);
        $this->assertSame([2, 'Buku Tulis 38 Lembar'], [$tx->items[0]->quantity, $tx->items[0]->product_name]);
        $this->assertSame($stockBefore - 2, \App\Models\Product::find($this->pid('ATK001'))->stock); // tidak dipotong dua kali
        $this->assertSame(8000, $shift->fresh()->expectedCash() - (int) $shift->opening_cash); // tunai masuk laci sekali (mutasi kas)

        $report = app(\App\Services\SalesReport::class)->summary(today(), now()->endOfDay());
        $this->assertSame(8000, $report['revenue']);
        $this->get('/transaksi')->assertSee($tx->invoice_number);
    }
}
