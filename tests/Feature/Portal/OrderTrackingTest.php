<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\OrderShow;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\SavingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->andi = Member::factory()->create(['name' => 'Andi', 'password' => '246810']);
        $this->actingAsMember($this->andi);
    }

    private function place(string $payment = 'tunai', ?Member $m = null): Order
    {
        return app(OrderService::class)->place($m ?? $this->andi, [$this->pid('ATK001') => 2], ['fulfillment' => 'ambil', 'payment_method' => $payment]);
    }

    public function test_list_and_detail_with_steps(): void
    {
        $o = $this->place();
        $this->get('/portal/pesanan')->assertOk()->assertSee($o->code)->assertSee('Menunggu');
        $this->get('/portal/pesanan/'.$o->id)->assertOk()->assertSee('menunggu disiapkan petugas')->assertSee('Buku Tulis 38 Lembar')
            ->assertSee('Pesanan dibuat dari portal anggota')->assertSee('wire:poll.30s.visible', false);

        $o->update(['status' => 'siap']);
        Livewire::test(OrderShow::class, ['order' => $o->id])->assertSee('Barang sudah siap')->assertDontSee('Batalkan pesanan…');
    }

    public function test_member_cancel_restores_stock_and_refunds_balance(): void
    {
        app(SavingsService::class)->deposit($this->andi, 10000);
        $stock = Product::find($this->pid('ATK001'))->stock;
        $o = $this->place('tabungan');
        $this->assertEquals(2000, (float) $this->andi->fresh()->balance);

        Livewire::test(OrderShow::class, ['order' => $o->id])->call('cancel')->assertHasErrors('reason')
            ->set('reason', 'Salah pilih barang')->call('cancel')->assertHasNoErrors()->assertSee('Pesanan dibatalkan: Salah pilih barang')
            ->assertSee('Saldo sudah dikembalikan');

        $o->refresh();
        $this->assertSame(['dibatalkan', 'dikembalikan'], [$o->status, $o->payment_status]);
        $this->assertSame($stock, Product::find($this->pid('ATK001'))->stock);
        $this->assertEquals(10000, (float) $this->andi->fresh()->balance);
        $this->assertSame(['menunggu', 'dibatalkan'], $o->statusLogs()->pluck('to_status')->all());
    }

    public function test_member_cannot_cancel_processed_or_view_others(): void
    {
        $o = $this->place();
        $o->update(['status' => 'diproses']);
        Livewire::test(OrderShow::class, ['order' => $o->id])->set('reason', 'Berubah pikiran')->call('cancel')->assertHasErrors('reason');
        $this->assertSame('diproses', $o->fresh()->status);

        $other = $this->place('tunai', Member::factory()->create(['password' => 'rahasia1']));
        $this->get('/portal/pesanan/'.$other->id)->assertNotFound();
        $this->get('/portal/pesanan')->assertDontSee($other->code);
    }
}
