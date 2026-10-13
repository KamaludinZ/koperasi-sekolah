<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Payables;
use App\Models\ActivityLog;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, UserSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    public function test_pay_validates_and_records(): void
    {
        $open = SupplierBill::where('status', 'belum')->first();
        $partial = SupplierBill::where('status', 'sebagian')->first();

        $this->postJson('/pengadaan/hutang/bayar', [])->assertJsonValidationErrors(['alokasi', 'paid_at', 'method']);
        $this->postJson('/pengadaan/hutang/bayar', ['alokasi' => [$open->id => 1000, $partial->id => 1000], 'paid_at' => '2026-10-14', 'method' => 'tunai'])
            ->assertJsonValidationErrors('tagihan');                                     // dua supplier berbeda
        $this->postJson('/pengadaan/hutang/bayar', ['alokasi' => [$open->id => $open->amount + 1], 'paid_at' => '2026-10-14', 'method' => 'tunai'])
            ->assertJsonValidationErrors("alokasi.{$open->id}");
        $this->postJson('/pengadaan/hutang/bayar', ['alokasi' => [$open->id => 0], 'paid_at' => '2026-10-14', 'method' => 'tunai'])->assertJsonValidationErrors('jumlah');

        $this->postJson('/pengadaan/hutang/bayar', ['alokasi' => [$open->id => $open->amount], 'paid_at' => '2026-10-14', 'method' => 'transfer', 'reference' => 'TRF-9'])
            ->assertCreated()->assertJsonPath('data.0.bill_status', 'lunas');
        $this->assertSame('lunas', $open->fresh()->status);
        $this->assertSame($open->amount, ActivityLog::where('event', 'payable.paid')->value('amount'));
    }

    public function test_admin_cancels_wrong_payment(): void
    {
        $bill = SupplierBill::where('status', 'sebagian')->first();
        $payment = $bill->payments()->first();

        $this->postJson("/pengadaan/hutang/pembayaran/{$payment->id}/batal")->assertJsonValidationErrors('alasan');
        $this->postJson("/pengadaan/hutang/pembayaran/{$payment->id}/batal", ['alasan' => 'Salah tagihan'])->assertOk()
            ->assertJsonPath('data.status', 'belum')->assertJsonPath('data.paid', 0);
        $this->assertNull(SupplierPayment::find($payment->id));
        $this->assertSame('Salah tagihan', ActivityLog::where('event', 'payable.payment_cancelled')->value('reason'));

        $this->loginAs('pengurus@koperasi.test');
        $other = SupplierBill::where('status', 'lunas')->first()->payments()->first();
        $this->postJson("/pengadaan/hutang/pembayaran/{$other->id}/batal", ['alasan' => 'Coba'])->assertForbidden();
        Livewire::test(Payables::class)->call('showHistory', $other->supplier_bill_id)->assertDontSee('Batalkan (salah input)');
    }

    public function test_cancel_from_history_panel(): void
    {
        $bill = SupplierBill::where('status', 'lunas')->first();
        $payment = $bill->payments()->first();
        Livewire::test(Payables::class)->call('showHistory', $bill->id)->assertSee('Batalkan (salah input)')
            ->set('cancelPaymentId', $payment->id)->call('cancelPayment')->assertHasErrors('cancelPaymentReason')
            ->set('cancelPaymentReason', 'Tercatat dua kali')->call('cancelPayment')->assertDispatched('toast', type: 'success');
        $this->assertSame('belum', $bill->fresh()->status);
    }

    public function test_cashier_cannot_pay(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $this->postJson('/pengadaan/hutang/bayar', [])->assertForbidden();
    }
}
