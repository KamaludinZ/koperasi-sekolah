<?php

namespace Tests\Feature\Pinjaman;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Endpoint rekap (data uji sama dengan RecapAggregationTest lewat SeedsRecapLoans). */
class RecapEndpointTest extends TestCase
{
    use RefreshDatabase, SeedsRecapLoans;

    public function test_running_endpoint(): void
    {
        $this->getJson(route('pinjaman.rekap.berjalan'))->assertOk()
            ->assertJsonPath('data.count', 2)->assertJsonPath('data.outstanding', 1070000)->assertJsonPath('data.paid', 550000)
            ->assertJsonPath('data.by_member_type.siswa.count', 1)->assertJsonPath('data.expected_30_days.amount', 320000)
            ->assertJsonPath('data.overdue.count', 1)
            ->assertJsonPath('portfolio.active', 2)->assertJsonPath('portfolio.paid_off', 1)
            ->assertJsonStructure(['generated_at']);
    }

    public function test_period_endpoint(): void
    {
        $this->getJson(route('pinjaman.rekap.data'))->assertOk()
            ->assertJsonPath('period.type', 'bulan')->assertJsonPath('period.label', 'September 2026')
            ->assertJsonPath('data.collected.amount', 550000)->assertJsonPath('data.months', []);
        $this->getJson(route('pinjaman.rekap.data', ['jenis' => 'tahun', 'tahun' => '2026']))->assertOk()
            ->assertJsonPath('period.from', '2026-01-01')->assertJsonPath('data.disbursed.amount', 1700000)->assertJsonCount(12, 'data.months')
            ->assertJsonPath('data.running.count', 2);
        $this->getJson(route('pinjaman.rekap.data', ['jenis' => 'rentang', 'dari' => '2026-06-01', 'sampai' => '2026-07-31']))->assertOk()
            ->assertJsonPath('period.label', '1 Jun 2026 – 31 Jul 2026')->assertJsonPath('data.collected.amount', 200000)->assertJsonCount(2, 'data.months');

        foreach ([['jenis' => 'minggu'], ['bulan' => '2026-13'], ['bulan' => '2026-10'], ['jenis' => 'tahun', 'tahun' => '2027'], ['jenis' => 'tahun', 'tahun' => '1999'],
            ['jenis' => 'rentang'], ['jenis' => 'rentang', 'dari' => '2025-01-01', 'sampai' => '2026-09-01'], ['jenis' => 'rentang', 'dari' => '2026-09-01', 'sampai' => '2026-08-01']] as $p) {
            $this->getJson(route('pinjaman.rekap.data', $p))->assertUnprocessable();
        }
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.rekap.berjalan'))->assertForbidden();
        $this->getJson(route('pinjaman.rekap.data'))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.rekap.data'))->assertUnauthorized();
    }
}
