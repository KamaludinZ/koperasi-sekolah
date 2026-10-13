<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\PromoEngine;
use Database\Seeders\PromoSeeder;
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

    /** Semua promo dari PromoSeeder (status dihitung), bentuk PromoMatcher. */
    private function promos(): array
    {
        $this->seed(PromoSeeder::class);

        return collect(app(\App\Services\PromoService::class)->all())->all();
    }

    private function line(string $name, string $category, int $price, int $qty): array
    {
        // Id barang sungguhan: promo cakupan "barang" dicocokkan lewat id.
        $id = \App\Models\Product::where('name', $name)->value('id') ?? 0;

        return ['id' => $id, 'name' => $name, 'category' => $category, 'price' => $price, 'quantity' => $qty, 'subtotal' => $price * $qty];
    }

    public function test_best_promo_per_line_without_stacking(): void
    {
        // Buku tulis 10 pcs: diskon alat tulis 10% (Rp4.000) vs grosir Rp3.500/pcs (Rp5.000) → grosir.
        $r = PromoMatcher::match([$this->line('Buku Tulis 38 Lembar', 'Alat Tulis', 4000, 10), $this->line('Pensil 2B', 'Alat Tulis', 2500, 2)], $this->promos());

        $byName = collect($r['applied'])->keyBy('name');
        $this->assertSame(5000, $byName['Grosir Buku Tulis (isi 10)']['saving']);
        $this->assertSame(['Pensil 2B'], $byName['Diskon Alat Tulis Awal Semester']['items']);
        $this->assertSame(500, $byName['Diskon Alat Tulis Awal Semester']['saving']);
        $this->assertSame(5500, $r['total']);
    }

    public function test_buy_x_get_y_and_hints(): void
    {
        $r = PromoMatcher::match([$this->line('Biskuit Kelapa', 'Makanan', 2000, 2)], $this->promos());
        $this->assertSame(0, $r['total']);
        $this->assertSame('Tambah 1 Biskuit Kelapa lagi — gratis 1.', $r['hints'][0]['message']);

        $r = PromoMatcher::match([$this->line('Biskuit Kelapa', 'Makanan', 2000, 7)], $this->promos());
        $this->assertSame(4000, $r['total']); // 2 kelompok isi 3 → gratis 2

        $r = PromoMatcher::match([$this->line('Buku Tulis 38 Lembar', 'Alat Tulis', 4000, 6)], $this->promos());
        $this->assertContains('Tambah 4 Buku Tulis 38 Lembar lagi untuk harga grosir Rp3.500/pcs.', array_column($r['hints'], 'message'));
    }

    public function test_time_window_day_and_inactive_promos(): void
    {
        $milk = [$this->line('Teh Kotak', 'Minuman', 4500, 2)];
        $this->assertSame(0, PromoMatcher::match($milk, $this->promos())['total']);
        $this->assertSame(1000, PromoMatcher::match($milk, $this->promos(), Carbon::parse('2026-10-14 13:30'))['total']);
        $this->assertSame(0, PromoMatcher::match($milk, $this->promos(), Carbon::parse('2026-10-17 13:30'))['total']); // Sabtu

        // Roti coklat: promo nonaktif tidak berlaku.
        $this->assertSame(0, PromoMatcher::match([$this->line('Roti Coklat', 'Makanan', 5000, 1)], $this->promos(), Carbon::parse('2026-10-14 13:00'))['total']);
    }

    public function test_cart_applies_running_promo_from_database(): void
    {
        $this->seed(PromoSeeder::class);

        $c = Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK002'), 2) // pulpen Rp3.000 × 2, alat tulis 10%
            ->assertSee('Promo otomatis')
            ->assertSee('Diskon Alat Tulis Awal Semester')
            ->assertSee('hemat Rp600')
            ->assertSee('sudah dikurangkan dari total');

        $this->assertSame([600, 5400], [$c->instance()->summary['promo'], $c->instance()->summary['total']]);
    }

    public function test_manual_discount_applies_after_promo(): void
    {
        $this->seed(PromoSeeder::class);

        $c = Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 5) // Rp20.000 − promo 10% = Rp18.000
            ->call('editDiscount')->set('discountValue', 10)->set('discountReason', 'Pelanggan tetap')->call('applyDiscount');

        $this->assertSame([2000, 1800, 3800, 16200], [
            $c->instance()->summary['promo'], $c->instance()->summary['manual_discount'],
            $c->instance()->summary['discount'], $c->instance()->summary['total'],
        ]);

        $c->call('openPayment')->set('paymentMethod', 'qris')->call('pay')->assertHasNoErrors();
        $tx = Transaction::latest('id')->first();
        $this->assertSame([3800, 16200], [(int) $tx->discount, (int) $tx->total]);
        $this->assertSame(['promo' => 2000, 'manual' => 1800], $tx->discounts->mapWithKeys(fn ($d) => [$d->source => (int) $d->amount])->all());
        $this->assertSame('Buku Tulis 38 Lembar', $tx->discounts->firstWhere('source', 'promo')->reason);
    }

    public function test_checkout_picks_best_promo_and_ignores_inactive_or_out_of_hours(): void
    {
        $this->seed(PromoSeeder::class);
        $checkout = app(CheckoutService::class);

        // 10 buku tulis: grosir (Rp5.000) lebih hemat dari alat tulis 10% (Rp4.000).
        $tx = $checkout->checkout([$this->pid('ATK001') => 10], 'qris', null);
        $this->assertSame(['Grosir Buku Tulis (isi 10)'], $tx->discounts->pluck('label')->all());
        $this->assertSame(5000, (int) $tx->discount);

        // Teh kotak pukul 10.00: happy hour (13.00–14.30) belum berlaku; roti coklat: promo nonaktif.
        $tx = $checkout->checkout([$this->pid('MNM002') => 2, $this->pid('MKN001') => 1], 'qris', null);
        $this->assertSame(0, (int) $tx->discount);

        Carbon::setTestNow('2026-10-14 13:30:00');
        app(PromoEngine::class)->flush();
        $tx = $checkout->checkout([$this->pid('MNM002') => 2], 'qris', null);
        $this->assertSame(1000, (int) $tx->discount);
    }

    public function test_no_panel_without_matching_promo(): void
    {
        Livewire::test(Cart::class)->call('setQty', $this->pid('SRG001'), 1)->assertDontSee('Promo otomatis');
    }
}
