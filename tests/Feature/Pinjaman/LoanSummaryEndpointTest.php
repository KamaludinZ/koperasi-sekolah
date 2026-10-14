<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanSummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_amounts_and_due_installments(): void
    {
        $this->travelTo('2026-06-15 09:00:00');
        Loan::factory()->count(2)->create(['principal' => 500000]); // diajukan
        Loan::factory()->create(['status' => 'disetujui', 'principal' => 700000]);
        $run = Loan::factory()->running()->create(['principal' => 1000000, 'interest' => 50000, 'outstanding' => 700000, 'payment_method' => 'transfer']);
        $late = Loan::factory()->overdue()->create(['principal' => 600000, 'interest' => 0, 'outstanding' => 400000, 'payment_method' => 'potong_gaji']);
        $done = Loan::factory()->create(['status' => 'lunas', 'principal' => 300000]);

        $inst = fn (Loan $l, int $seq, string $due, int $amount, int $paid = 0, string $status = 'belum') => LoanInstallment::create(
            ['loan_id' => $l->id, 'sequence' => $seq, 'due_date' => $due, 'amount' => $amount, 'paid_amount' => $paid,
                'paid_at' => $status === 'dibayar' ? $due : null, 'status' => $status]);
        $inst($run, 1, '2026-05-20', 350000, 350000, 'dibayar');
        $inst($run, 2, '2026-06-20', 350000);                    // jatuh tempo bulan ini (belum lewat)
        $inst($late, 1, '2026-05-01', 200000, 50000, 'menunggak'); // terlambat, sisa 150.000
        $inst($late, 2, '2026-06-01', 200000);                    // bulan ini & terlambat
        $inst($done, 1, '2026-06-05', 300000, 300000, 'dibayar');  // lunas: tidak dihitung

        $data = $this->getJson(route('pinjaman.summary'))->assertOk()->json('data');

        $this->assertSame(2, $data['counts']['diajukan']);
        $this->assertSame(2, $data['awaiting_decision']);
        $this->assertSame(1, $data['awaiting_disbursement']);
        $this->assertSame(2, $data['active_count']);
        $this->assertSame(1100000, $data['outstanding']);
        $this->assertSame(400000, $data['overdue_outstanding']);
        $this->assertSame(['count' => 2, 'amount' => 550000], $data['due_this_month']);
        $this->assertSame(['count' => 2, 'amount' => 350000], $data['late_installments']);

        $status = collect($data['statuses'])->keyBy('status');
        $this->assertSame(['status' => 'diajukan', 'label' => 'Diajukan', 'count' => 2, 'principal' => 1000000, 'outstanding' => 0], $status['diajukan']);
        $this->assertSame(0, $status['ditolak']['count']);
        $this->assertCount(count(Loan::STATUSES), $data['statuses']);

        $method = collect($data['methods'])->keyBy('method');
        $this->assertSame(['count' => 1, 'outstanding' => 700000], collect($method['transfer'])->only('count', 'outstanding')->all());
        $this->assertSame(['count' => 1, 'outstanding' => 400000], collect($method['potong_gaji'])->only('count', 'outstanding')->all());
        $this->assertSame(0, $method['tunai']['count']);
    }

    public function test_empty_summary(): void
    {
        $this->getJson(route('pinjaman.summary'))->assertOk()
            ->assertJsonPath('data.outstanding', 0)->assertJsonPath('data.active_count', 0)
            ->assertJsonPath('data.due_this_month', ['count' => 0, 'amount' => 0])
            ->assertJsonCount(3, 'data.methods');
    }

    public function test_access(): void
    {
        $this->loginAs('pengurus');
        $this->getJson(route('pinjaman.summary'))->assertOk();
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.summary'))->assertForbidden();
    }
}
