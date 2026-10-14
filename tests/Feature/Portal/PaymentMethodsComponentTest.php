<?php

namespace Tests\Feature\Portal;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PaymentMethodsComponentTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    private function render(array $balances, int $total, string $payment = 'tunai', ?array $deduction = null): string
    {
        view()->share('errors', new ViewErrorBag);

        return Blade::render('<x-portal.payment-methods :payments="$p" :payment="$pay" fulfillment="ambil" :balances="$b" :balance-total="$t" :deduction="$d" />',
            ['p' => Order::PAYMENTS, 'pay' => $payment, 'b' => $balances, 't' => $total, 'd' => $deduction]);
    }

    public function test_balances_and_sufficiency(): void
    {
        $html = $this->render(['tabungan' => 5000, 'simpanan' => [['id' => 3, 'name' => 'Simpanan Sukarela', 'balance' => 20000]]], 8000);
        $this->assertMatchesRegularExpression('/value="tabungan" disabled/', $html);
        $this->assertStringContainsString('Saldo Rp5.000 — tidak cukup', $html);
        $this->assertStringContainsString('Simpanan Sukarela Rp20.000', $html);
        $this->assertDoesNotMatchRegularExpression('/value="simpanan" disabled/', $html);

        $none = $this->render(['tabungan' => 0, 'simpanan' => []], 8000);
        $this->assertStringContainsString('Tidak ada simpanan yang bisa dipakai', $none);
        $this->assertMatchesRegularExpression('/value="simpanan" disabled/', $none);
    }

    public function test_deduction_summary(): void
    {
        $html = $this->render(['tabungan' => 20000, 'simpanan' => []], 8000, 'tabungan',
            ['source' => 'Tabungan', 'before' => 20000, 'amount' => 8000, 'after' => 12000, 'short' => 0]);
        $this->assertStringContainsString('data-deduction', $html);
        $this->assertStringContainsString('data-after>Rp12.000', $html);
    }
}
