<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\ProductPicker;
use App\Support\Rupiah;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

class RupiahFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_rules(): void
    {
        $this->assertSame(['Rp0', 'Rp12.500', 'Rp1.250.000', '−Rp3.000', '+Rp500', 'Rp1.000'],
            [Rupiah::format(0), Rupiah::format(12500), Rupiah::format(1250000), Rupiah::format(-3000), Rupiah::format(500, signed: true), Rupiah::format('999.6')]);
        $this->assertSame(['4.000', '0'], [Rupiah::number(4000), Rupiah::number(null)]);
        $this->assertSame('Rp7.500', Blade::render('@rupiah($n)', ['n' => 7500]));
    }

    public function test_cashier_screens_use_same_format(): void
    {
        $this->seed(ProductSeeder::class);
        Livewire::test(ProductPicker::class)->assertSee('Rp4.000');
        $this->get('/kasir')->assertOk()->assertDontSee('Rp ')->assertSee('Rp0');
    }
}
