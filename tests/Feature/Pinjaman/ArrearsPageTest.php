<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Arrears;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanArrearsQuery;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArrearsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('pengurus');
        // tiga pinjaman @ angsuran 100.000/bulan, cair di tanggal berbeda → umur tunggakan berbeda per 10 Okt 2026
        $this->loan('PJM-LAMA', 'Bu Wati', 'potong_gaji', '2026-05-01', 5);   // jatuh tempo 1 Jun.. → 1 Jun lewat 131 hari (90+), 5 angsuran tertunggak
        $this->loan('PJM-SEDANG', 'Pak Joko', 'transfer', '2026-07-20', 3);   // 20 Agt lewat 51 hari (31-60), 2 tertunggak
        $this->loan('PJM-BARU', 'Bu Ani', 'tunai', '2026-08-25', 3);          // 25 Sep lewat 15 hari (1-30), 1 tertunggak
        $this->loan('PJM-LANCAR', 'Pak Budi', 'tunai', '2026-09-15', 3);       // jatuh tempo 15 Okt → belum menunggak
        $this->travelTo('2026-10-10 10:00:00');
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    private function loan(string $code, string $name, string $method, string $on, int $tenor): void
    {
        $this->travelTo($on.' 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => $name, 'phone' => '0812'.random_int(1000000, 9999999)]);
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 100000 * $tenor, 'interest' => 0,
            'tenor_months' => $tenor, 'payment_method' => $method, 'applied_at' => $on]);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $on, 'amount' => 100000 * $tenor]);
    }

    public function test_lists_overdue_loans_oldest_first_with_buckets(): void
    {
        $this->get(route('pinjaman.tunggakan'))->assertOk()->assertSee('Tunggakan Pinjaman')
            ->assertSeeInOrder(['data-arrear="PJM-LAMA"', 'data-arrear="PJM-SEDANG"', 'data-arrear="PJM-BARU"'], false)
            ->assertDontSeeHtml('data-arrear="PJM-LANCAR"')
            ->assertSeeHtml('data-total-arrears>Rp800.000')
            ->assertSee('5 angsuran tertunggak')->assertSee('131 hari')->assertSee('51 hari')->assertSee('15 hari');

        $sum = LoanArrearsQuery::summary(app(LoanArrearsQuery::class)->all());
        $this->assertSame([1, 1, 0, 1], array_column($sum['buckets'], 'count'));
        $this->assertSame([100000, 200000, 0, 500000], array_column($sum['buckets'], 'amount'));
        $this->assertSame(8, $sum['installments']);
    }

    public function test_filters_sort_and_search(): void
    {
        Livewire::test(Arrears::class)
            ->call('setBucket', '31-60')->assertSet('bucket', '31-60')
            ->assertSeeHtml('data-arrear="PJM-SEDANG"')->assertDontSeeHtml('data-arrear="PJM-LAMA"')
            ->call('setBucket', '31-60')->assertSet('bucket', '') // klik lagi → lepas
            ->set('method', 'tunai')->assertSeeHtml('data-arrear="PJM-BARU"')->assertDontSeeHtml('data-arrear="PJM-SEDANG"')
            ->set('method', '')->set('search', 'joko')->assertSeeHtml('data-arrear="PJM-SEDANG"')->assertDontSeeHtml('data-arrear="PJM-BARU"')
            ->set('search', '')->set('sort', 'terbesar')->assertSeeHtmlInOrder(['data-arrear="PJM-LAMA"', 'data-arrear="PJM-SEDANG"', 'data-arrear="PJM-BARU"'])
            ->set('bucket', 'ngawur')->assertSet('bucket', '')->set('sort', 'x')->assertSet('sort', 'terlama')
            ->set('search', 'tidak-ada')->assertSeeHtml('data-empty="tunggakan"')->assertSee('Tidak ada tunggakan yang cocok dengan filter.')
            ->call('resetFilters')->assertSet('search', '');

        $this->get(route('pinjaman.tunggakan', ['umur' => '90+']))->assertSeeHtml('data-arrear="PJM-LAMA"')->assertDontSeeHtml('data-arrear="PJM-BARU"');
    }

    public function test_payment_removes_loan_and_empty_state(): void
    {
        $svc = app(LoanPaymentService::class);
        foreach (['PJM-LAMA' => 500000, 'PJM-SEDANG' => 200000, 'PJM-BARU' => 100000] as $code => $amount) {
            $svc->record(Loan::where('code', $code)->first(), ['amount' => $amount, 'paid_at' => '2026-10-10', 'payment_method' => 'tunai']);
        }
        $this->get(route('pinjaman.tunggakan'))->assertOk()->assertSeeHtml('data-empty="tunggakan"')->assertSee('Tidak ada tunggakan.')
            ->assertSeeHtml('data-total-arrears>Rp0');
    }

    public function test_nav_and_access(): void
    {
        $this->get(route('pinjaman'))->assertSee(route('pinjaman.tunggakan'), false);
        $this->loginAs('kasir');
        $this->get(route('pinjaman.tunggakan'))->assertForbidden();
    }
}
