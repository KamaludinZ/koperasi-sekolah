<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Tax;
use App\Support\MockData;
use App\Support\PriceCalculator;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaxSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        $this->seed(ProductSeeder::class);
    }

    public function test_calculator_rules(): void
    {
        $none = ['enabled' => false, 'rounding' => 'none'];
        $this->assertSame(['subtotal' => 12340, 'tax' => 0, 'tax_included' => 0, 'total' => 12340, 'rounding' => 0, 'payable' => 12340], PriceCalculator::compute(12340, $none));

        $added = ['enabled' => true, 'rate' => 11, 'included' => false, 'rounding' => 'up', 'rounding_unit' => 500, 'cash_only' => true];
        $this->assertSame(['subtotal' => 10000, 'tax' => 1100, 'tax_included' => 0, 'total' => 11100, 'rounding' => 400, 'payable' => 11500], PriceCalculator::compute(10000, $added));
        $this->assertSame(11100, PriceCalculator::compute(10000, $added, 'qris')['payable']); // non-tunai tidak dibulatkan

        $included = ['enabled' => true, 'rate' => 10, 'included' => true, 'rounding' => 'nearest', 'rounding_unit' => 100, 'cash_only' => false];
        $r = PriceCalculator::compute(11050, $included, 'qris');
        $this->assertSame([0, 1005, 11050, 11100], [$r['tax'], $r['tax_included'], $r['total'], $r['payable']]);

        $down = ['rounding' => 'down', 'rounding_unit' => 1000];
        $this->assertSame(-340, PriceCalculator::compute(12340, $down)['rounding']);
    }

    public function test_form_saves_and_overview_reflects(): void
    {
        $this->get('/pengaturan/pajak')->assertOk()->assertSee('Pajak penjualan')->assertSee('Contoh perhitungan');

        Livewire::test(Tax::class)
            ->set('enabled', true)
            ->set('label', 'PPN')
            ->set('rate', '11')
            ->set('rounding', 'up')
            ->set('rounding_unit', 500)
            ->set('sample', 10000)
            ->assertSee('PPN 11%')
            ->assertSee('Rp11.500')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $t = MockData::tax();
        $this->assertSame(['PPN', true, 11.0, 'up', 500], [$t['label'], $t['enabled'], $t['rate'], $t['rounding'], $t['rounding_unit']]);
        $this->get('/pengaturan')->assertSee('11%')->assertSee('Selalu ke atas');
    }

    public function test_decimal_comma_rate_and_validation(): void
    {
        Livewire::test(Tax::class)->set('enabled', true)->set('rate', '2,5')->call('save')->assertHasNoErrors();
        $this->assertSame(2.5, MockData::tax()['rate']);

        Livewire::test(Tax::class)->set('enabled', true)->set('rate', '0')->call('save')->assertHasErrors('rate');
        Livewire::test(Tax::class)->set('enabled', true)->set('rate', '150')->call('save')->assertHasErrors('rate');
        Livewire::test(Tax::class)->set('enabled', true)->set('rate', '1.234')->call('save')->assertHasErrors('rate');
        Livewire::test(Tax::class)->set('rounding', 'acak')->set('rounding_unit', 250)->call('save')->assertHasErrors(['rounding', 'rounding_unit']);

        // Pajak dimatikan → tarif disimpan 0.
        Livewire::test(Tax::class)->set('enabled', false)->call('save')->assertHasNoErrors();
        $this->assertSame(0, MockData::tax()['rate']);
    }

    public function test_only_admin(): void
    {
        $this->loginAs('kasir');
        $this->get('/pengaturan/pajak')->assertForbidden();
    }

    public function test_preview_uses_uniform_rupiah_format(): void
    {
        $html = \Livewire\Livewire::test(\App\Livewire\Pengaturan\Tax::class)
            ->set('rounding', 'up')->set('rounding_unit', 500)->html();
        $this->assertStringContainsString('Rp500', $html);
        $this->assertStringNotContainsString('Rp ', strip_tags($html));
        $this->assertMatchesRegularExpression('/[+−]Rp\d/', strip_tags($html));
    }
}
