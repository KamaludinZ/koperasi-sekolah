<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisbursementAuditTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 1200000,
            'interest' => 60000, 'tenor_months' => 6, 'applied_at' => '2026-10-01']);
    }

    public function test_disbursement_audit_has_full_details_and_file_fingerprint(): void
    {
        $user = $this->loginAs('pengurus');
        $upload = UploadedFile::fake()->image('bukti.jpg');
        $sha = hash_file('sha256', $upload->getRealPath());
        $this->post(route('pinjaman.pencairan.store', $this->loan), ['proof' => 'Kuitansi 017', 'bukti' => $upload], ['Accept' => 'application/json'])->assertCreated();

        $log = ActivityLog::where('event', 'pinjaman.dicairkan')->sole();
        $this->assertSame(['pinjaman', 'loan', $this->loan->id, 1200000, $user->id, 'pengurus'], [$log->category, $log->subject_type, $log->subject_id, $log->amount, $log->actor_id, $log->actor_role]);
        $this->assertSame('Pencairan PJM-2026-0001 Bu Sari: Rp1.200.000 pada 15 Okt 2026, 6 angsuran terbentuk · Kuitansi 017', $log->description);
        $this->assertSame([6, true, 'Kuitansi 017', $sha, 'image/jpeg', '2026-11-15', '2027-04-15', 210000, 60000],
            [$log->properties['installments'], $log->properties['has_file'], $log->properties['proof'], $log->properties['proof_sha256'], $log->properties['proof_mime'],
                $log->properties['first_due'], $log->properties['last_due'], $log->properties['monthly'], $log->properties['interest']]);
        $this->assertSame(['status' => 'disetujui'], $log->old_values);
        $this->assertSame(['status' => 'berjalan', 'disbursed_at' => '2026-10-15', 'outstanding' => 1260000], $log->new_values);
        $this->assertSame($sha, LoanDisbursement::first()->proof_sha256);
    }

    public function test_failed_disbursement_leaves_no_audit(): void
    {
        $pending = Loan::factory()->create(); // masih diajukan → tidak bisa dicairkan
        $this->postJson(route('pinjaman.pencairan.store', $pending))->assertUnprocessable();
        $this->assertSame(0, ActivityLog::where('event', 'pinjaman.dicairkan')->count());
    }

    public function test_proof_replacement_audit_records_old_and_new_fingerprints(): void
    {
        $svc = app(LoanDisbursementService::class);
        $first = UploadedFile::fake()->image('a.png');
        $svc->disburse($this->loan, ['disbursed_on' => '2026-10-15', 'amount' => 1200000], null, LoanDisbursementService::storeProof($first));
        $d = LoanDisbursement::first();
        $oldSha = $d->proof_sha256;

        $second = UploadedFile::fake()->createWithContent('k.pdf', "%PDF-1.4\n%%EOF");
        $svc->attachProof($d, 'Diganti scan', LoanDisbursementService::storeProof($second));
        $log = ActivityLog::where('event', 'pinjaman.bukti')->sole();
        $this->assertSame($oldSha, $log->old_values['proof_sha256']);
        $this->assertSame(hash('sha256', "%PDF-1.4\n%%EOF"), $log->new_values['proof_sha256']);
        $this->assertTrue($log->properties['file_replaced']);
    }

    public function test_tampered_proof_file_is_refused(): void
    {
        $svc = app(LoanDisbursementService::class);
        $svc->disburse($this->loan, ['disbursed_on' => '2026-10-15', 'amount' => 1200000], null, LoanDisbursementService::storeProof(UploadedFile::fake()->image('a.png')));
        $d = LoanDisbursement::first();
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertOk();

        Storage::disk('local')->put($d->proof_file, 'diganti diam-diam');
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertStatus(409);
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.bukti_tidak_cocok')->count());
    }

    public function test_audit_log_is_append_only(): void
    {
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-10-15', 'amount' => 1200000]);
        $log = ActivityLog::where('event', 'pinjaman.dicairkan')->sole();
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('activity_logs')->where('id', $log->id)->update(['amount' => 1]);
    }
}
