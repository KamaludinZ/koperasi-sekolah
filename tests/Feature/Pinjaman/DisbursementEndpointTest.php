<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisbursementEndpointTest extends TestCase
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

    public function test_disburse_with_defaults_and_proof_file(): void
    {
        $user = $this->loginAs('pengurus');
        $res = $this->post(route('pinjaman.pencairan.store', $this->loan), ['proof' => 'Kuitansi 017', 'bukti' => UploadedFile::fake()->image('bukti.jpg')],
            ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.loan.status', 'berjalan')->assertJsonPath('data.loan.disbursed_at', '2026-10-15')
            ->assertJsonPath('data.loan.outstanding', 1260000)->assertJsonCount(6, 'data.installments')
            ->assertJsonPath('data.installments.0.due_date', '2026-11-15')->assertJsonPath('data.installments.0.amount', 210000)
            ->assertJsonPath('data.disbursements.0.has_file', true)->assertJsonPath('data.disbursements.0.proof', 'Kuitansi 017')
            ->assertJsonPath('message', 'Dana PJM-2026-0001 dicairkan. 6 angsuran terjadwal mulai 15 Nov 2026.');
        $this->assertStringNotContainsString('pinjaman/bukti/', $res->getContent());
        $d = LoanDisbursement::firstOrFail();
        $this->assertSame([$user->id, 'image/jpeg'], [$d->user_id, $d->proof_mime]);
        Storage::disk('local')->assertExists($d->proof_file);
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.dicairkan')->count());
    }

    public function test_validation_and_no_orphan_file(): void
    {
        $this->postJson(route('pinjaman.pencairan.store', $this->loan), ['amount' => 1000000, 'disbursed_on' => '2026-09-30'])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'disbursed_on'])
            ->assertJsonPath('errors.amount.0', 'Nominal pencairan harus sama dengan pokok yang disetujui (Rp1.200.000). Ubah pengajuan bila nominalnya berbeda.');
        $this->post(route('pinjaman.pencairan.store', $this->loan), ['bukti' => UploadedFile::fake()->createWithContent('x.pdf', 'bukan pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('bukti');
        $this->assertSame('disetujui', $this->loan->fresh()->status);

        // Pinjaman sudah cair → 422 dan berkas yang terunggah tidak tertinggal.
        $this->postJson(route('pinjaman.pencairan.store', $this->loan))->assertCreated();
        $this->post(route('pinjaman.pencairan.store', $this->loan), ['bukti' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.loan.0', 'Pinjaman PJM-2026-0001 berstatus berjalan; hanya pengajuan yang sudah disetujui yang bisa dicairkan.');
        $this->assertSame([], Storage::disk('local')->allFiles('pinjaman/bukti'));
        $this->assertSame(1, LoanDisbursement::count());
        $this->assertSame(6, $this->loan->installments()->count());
    }

    public function test_pending_or_missing_loan(): void
    {
        $pending = Loan::factory()->create();
        $this->postJson(route('pinjaman.pencairan.store', $pending))->assertUnprocessable();
        $this->assertSame(0, $pending->installments()->count());
        $this->postJson('/pinjaman/999999/pencairan')->assertNotFound();
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->postJson(route('pinjaman.pencairan.store', $this->loan))->assertForbidden();
        auth()->logout();
        $this->postJson(route('pinjaman.pencairan.store', $this->loan))->assertUnauthorized();
        $this->assertSame('disetujui', $this->loan->fresh()->status);
    }
}
