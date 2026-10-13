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
        $c->assertSee('setelah diskon');
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
        $this->assertSame(3, $line->fresh()->quantity); // data transaksi tidak berubah (tahap tampilan)
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
        // Setelah ada transaksi lebih baru, transaksi ini masuk data tiruan sebagai pengajuan menunggu.
        $newer = Transaction::factory()->withItems()->create(['created_at' => now()]);
        $rows = \App\Support\MockData::transactionReturns();
        $r = collect($rows)->firstWhere('transaction.id', $this->tx->id);
        $this->assertNotNull($r);
        $c = Livewire::test(ReturnForm::class, ['transaction' => $this->tx->id]);
        $returned = array_sum(array_column($c->instance()->lines, 'returned'));
        $this->assertSame(array_sum(array_column($r['items'], 'qty')), $returned);
        $c->assertSee('sudah diretur');
        $this->assertNotNull($newer);
    }
}
