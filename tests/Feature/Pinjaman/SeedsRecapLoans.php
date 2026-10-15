<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;

/**
 * Data uji rekap: guru potong gaji 12 bln (5 angsuran dibayar), siswa tunai 3 bln, guru transfer 1 bln lunas;
 * "hari ini" 10 Sep 2026, petugas pengurus masuk.
 */
trait SeedsRecapLoans
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('pengurus');
        // guru, potong gaji, 12 bln: 1.200.000 + 120.000; siswa, tunai, 3 bln: 300.000; guru transfer lunas
        $this->loan('PJM-G', 'teacher', 'potong_gaji', 1200000, 120000, 12, '2026-03-05');
        $this->loan('PJM-S', 'student', 'tunai', 300000, 0, 3, '2026-08-20');
        $this->loan('PJM-LUNAS', 'teacher', 'transfer', 200000, 0, 1, '2026-06-01');
        $svc = app(LoanPaymentService::class);
        $this->travelTo('2026-07-02 10:00:00');
        $svc->record(Loan::where('code', 'PJM-LUNAS')->first(), ['amount' => 200000, 'paid_at' => '2026-07-01', 'payment_method' => 'transfer', 'reference' => 'TRX-L']);
        $this->travelTo('2026-09-10 10:00:00');
        $svc->record(Loan::where('code', 'PJM-G')->first(), ['amount' => 550000, 'paid_at' => '2026-09-10', 'payment_method' => 'potong_gaji', 'reference' => '2026-09']); // 5 × 110.000
    }

    private function loan(string $code, string $state, string $method, int $principal, int $interest, int $tenor, string $on): void
    {
        $this->travelTo($on.' 09:00:00');
        $m = $state === 'teacher' ? Member::factory()->teacher()->create(['name' => "Anggota {$code}"]) : Member::factory()->create(['name' => "Anggota {$code}", 'type' => 'siswa']);
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => $principal, 'interest' => $interest,
            'tenor_months' => $tenor, 'payment_method' => $method, 'applied_at' => $on]);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $on, 'amount' => $principal]);
    }
}
