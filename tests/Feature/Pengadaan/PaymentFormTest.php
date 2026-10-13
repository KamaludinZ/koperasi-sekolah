<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\PaymentForm;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_page_and_link(): void
    {
        $this->get('/pengadaan/hutang/bayar?supplier=2')->assertOk()->assertSee('Bayar hutang supplier')->assertSee('PO-202610-0004');
        $this->get('/pengadaan/hutang')->assertSee(route('pengadaan.hutang.bayar'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/hutang/bayar')->assertForbidden();
    }

    public function test_amount_is_allocated_to_nearest_due_first(): void
    {
        $c = Livewire::test(PaymentForm::class)->set('supplierId', '2');
        $this->assertSame([2, 3], array_column($c->instance()->bills, 'id'));
        $this->assertSame(1_250_000, $c->get('total'));
        $this->assertSame([2 => 640_000, 3 => 610_000], $c->get('alloc'));

        $c->set('total', 800_000);
        $this->assertSame([2 => 640_000, 3 => 160_000], $c->get('alloc'));

        $c->set('selected', [3]);                       // hanya tagihan kedua
        $this->assertSame(610_000, $c->get('total'));
        $this->assertSame([2 => 0, 3 => 610_000], $c->get('alloc'));

        $c->set('alloc.2', 100_000);                    // ubah manual → total ikut
        $this->assertSame(710_000, $c->get('total'));
    }

    public function test_validation_and_saving_multiple_payments(): void
    {
        Livewire::test(PaymentForm::class)->call('save')->assertHasErrors('supplierId');

        $c = Livewire::test(PaymentForm::class)->set('supplierId', '2')->set('alloc.2', 900_000)->call('save')->assertHasErrors('alloc.2');
        $c->set('total', 0)->call('save')->assertHasErrors('total');
        $c->set('total', 800_000)->set('reference', 'TRF-777')->call('save')->assertHasNoErrors()->assertRedirect(route('pengadaan.hutang'));

        $byId = collect(MockData::payables())->keyBy('id');
        $this->assertSame(['lunas', 'sebagian', 160_000], [$byId[2]['status'], $byId[3]['status'], $byId[3]['paid']]);
        $this->assertCount(1, MockData::payablePayments(3));
        $this->assertSame('Pembayaran Rp800.000 ke UD Berkah Pangan dicatat, 1 tagihan lunas.', session('toast')['message']);
    }
}
