<?php

namespace Tests\Feature\Kasir;

use App\Services\CheckoutService;
use App\Services\ReceiptPresenter;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptPaymentInfoTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $method, array $extra = []): array
    {
        return [
            'invoice_number' => 'INV-20261013-0001', 'created_at' => now(), 'cashier' => 'Bu Rina', 'member' => 'Andi Pratama (8A)', 'member_code' => 'S-2026-001',
            'items' => [['product_name' => 'Pulpen', 'quantity' => 2, 'price' => 4000, 'subtotal' => 8000]],
            'subtotal' => 8000, 'discount' => 0, 'tax' => 0, 'total' => 8000, 'payment_method' => $method,
            'payment_method_label' => CheckoutService::PAYMENT_LABELS[$method], 'paid' => $method === 'tunai' ? 10000 : 8000, 'change' => $method === 'tunai' ? 2000 : 0,
        ] + $extra;
    }

    private function render(array $tx, array $receipt = []): string
    {
        $profile = app(ReceiptPresenter::class)->profile($receipt ? [...app(SettingsService::class)->receipt(), ...$receipt] : null);
        $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
        $html = view('livewire.kasir.partials.receipt', compact('tx', 'profile', 'rp'))->render();
        $pdf = view('kasir.receipt-pdf', ['tx' => $tx, 'profile' => $profile, 'width' => 58])->render();

        return $html."\n<!--pdf-->\n".$pdf;
    }

    public function test_labels_cover_all_methods(): void
    {
        $this->assertSame(['tunai', 'qris', 'tabungan', 'simpanan', 'bon'], array_keys(CheckoutService::PAYMENT_LABELS));
        $this->assertSame(['tunai', 'qris'], array_keys(CheckoutService::PAYMENT_METHODS)); // checkout tunai/QRIS saja sampai backend anggota siap
        $this->assertStringContainsString('Saldo tabungan', view('components.transaksi.method-badge', ['method' => 'tabungan', 'attributes' => new \Illuminate\View\ComponentAttributeBag])->render());
        $this->assertStringContainsString('bg-teal-50', view('components.transaksi.method-badge', ['method' => 'simpanan', 'attributes' => new \Illuminate\View\ComponentAttributeBag])->render());
    }

    public function test_cash_receipt_shows_method_paid_and_change(): void
    {
        $html = $this->render($this->tx('tunai'), ['show_cashier' => true, 'show_member' => false]);
        foreach (['Metode', 'Tunai', 'Bayar', 'Rp10.000', 'Kembali', 'Rp2.000', 'Petugas', 'Bu Rina'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Andi Pratama', $html);
    }

    public function test_qris_receipt_has_no_change_line(): void
    {
        $html = $this->render($this->tx('qris'), ['show_cashier' => false]);
        $this->assertStringContainsString('QRIS / Transfer', $html);
        $this->assertStringContainsString('Dibayar', $html);
        $this->assertStringNotContainsString('Kembali', $html);
        $this->assertStringNotContainsString('Petugas', $html);
    }

    public function test_savings_receipt_always_names_member_and_officer(): void
    {
        $html = $this->render($this->tx('tabungan', ['balance_before' => 50000, 'balance_after' => 42000]), ['show_cashier' => false, 'show_member' => false]);
        foreach (['Saldo tabungan', 'Saldo awal', 'Rp50.000', 'Dipotong', 'Sisa saldo', 'Rp42.000', 'Petugas', 'Bu Rina', 'Andi Pratama (8A)', 'S-2026-001'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Kembali', $html);
    }

    public function test_bon_receipt_shows_due_date_and_signatures(): void
    {
        $html = $this->render($this->tx('bon', ['credit_due_at' => '2026-10-27']), ['show_cashier' => false, 'show_member' => false]);
        foreach (['Bon (bayar nanti)', 'Dicatat bon', 'Jatuh tempo', '27/10/2026', 'Petugas', 'Andi Pratama'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(2, substr_count(explode('<!--pdf-->', $html)[0], 'border-t border-black pt-0.5')); // dua kolom tanda tangan
        $this->assertStringNotContainsString('Kembali', $html);
    }
}
