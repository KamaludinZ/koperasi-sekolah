<?php

namespace Tests\Feature\Promo;

use App\Livewire\Promo\Index;
use App\Support\MockData;
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
        $promos = collect(MockData::promos())->pluck('status', 'name');
        $this->assertSame('aktif', $promos['Beli 2 Gratis 1 Biskuit Kelapa']);
        $this->assertSame('terjadwal', $promos['Promo Seragam Tahun Ajaran Baru']);
        $this->assertSame('berakhir', $promos['Diskon Hari Guru']);
        $this->assertSame('nonaktif', $promos['Potongan Roti Coklat Sore']);

        $coupons = collect(MockData::coupons())->pluck('status', 'code');
        $this->assertSame(['aktif', 'aktif', 'habis', 'berakhir', 'terjadwal'], $coupons->only(['MPLS2026', 'GURUHEBAT', 'PRAMUKA25', 'HARIBATIK', 'OSIS100'])->values()->all());
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
        $byName = collect(MockData::promos())->keyBy('name');

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
}
