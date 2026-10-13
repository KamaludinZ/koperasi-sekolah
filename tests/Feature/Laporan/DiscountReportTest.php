<?php

namespace Tests\Feature\Laporan;

use App\Livewire\Laporan\Index;
use App\Services\CheckoutService;
use App\Services\DiscountReport;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PromoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DiscountReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, PromoSeeder::class]);

        $checkout = app(CheckoutService::class);
        // Promo alat tulis 10%: 5 buku tulis Rp20.000 → Rp2.000; + kupon GURUHEBAT 10% dari Rp18.000 = Rp1.800.
        $checkout->checkout([$this->pid('ATK001') => 5], 'qris', null, auth()->id(), null, null, 'GURUHEBAT');
        // Diskon manual Rp1.000 pada dasi Rp15.000 (tanpa promo berjalan).
        $checkout->checkout([$this->pid('SRG001') => 1], 'qris', null, auth()->id(), null, ['type' => 'nominal', 'value' => 1000, 'reason' => 'Jahitan lepas']);
        // Tanpa diskon.
        $checkout->checkout([$this->pid('SRG002') => 1], 'qris', null, auth()->id());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_summarizes_by_source(): void
    {
        $r = app(DiscountReport::class)->report(today(), today());
        $s = $r['summary'];

        $this->assertSame([4800, 55000, 50200], [$s['total_discount'], $s['gross_sales'], $s['net_sales']]);
        $this->assertSame([3, 2, 67], [$s['transactions'], $s['discounted_transactions'], $s['discounted_share']]);
        $this->assertSame([15100, 20000], [$s['avg_discounted'], $s['avg_plain']]);
        $this->assertSame(8.7, $s['discount_rate']);
        $this->assertSame([2000, 1800, 1000], [$r['by_source']['promo']['amount'], $r['by_source']['kupon']['amount'], $r['by_source']['manual']['amount']]);
        $this->assertSame([['Diskon Alat Tulis Awal Semester', 1, 2000]], array_map(fn ($p) => [$p['label'], $p['uses'], $p['amount']], $r['promos']));
        $this->assertSame('Kupon GURUHEBAT', $r['coupons'][0]['label']);
        $this->assertSame([['date' => '2026-10-14', 'amount' => 4800]], $r['daily']);
    }

    public function test_api_and_page_section(): void
    {
        $this->getJson('/laporan/diskon')->assertOk()->assertJsonPath('data.summary.total_discount', 4800);
        $this->getJson('/laporan/diskon?periode=7-hari')->assertOk()->assertJsonCount(7, 'data.daily');
        $this->getJson('/laporan/diskon?dari=2026-10-10&sampai=2026-10-01')->assertJsonValidationErrors('sampai');

        Livewire::test(Index::class)->assertSee('Diskon & promo')->assertSee('Rp4.800')->assertSee('Kupon GURUHEBAT');
        $this->loginAs('kasir');
        $this->getJson('/laporan/diskon')->assertForbidden();
    }

    public function test_export_includes_discount_section(): void
    {
        $csv = $this->get('/laporan/unduh?dari=2026-10-14&sampai=2026-10-14&format=csv&isi[]=summary&isi[]=discounts')->assertOk()->streamedContent();
        $this->assertStringContainsString('DISKON & PROMO', $csv);
        $this->assertStringContainsString('"Promo: Diskon Alat Tulis Awal Semester";1;2000', $csv);

        $this->get('/laporan/unduh?dari=2026-10-14&sampai=2026-10-14&format=pdf&isi[]=discounts')->assertOk();
    }
}
