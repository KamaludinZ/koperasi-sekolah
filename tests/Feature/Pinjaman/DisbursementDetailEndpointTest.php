<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisbursementDetailEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
    }

    public function test_detail_of_disbursed_loan(): void
    {
        $user = $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 1000000, 'interest' => 100, 'tenor_months' => 3, 'applied_at' => '2026-10-01']);
        $file = UploadedFile::fake()->image('b.png');
        $sha = hash_file('sha256', $file->getRealPath());
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-10-15', 'amount' => 1000000, 'proof' => 'Kuitansi 9'], $user->id, LoanDisbursementService::storeProof($file));

        $res = $this->getJson(route('pinjaman.pencairan.show', $loan))->assertOk()
            ->assertJsonPath('data.loan.code', 'PJM-2026-0001')->assertJsonPath('data.disbursement.amount', 1000000)
            ->assertJsonPath('data.disbursement.disbursed_on', '2026-10-15')->assertJsonPath('data.disbursement.proof', 'Kuitansi 9')
            ->assertJsonPath('data.disbursement.proof_sha256', $sha)->assertJsonPath('data.disbursement.proof_mime', 'image/png')
            ->assertJsonPath('data.disbursement.user', $user->name)
            ->assertJsonPath('data.totals', ['amount' => 1000100, 'principal' => 1000000, 'interest' => 100, 'paid' => 0])
            ->assertJsonCount(3, 'data.schedule')->assertJsonPath('data.preview', []);
        $this->assertSame([333366, 333366, 333368], array_column($res->json('data.schedule'), 'amount'));
        $this->assertSame([33, 33, 34], array_column($res->json('data.schedule'), 'interest_part'));
        $this->assertSame(['2026-11-15', '2026-12-15', '2027-01-15'], array_column($res->json('data.schedule'), 'due_date'));
        $this->assertStringNotContainsString('pinjaman/bukti/', $res->getContent());
    }

    public function test_preview_for_undisbursed_and_empty_for_closed(): void
    {
        $approved = Loan::factory()->create(['status' => 'disetujui', 'principal' => 600000, 'interest' => 0, 'tenor_months' => 2]);
        $this->getJson(route('pinjaman.pencairan.show', $approved))->assertOk()->assertJsonPath('data.disbursement', null)
            ->assertJsonPath('data.preview.0.due_date', '2026-11-15')->assertJsonPath('data.preview.1.amount', 300000);
        $rejected = Loan::factory()->create(['status' => 'ditolak']);
        $this->getJson(route('pinjaman.pencairan.show', $rejected))->assertOk()->assertJsonPath('data.preview', []);
        $this->getJson('/pinjaman/999999/pencairan')->assertNotFound();
    }

    public function test_index_ready_and_recent(): void
    {
        Loan::factory()->create(['code' => 'PJM-R', 'status' => 'disetujui']);
        $l = Loan::factory()->create(['code' => 'PJM-D', 'status' => 'disetujui', 'principal' => 100000, 'tenor_months' => 1]);
        app(LoanDisbursementService::class)->disburse($l, ['disbursed_on' => '2026-10-15', 'amount' => 100000]);
        $this->getJson(route('pinjaman.pencairan.data'))->assertOk()
            ->assertJsonPath('data.ready.0.code', 'PJM-R')->assertJsonCount(1, 'data.ready')->assertJsonPath('data.recent.0.code', 'PJM-D');
    }

    public function test_access(): void
    {
        $loan = Loan::factory()->create(['status' => 'disetujui']);
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.pencairan.show', $loan))->assertForbidden();
        $this->getJson(route('pinjaman.pencairan.data'))->assertForbidden();
    }
}
