<?php

namespace Tests\Feature\Promo;

use App\Livewire\Promo\Index;
use App\Models\Coupon;
use App\Services\PromoService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PromoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, PromoSeeder::class]);
        Coupon::where('code', 'PRAMUKA25')->update(['used_count' => 50]); // kuota habis
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_shows_active_promos_coupons_and_manual_rules(): void
    {
        $this->get('/promo')
            ->assertOk()
            ->assertSeeText('Diskon & Promo')
            ->assertSee('Diskon Alat Tulis Awal Semester')
            ->assertSee('Potongan 10% · kategori Alat Tulis')
            ->assertDontSee('Diskon Hari Guru') // berakhir, tidak di saringan bawaan
            ->assertSee('MPLS2026')
            ->assertSee('Diskon manual kasir');
    }

    public function test_status_is_derived_from_dates_switch_and_quota(): void
    {
        $promos = collect(app(PromoService::class)->all())->pluck('status', 'name');
        $this->assertSame('aktif', $promos['Beli 2 Gratis 1 Biskuit Kelapa']);
        $this->assertSame('terjadwal', $promos['Promo Seragam Tahun Ajaran Baru']);
        $this->assertSame('berakhir', $promos['Diskon Hari Guru']);
        $this->assertSame('nonaktif', $promos['Potongan Roti Coklat Sore']);

        $coupons = collect(app(PromoService::class)->coupons())->pluck('status', 'code');
        $this->assertSame(['aktif', 'aktif', 'habis', 'berakhir', 'terjadwal'], array_map(fn ($c) => $coupons[$c], ['MPLS2026', 'GURUHEBAT', 'PRAMUKA25', 'HARIBATIK', 'OSIS100']));
    }

    public function test_filter_by_status_and_search(): void
    {
        $c = Livewire::test(Index::class);
        $this->assertCount(4, $c->get('promos'));
        $this->assertSame(['aktif' => 4, 'terjadwal' => 1, 'berakhir' => 1, 'nonaktif' => 1, 'semua' => 7], $c->get('counts'));

        $c->call('setStatus', 'terjadwal')->assertSee('Promo Seragam Tahun Ajaran Baru')->assertSee('Mulai 5 hari lagi');
        $c->call('setStatus', 'semua')->set('search', 'biskuit');
        $this->assertSame(['Beli 2 Gratis 1 Biskuit Kelapa'], array_column($c->get('promos'), 'name'));
        $c->set('search', 'tidak ada')->assertSee('Tidak ada promo yang cocok');
        $c->call('setStatus', 'ngawur')->assertSet('status', 'semua');
    }

    public function test_rule_and_schedule_descriptions(): void
    {
        $byName = collect(app(PromoService::class)->all())->keyBy('name');

        $this->assertSame('Beli 2 gratis 1 · Biskuit Kelapa', Index::describe($byName['Beli 2 Gratis 1 Biskuit Kelapa']));
        $this->assertSame('Harga Rp3.500/pcs mulai 10 pcs · Buku Tulis 38 Lembar', Index::describe($byName['Grosir Buku Tulis (isi 10)']));
        $this->assertSame('Potongan 20% · semua barang · min. belanja Rp10.000', Index::describe($byName['Diskon Hari Guru']));
        $this->assertSame('Sen–Jum · 13.00–14.30', Index::schedule($byName['Happy Hour Minuman Pulang Sekolah']));
        $this->assertSame('Setiap hari buka', Index::schedule($byName['Diskon Alat Tulis Awal Semester']));
    }

    public function test_cashier_sees_promos_without_manage_buttons(): void
    {
        $this->get('/promo')->assertSee('Buat promo');
        $this->loginAs('kasir');
        $this->get('/promo')->assertOk()->assertDontSee('Buat promo');
    }

    public function test_usage_and_discount_come_from_transactions(): void
    {
        app(\App\Services\CheckoutService::class)->checkout([$this->pid('ATK002') => 2], 'qris', null, null, null, null, 'GURUHEBAT');

        $promo = collect(app(PromoService::class)->all())->firstWhere('name', 'Diskon Alat Tulis Awal Semester');
        $this->assertSame([1, 600], [$promo['usage_count'], $promo['discount_given']]);
        $stats = Livewire::test(Index::class)->get('stats');
        $this->assertSame([4, 1, 1], [$stats['active_promos'], $stats['promo_uses'], $stats['coupon_uses'] - 50]);
    }

    public function test_api_lists_and_filters(): void
    {
        $this->getJson('/promo/data')->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('counts.aktif', 4)
            ->assertJsonPath('manual_discount.max_percent', 10)
            ->assertJsonCount(5, 'coupons');
        $this->getJson('/promo/data?status=terjadwal')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Promo Seragam Tahun Ajaran Baru');
        $this->getJson('/promo/data?q=biskuit')->assertJsonCount(1, 'data');
        $this->getJson('/promo/data?status=ngawur')->assertJsonValidationErrors('status');
    }
}
