<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Support\MockData;
use App\Support\PromoMatcher;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PromoPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00'); // Rabu pagi: happy hour minuman belum mulai
        $this->seed(ProductSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(string $name, string $category, int $price, int $qty): array
    {
        static $id = 0;

        return ['id' => ++$id, 'name' => $name, 'category' => $category, 'price' => $price, 'quantity' => $qty, 'subtotal' => $price * $qty];
    }

    public function test_best_promo_per_line_without_stacking(): void
    {
        // Buku tulis 10 pcs: diskon alat tulis 10% (Rp4.000) vs grosir Rp3.500/pcs (Rp5.000) → grosir.
        $r = PromoMatcher::match([$this->line('Buku Tulis 38 Lembar', 'Alat Tulis', 4000, 10), $this->line('Pensil 2B', 'Alat Tulis', 2500, 2)], MockData::promos());

        $byName = collect($r['applied'])->keyBy('name');
        $this->assertSame(5000, $byName['Grosir Buku Tulis (isi 10)']['saving']);
        $this->assertSame(['Pensil 2B'], $byName['Diskon Alat Tulis Awal Semester']['items']);
        $this->assertSame(500, $byName['Diskon Alat Tulis Awal Semester']['saving']);
        $this->assertSame(5500, $r['total']);
    }

    public function test_buy_x_get_y_and_hints(): void
    {
        $r = PromoMatcher::match([$this->line('Biskuit Kelapa', 'Makanan', 2000, 2)], MockData::promos());
        $this->assertSame(0, $r['total']);
        $this->assertSame('Tambah 1 Biskuit Kelapa lagi — gratis 1.', $r['hints'][0]['message']);

        $r = PromoMatcher::match([$this->line('Biskuit Kelapa', 'Makanan', 2000, 7)], MockData::promos());
        $this->assertSame(4000, $r['total']); // 2 kelompok isi 3 → gratis 2

        $r = PromoMatcher::match([$this->line('Buku Tulis 38 Lembar', 'Alat Tulis', 4000, 6)], MockData::promos());
        $this->assertContains('Tambah 4 Buku Tulis 38 Lembar lagi untuk harga grosir Rp3.500/pcs.', array_column($r['hints'], 'message'));
    }

    public function test_time_window_day_and_inactive_promos(): void
    {
        $milk = [$this->line('Teh Kotak', 'Minuman', 4500, 2)];
        $this->assertSame(0, PromoMatcher::match($milk, MockData::promos())['total']);
        $this->assertSame(1000, PromoMatcher::match($milk, MockData::promos(), Carbon::parse('2026-10-14 13:30'))['total']);
        $this->assertSame(0, PromoMatcher::match($milk, MockData::promos(), Carbon::parse('2026-10-17 13:30'))['total']); // Sabtu

        // Roti coklat: promo nonaktif tidak berlaku.
        $this->assertSame(0, PromoMatcher::match([$this->line('Roti Coklat', 'Makanan', 5000, 1)], MockData::promos(), Carbon::parse('2026-10-14 13:00'))['total']);
    }

    public function test_cart_shows_promo_preview_without_changing_total(): void
    {
        $c = Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK002'), 2) // pulpen Rp3.000 × 2, alat tulis 10%
            ->assertSee('Promo otomatis')
            ->assertSee('Diskon Alat Tulis Awal Semester')
            ->assertSee('hemat Rp600')
            ->assertSee('belum memotong total');

        $this->assertSame(6000, $c->instance()->summary['total']);
    }

    public function test_no_panel_without_matching_promo(): void
    {
        Livewire::test(Cart::class)->call('setQty', $this->pid('SRG001'), 1)->assertDontSee('Promo otomatis');
    }
}
