<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Installments;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InstallmentsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 09:00:00');
        $sari = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $budi = Member::factory()->teacher()->create(['name' => 'Pak Budi']);
        $a = Loan::factory()->running()->create(['code' => 'PJM-A', 'member_id' => $sari->id, 'payment_method' => 'potong_gaji', 'principal' => 900000, 'interest' => 0, 'outstanding' => 600000, 'tenor_months' => 3]);
        $b = Loan::factory()->overdue()->create(['code' => 'PJM-B', 'member_id' => $budi->id, 'payment_method' => 'tunai', 'principal' => 400000, 'interest' => 0, 'outstanding' => 400000, 'tenor_months' => 2]);
        $mk = fn ($loan, $seq, $due, $amount, $paid = 0, $status = 'belum', $paidAt = null) => LoanInstallment::create(['loan_id' => $loan->id, 'sequence' => $seq,
            'due_date' => $due, 'amount' => $amount, 'paid_amount' => $paid, 'status' => $status, 'paid_at' => $paidAt, 'payment_method' => $paid ? 'potong_gaji' : null]);
        $mk($a, 1, '2026-09-25', 300000, 300000, 'dibayar', '2026-10-02');
        $mk($a, 2, '2026-10-25', 300000);
        $mk($a, 3, '2026-11-25', 300000);
        $mk($b, 1, '2026-09-01', 200000, 0, 'menunggak');
        $mk($b, 2, '2026-10-01', 200000);
        Loan::factory()->create(['code' => 'PJM-PENDING']); // belum cair: tidak punya angsuran
    }

    private function rows($c): array
    {
        return collect($c->instance()->installments->items())->map(fn ($i) => $i['loan']['code'].'-'.$i['sequence'])->all();
    }

    public function test_default_shows_due_this_month_late_first(): void
    {
        $c = Livewire::test(Installments::class);
        $this->assertSame(['PJM-B-2', 'PJM-A-2'], $this->rows($c)); // 1 Okt terlambat dulu, lalu 25 Okt
        $c->assertSee('Terlambat 14 hari')->assertSee('Belum dibayar');

        $this->get('/pinjaman/angsuran')->assertOk()->assertSee('Angsuran Pinjaman')
            ->assertSeeInOrder(['data-card="bulan-ini"', 'Rp500.000', '2 angsuran'], false)
            ->assertSeeInOrder(['data-card="terlambat"', 'Rp400.000', '2 angsuran'], false)
            ->assertSeeInOrder(['data-card="dibayar"', 'Rp300.000', '1 angsuran'], false);
    }

    public function test_filters(): void
    {
        $c = Livewire::test(Installments::class)->call('setFilter', 'terlambat');
        $this->assertSame(['PJM-B-1', 'PJM-B-2'], $this->rows($c));
        $c->call('setFilter', 'dibayar');
        $this->assertSame(['PJM-A-1'], $this->rows($c));
        $c->call('setFilter', 'belum');
        $this->assertSame(['PJM-B-1', 'PJM-B-2', 'PJM-A-2', 'PJM-A-3'], $this->rows($c));
        $c->call('setFilter', 'semua')->set('month', '2026-11');
        $this->assertSame(['PJM-A-3'], $this->rows($c));
        $c->set('month', '')->set('method', 'tunai');
        $this->assertSame(['PJM-B-1', 'PJM-B-2'], $this->rows($c));
        $c->set('method', '')->set('search', 'bu sari');
        $this->assertSame(['PJM-A-2', 'PJM-A-3', 'PJM-A-1'], $this->rows($c));
        $c->set('search', '%')->assertSee('Tidak ada angsuran yang cocok dengan filter.');
        $c->call('resetFilters')->assertSet('filter', 'bulan_ini');
        $c->call('setFilter', 'aneh')->assertSet('filter', 'bulan_ini');
        $c->set('month', '2026-13')->set('filter', 'semua');
        $this->assertCount(5, $this->rows($c)); // bulan tidak valid diabaikan
    }

    public function test_nav_and_access(): void
    {
        $this->get('/pinjaman')->assertSee(route('pinjaman.angsuran'), false);
        $this->get('/pinjaman/angsuran?tampil=dibayar')->assertOk()->assertSee('data-row="PJM-A-1"', false)->assertDontSee('data-row="PJM-B-1"', false);
        $this->loginAs('kasir');
        $this->get('/pinjaman/angsuran')->assertForbidden();
    }
}
