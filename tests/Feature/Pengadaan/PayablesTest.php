<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Payables;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PayablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_access(): void
    {
        $this->get('/pengadaan/hutang')->assertOk()->assertSee('UD Berkah Pangan')->assertSee('lewat 1 hari');
        $this->get('/pengadaan')->assertSee(route('pengadaan.hutang'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/hutang')->assertForbidden();
    }

    public function test_filters_and_summary(): void
    {
        $c = Livewire::test(Payables::class);
        $this->assertSame(['outstanding' => 2_110_000, 'overdue' => 640_000, 'week' => 610_000, 'paid_month' => 1_590_000], $c->instance()->summary);
        $this->assertSame(['belum-lunas' => 3, 'lewat' => 1, 'minggu-ini' => 1, 'lunas' => 1, 'semua' => 4], $c->instance()->counts);
        $this->assertSame([2, 3, 1], array_column($c->instance()->payables, 'id'));   // jatuh tempo terdekat dulu

        $c->call('setFilter', 'lunas');
        $this->assertSame([4], array_column($c->instance()->payables, 'id'));
        $c->call('setFilter', 'semua')->set('supplier', '1');
        $this->assertSame([4, 1], array_column($c->instance()->payables, 'id'));
        $c->set('supplier', '')->set('from', '2026-10-15')->set('to', '2026-10-31');
        $this->assertSame([3], array_column($c->instance()->payables, 'id'));
        $c->call('resetFilters')->assertSet('filter', 'belum-lunas');
    }

    public function test_record_partial_then_full_payment(): void
    {
        $c = Livewire::test(Payables::class)->call('pay', 2)->assertSet('amount', 640_000)->assertSee('Bayar hutang ke UD Berkah Pangan');

        $c->set('amount', 700_000)->call('savePayment')->assertHasErrors('amount');
        $c->set('amount', 240_000)->set('paidAt', '2026-10-20')->call('savePayment')->assertHasErrors('paidAt');
        $c->set('paidAt', '2026-10-14')->set('reference', 'TRF-1')->call('savePayment')->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Pembayaran PO-202610-0004 dicatat.')->assertSet('payingId', null);

        $p = collect(MockData::payables())->firstWhere('id', 2);
        $this->assertSame(['sebagian', 240_000], [$p['status'], $p['paid']]);

        $c->call('pay', 2)->assertSet('amount', 400_000)->set('method', 'tunai')->call('savePayment')
            ->assertDispatched('toast', type: 'success', message: 'Hutang PO-202610-0004 ke UD Berkah Pangan lunas.');
        $this->assertSame('lunas', collect(MockData::payables())->firstWhere('id', 2)['status']);
        $c->call('pay', 2)->assertSet('payingId', null);   // sudah lunas

        $c->call('showHistory', 2);
        $this->assertCount(2, $c->instance()->history['payments']);
        $c->assertSee('TRF-1')->call('closeHistory')->assertSet('historyId', null);
    }
}
