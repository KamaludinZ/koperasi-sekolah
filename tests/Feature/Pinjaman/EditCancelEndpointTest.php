<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditCancelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'principal' => 1000000, 'interest' => 50000,
            'tenor_months' => 10, 'payment_method' => 'potong_gaji', 'purpose' => 'Renovasi', 'applied_at' => '2026-10-10']);
    }

    private function payload(array $o = []): array
    {
        return ['principal' => 1000000, 'interest' => 50000, 'tenor_months' => 10, 'payment_method' => 'potong_gaji', ...$o];
    }

    public function test_update_keeps_member_and_unspecified_fields(): void
    {
        $other = Member::factory()->teacher()->create();
        $this->putJson(route('pinjaman.pengajuan.update', $this->loan), $this->payload(['principal' => 1200000, 'tenor_months' => 12, 'member_id' => $other->id, 'status' => 'berjalan']))
            ->assertOk()->assertJsonPath('data.principal', 1200000)->assertJsonPath('data.tenor_months', 12)->assertJsonPath('data.status', 'diajukan')
            ->assertJsonPath('data.purpose', 'Renovasi')->assertJsonPath('data.applied_at', '2026-10-10')->assertJsonPath('reopened', false);
        $this->assertSame($this->loan->member_id, $this->loan->fresh()->member_id);
        $this->assertSame(['principal' => 1000000, 'tenor_months' => 10], ActivityLog::where('event', 'pinjaman.diubah')->first()->old_values);
    }

    public function test_update_of_approved_terms_reopens(): void
    {
        app(LoanApplicationService::class)->decide($this->loan, 'disetujui', 'OK');
        $this->putJson(route('pinjaman.pengajuan.update', $this->loan), $this->payload(['interest' => 80000]))->assertOk()
            ->assertJsonPath('reopened', true)->assertJsonPath('data.status', 'diajukan')
            ->assertJsonPath('message', 'Pengajuan PJM-2026-0001 diperbarui. Syaratnya berubah, jadi perlu diputuskan ulang.');
    }

    public function test_update_validation_and_locked_states(): void
    {
        $this->putJson(route('pinjaman.pengajuan.update', $this->loan), $this->payload(['interest' => 2000000, 'tenor_months' => 200, 'applied_at' => '2026-12-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['interest', 'tenor_months', 'applied_at']);

        $this->loan->update(['status' => 'berjalan', 'disbursed_at' => '2026-10-14', 'outstanding' => 1050000]);
        $this->putJson(route('pinjaman.pengajuan.update', $this->loan), $this->payload(['principal' => 2000000]))->assertUnprocessable()
            ->assertJsonPath('errors.loan.0', 'Pinjaman PJM-2026-0001 sudah berjalan; hanya pengajuan yang belum dicairkan yang bisa diubah atau dibatalkan.');
        $this->assertSame(1000000, (int) $this->loan->fresh()->principal);
    }

    public function test_cancel(): void
    {
        $user = $this->loginAs('pengurus');
        $this->postJson(route('pinjaman.pengajuan.cancel', $this->loan), [])->assertUnprocessable()->assertJsonPath('errors.alasan.0', 'Tulis alasan pembatalan.');
        $this->postJson(route('pinjaman.pengajuan.cancel', $this->loan), ['alasan' => 'Anggota menarik pengajuan'])->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan')->assertJsonPath('data.cancel_reason', 'Anggota menarik pengajuan')
            ->assertJsonPath('message', 'Pengajuan PJM-2026-0001 (Bu Sari) dibatalkan.');
        $this->assertSame($user->id, $this->loan->fresh()->cancelled_by);
        $this->postJson(route('pinjaman.pengajuan.cancel', $this->loan), ['alasan' => 'Batal lagi'])->assertUnprocessable();
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.dibatalkan')->count());
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->putJson(route('pinjaman.pengajuan.update', $this->loan), $this->payload(['principal' => 5000000]))->assertForbidden();
        $this->postJson(route('pinjaman.pengajuan.cancel', $this->loan), ['alasan' => 'Tidak berhak'])->assertForbidden();
        $this->assertSame(['diajukan', 1000000], [$this->loan->fresh()->status, (int) $this->loan->fresh()->principal]);
    }
}
