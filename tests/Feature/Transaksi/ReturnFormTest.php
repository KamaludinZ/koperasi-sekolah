<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\ReturnForm;
use App\Models\Member;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnFormTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        // Transaksi terbaru → tidak punya pengajuan tiruan (data tiruan dimulai dari transaksi kedua terbaru).
        $this->tx = Transaction::factory()->withItems([$this->pid('ATK001') => 3, $this->pid('ATK002') => 1])->at(now()->subHour()->toDateTimeString())->create();
    }

    public function test_detail_links_to_return_form_within_limit(): void
    {
        $this->get(route('transaksi.show', $this->tx))->assertOk()->assertSee(route('transaksi.retur.buat', $this->tx), false);
        $old = Transaction::factory()->withItems()->at(now()->subDays(5)->toDateTimeString())->create();
        $this->get(route('transaksi.show', $old))->assertOk()->assertDontSee(route('transaksi.retur.buat', $old), false);
        $this->get(route('transaksi.retur.buat', $old))->assertOk()->assertSee('sudah lewat batas retur 3 hari');
    }

    public function test_form_lists_items_and_computes_refund(): void
    {
        $line = $this->tx->items()->where('product_id', $this->pid('ATK001'))->first();
        $c = Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id])
            ->assertSee($this->tx->invoice_number)->assertSee($line->product_name)->assertSee('maks. 3')
            ->assertSet('refundMethod', 'tunai');
        $this->assertSame(['tunai' => 'Uang tunai'], $c->instance()->refundOptions);

        $c->set("qty.{$line->id}", 2);
        $this->assertSame(['units' => 2, 'amount' => 2 * (int) $line->price], $c->instance()->summary);
        $c->call('returnAll');
        $this->assertSame(4, $c->instance()->summary['units']);
    }

    public function test_discount_is_spread_over_refund(): void
    {
        $half = (float) $this->tx->subtotal / 2;
        $this->tx->update(['discount' => $half, 'total' => $half, 'paid' => $half, 'change' => 0]);
        $line = $this->tx->items()->first();
        $c = Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id])->set("qty.{$line->id}", 1);
        $this->assertSame((int) round($line->price / 2), $c->instance()->summary['amount']);
        $c->assertSee('harga bersih transaksi');
    }

    public function test_validation_and_mock_submit(): void
    {
        $line = $this->tx->items()->where('product_id', $this->pid('ATK001'))->first();
        $c = Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id]);

        $c->set('reason', 'rusak')->call('submit')->assertHasErrors('qty')->assertSee('Isi jumlah barang yang diretur.');
        $c->set("qty.{$line->id}", 4)->call('submit')->assertHasErrors("qty.{$line->id}");
        $c->set("qty.{$line->id}", 1)->set('reason', '')->call('submit')->assertHasErrors(['reason' => 'required']);
        $c->set('reason', 'lainnya')->call('submit')->assertHasErrors(['note' => 'required']);
        $c->set('note', 'Salah warna')->set('refundMethod', 'tabungan')->call('submit')->assertHasErrors('refundMethod');
        $c->set('refundMethod', 'tunai')->set('condition', 'rusak')->call('submit')->assertHasNoErrors()->assertRedirect(route('transaksi.retur'));
        $this->assertStringContainsString('Pengajuan retur RTR-', session('toast')['message']);
        $r = \App\Models\TransactionReturn::sole();
        $this->assertSame(['menunggu', 'lainnya', 'Salah warna', false, 1, auth()->id()], [$r->status, $r->reason_key, $r->note, $r->restock, $r->quantity, $r->requested_by]);
        $this->assertSame(3, $line->fresh()->quantity); // transaksi tidak berubah sampai disetujui
    }

    public function test_refund_options_follow_payment_method(): void
    {
        $this->tx->update(['payment_method' => 'qris']);
        $this->assertSame(['tunai', 'qris'], array_keys(Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id])->instance()->refundOptions));

        $member = Member::factory()->create();
        $this->tx->update(['payment_method' => 'tunai', 'member_id' => $member->id]);
        $this->assertSame(['tunai', 'tabungan'], array_keys(Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id])->instance()->refundOptions));
    }

    public function test_already_returned_items_reduce_limit(): void
    {
        $line = $this->tx->items()->where('product_id', $this->pid('ATK001'))->first();
        $r = app(\App\Services\TransactionReturnService::class)->requestReturn($this->tx, [$line->id => 2], 'rusak', false, 'tunai', null, null);
        app(\App\Services\TransactionReturnService::class)->approve($r, auth()->user());

        $c = Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id])->assertSee('2 sudah diretur')->assertSee('maks. 1');
        $this->assertSame(2, collect($c->instance()->lines)->firstWhere('id', $line->id)['returned']);
        $c->set("qty.{$line->id}", 2)->set('reason', 'rusak')->call('submit')->assertHasErrors("qty.{$line->id}");
    }

    public function test_pending_request_blocks_new_one(): void
    {
        $line = $this->tx->items()->first();
        app(\App\Services\TransactionReturnService::class)->requestReturn($this->tx, [$line->id => 1], 'rusak', false, 'tunai', null, null);
        Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id])->set("qty.{$line->id}", 1)->set('reason', 'rusak')->call('submit')
            ->assertHasErrors('qty')->assertSee('menunggu');
    }

    public function test_api_store(): void
    {
        $line = $this->tx->items()->where('product_id', $this->pid('ATK001'))->first();
        $this->postJson(route('transaksi.retur.store', $this->tx), ['items' => [['transaction_item_id' => $line->id, 'quantity' => 1]], 'reason' => 'salah_ambil', 'condition' => 'layak', 'refund_method' => 'tunai'])
            ->assertCreated()->assertJsonPath('data.status', 'menunggu')->assertJsonPath('data.kind', 'retur')->assertJsonPath('data.restock', true)
            ->assertJsonPath('data.items.0.qty', 1)->assertJsonPath('data.transaction.invoice', $this->tx->invoice_number);
        $this->postJson(route('transaksi.retur.store', $this->tx), ['items' => [['transaction_item_id' => $line->id, 'quantity' => 1]], 'reason' => 'rusak', 'condition' => 'rusak', 'refund_method' => 'tunai'])
            ->assertStatus(422)->assertJsonValidationErrors('transaction');
        $this->postJson(route('transaksi.retur.store', $this->tx), ['items' => [], 'reason' => 'x', 'condition' => 'baru', 'refund_method' => 'emas'])
            ->assertStatus(422)->assertJsonValidationErrors(['items', 'reason', 'condition', 'refund_method']);
    }
}
