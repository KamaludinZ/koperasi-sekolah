<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanInstallment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanDetailEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-10 09:00:00');
        $member = Member::factory()->teacher()->create(['name' => 'Bu Sari', 'phone' => '0812-0000-1111']);
        $this->loan = Loan::factory()->running()->create(['code' => 'PJM-2026-0007', 'member_id' => $member->id, 'principal' => 900000, 'interest' => 45000,
            'outstanding' => 630000, 'tenor_months' => 3, 'disbursed_at' => '2026-03-10', 'payment_method' => 'potong_gaji']);
        LoanDisbursement::create(['loan_id' => $this->loan->id, 'disbursed_on' => '2026-03-10', 'amount' => 900000, 'proof' => 'Kuitansi 12', 'proof_file' => 'pinjaman/bukti/rahasia123.jpg']);
        // ke-1 dibayar; ke-2 dibayar sebagian & lewat 10 hari; ke-3 jatuh tempo hari ini (belum terlambat)
        LoanInstallment::create(['loan_id' => $this->loan->id, 'sequence' => 1, 'due_date' => '2026-04-10', 'amount' => 315000, 'paid_amount' => 315000,
            'paid_at' => '2026-04-09', 'payment_method' => 'potong_gaji', 'status' => 'dibayar']);
        LoanInstallment::create(['loan_id' => $this->loan->id, 'sequence' => 3, 'due_date' => '2026-06-10', 'amount' => 315000]);
        LoanInstallment::create(['loan_id' => $this->loan->id, 'sequence' => 2, 'due_date' => '2026-05-31', 'amount' => 315000, 'paid_amount' => 100000, 'note' => 'Sebagian']);
    }

    public function test_detail_payload(): void
    {
        $res = $this->getJson(route('pinjaman.data', $this->loan))->assertOk()
            ->assertJsonPath('data.loan.code', 'PJM-2026-0007')->assertJsonPath('data.loan.member.name', 'Bu Sari')
            ->assertJsonPath('data.loan.member_phone', '0812-0000-1111')->assertJsonPath('data.loan.member_deleted', false)
            ->assertJsonPath('data.loan.total', 945000)->assertJsonPath('data.loan.monthly', 315000)
            ->assertJsonPath('data.disbursements.0.amount', 900000)->assertJsonPath('data.disbursements.0.has_file', true)
            ->assertJsonPath('data.summary.paid', 415000)->assertJsonPath('data.summary.paid_count', 1)->assertJsonPath('data.summary.count', 3)
            ->assertJsonPath('data.summary.late_count', 1)->assertJsonPath('data.summary.late_amount', 215000)
            ->assertJsonPath('data.summary.progress', 43)->assertJsonPath('data.summary.next.sequence', 2);

        $rows = $res->json('data.installments');
        $this->assertSame([1, 2, 3], array_column($rows, 'sequence')); // urut ke-n, bukan urutan simpan
        $this->assertSame([false, true, false], array_column($rows, 'late')); // jatuh tempo hari ini belum terlambat
        $this->assertSame([0, 10, 0], array_column($rows, 'days_late'));
        $this->assertSame([0, 215000, 315000], array_column($rows, 'remaining'));
        $this->assertStringNotContainsString('rahasia123', $res->getContent()); // path berkas tidak dibocorkan
    }

    public function test_installments_endpoint_filters(): void
    {
        $seq = fn (array $q = []) => array_column($this->getJson(route('pinjaman.installments', ['loan' => $this->loan->id, ...$q]))->assertOk()->json('data'), 'sequence');
        $this->assertSame([1, 2, 3], $seq());
        $this->assertSame([1], $seq(['status' => 'dibayar']));
        $this->assertSame([2, 3], $seq(['status' => 'belum']));
        $this->assertSame([2], $seq(['status' => 'terlambat']));
        $this->assertSame([], $seq(['status' => 'menunggak']));

        $this->getJson(route('pinjaman.installments', ['loan' => $this->loan->id]))
            ->assertJsonPath('meta.loan.code', 'PJM-2026-0007')->assertJsonPath('meta.summary.late_count', 1);
        $this->getJson(route('pinjaman.installments', ['loan' => $this->loan->id, 'status' => 'lunas']))
            ->assertUnprocessable()->assertJsonPath('errors.status.0', 'Status angsuran tidak dikenal.');
        $this->getJson('/pinjaman/999999/angsuran')->assertNotFound();
    }

    public function test_loan_of_deleted_member_still_readable(): void
    {
        $this->loan->member->delete();
        $this->getJson(route('pinjaman.data', $this->loan))->assertOk()
            ->assertJsonPath('data.loan.member.name', 'Bu Sari')->assertJsonPath('data.loan.member_deleted', true);
    }

    public function test_new_application_has_empty_schedule(): void
    {
        $loan = Loan::factory()->create(['principal' => 500000, 'interest' => 0, 'tenor_months' => 3]);
        $this->getJson(route('pinjaman.data', $loan))->assertOk()
            ->assertJsonPath('data.installments', [])->assertJsonPath('data.disbursements', [])
            ->assertJsonPath('data.summary.next', null)->assertJsonPath('data.summary.progress', 0)
            ->assertJsonPath('data.loan.monthly', 166667); // dibulatkan ke atas
    }

    public function test_kasir_forbidden(): void
    {
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.data', $this->loan))->assertForbidden();
        $this->getJson(route('pinjaman.installments', $this->loan))->assertForbidden();
    }
}
