<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Applications;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EditCancelTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'principal' => 1000000, 'interest' => 50000,
            'tenor_months' => 10, 'payment_method' => 'potong_gaji', 'purpose' => 'Renovasi', 'applied_at' => '2026-10-10']);
    }

    public function test_edit_pending_application(): void
    {
        Livewire::test(Applications::class)->call('edit', $this->loan->id)
            ->assertSet('creating', true)->assertSet('form.loan_id', $this->loan->id)->assertSet('form.principal', 1000000)
            ->assertSee('Ubah pengajuan pinjaman')->assertDontSee('Ganti')
            ->set('form.principal', 1200000)->set('form.tenor_months', 12)->call('save')->assertHasNoErrors()
            ->assertSee('Pengajuan PJM-2026-0001 diperbarui.');

        $this->loan->refresh();
        $this->assertSame([1200000, 12, 'diajukan', 'PJM-2026-0001'], [(int) $this->loan->principal, $this->loan->tenor_months, $this->loan->status, $this->loan->code]);
        $log = ActivityLog::where('event', 'pinjaman.diubah')->firstOrFail();
        $this->assertSame(['principal' => 1000000, 'tenor_months' => 10], $log->old_values);
        $this->assertSame(['principal' => 1200000, 'tenor_months' => 12], $log->new_values);
    }

    public function test_editing_terms_of_approved_application_requires_new_decision(): void
    {
        $svc = app(LoanApplicationService::class);
        $svc->decide($this->loan, 'disetujui', 'OK');

        // Keperluan saja → tetap disetujui.
        Livewire::test(Applications::class)->call('edit', $this->loan->id)->set('form.purpose', 'Renovasi dapur')->call('save')
            ->assertSee('Pengajuan PJM-2026-0001 diperbarui.')->assertDontSee('perlu diputuskan ulang');
        $this->assertSame(['disetujui', 'Renovasi dapur'], [$this->loan->fresh()->status, $this->loan->fresh()->purpose]);

        // Bunga berubah → kembali menunggu keputusan, keputusan lama dihapus.
        Livewire::test(Applications::class)->call('edit', $this->loan->id)->set('form.interest', 80000)->call('save')
            ->assertSee('Syaratnya berubah, jadi perlu diputuskan ulang.');
        $fresh = $this->loan->fresh();
        $this->assertSame(['diajukan', 80000, null, null], [$fresh->status, (int) $fresh->interest, $fresh->decided_by, $fresh->decision_note]);
        $this->assertTrue(ActivityLog::where('event', 'pinjaman.diubah')->latest('id')->first()->properties['reopened']);

        // Tidak ada perubahan → tidak ada log baru.
        $before = ActivityLog::count();
        $svc->update($fresh, ['principal' => 1000000, 'interest' => 80000, 'tenor_months' => 10, 'payment_method' => 'potong_gaji', 'purpose' => 'Renovasi dapur', 'applied_at' => '2026-10-10']);
        $this->assertSame($before, ActivityLog::count());
    }

    public function test_member_cannot_be_swapped_when_editing(): void
    {
        $other = Member::factory()->teacher()->create();
        Livewire::test(Applications::class)->call('edit', $this->loan->id)
            ->call('selectMember', $other->id)->call('clearMember')->assertSet('form.member_id', $this->loan->member_id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($this->loan->member_id, $this->loan->fresh()->member_id);
    }

    public function test_cancel_with_reason(): void
    {
        $user = $this->loginAs('pengurus');
        $c = Livewire::test(Applications::class)->call('openCancel', $this->loan->id)->assertSeeHtml('data-cancel-dialog')
            ->call('confirmCancel')->assertHasErrors('cancelReason')->assertSee('Tulis alasan pembatalan (5–200 karakter).');
        $this->assertSame('diajukan', $this->loan->fresh()->status);

        $c->set('cancelReason', 'Anggota menarik pengajuan')->call('confirmCancel')->assertHasNoErrors()
            ->assertSee('Pengajuan PJM-2026-0001 (Bu Sari) dibatalkan.')
            ->call('setTab', 'dibatalkan')->assertSee('Anggota menarik pengajuan');
        $fresh = $this->loan->fresh();
        $this->assertSame(['dibatalkan', $user->id, 'Anggota menarik pengajuan', '2026-10-14 10:00:00'], [$fresh->status, $fresh->cancelled_by, $fresh->cancel_reason, $fresh->cancelled_at->toDateTimeString()]);
        $this->get(route('pinjaman.show', $this->loan))->assertSee('data-cancel-reason', false)->assertSee('Anggota menarik pengajuan');
        $this->assertSame('diajukan', ActivityLog::where('event', 'pinjaman.dibatalkan')->first()->old_values['status']);
    }

    public function test_disbursed_or_closed_loans_cannot_be_edited_or_cancelled(): void
    {
        $svc = app(LoanApplicationService::class);
        foreach (['berjalan', 'menunggak', 'lunas', 'ditolak', 'dibatalkan'] as $status) {
            $loan = $status === 'berjalan' || $status === 'menunggak'
                ? Loan::factory()->running()->create(['status' => $status]) : Loan::factory()->create(['status' => $status]);
            Livewire::test(Applications::class)->call('edit', $loan->id)->assertSet('creating', false)->assertSee('tidak bisa diubah');
            Livewire::test(Applications::class)->call('openCancel', $loan->id)->assertSet('cancellingId', null);
            try {
                $svc->cancel($loan, 'Coba batalkan');
                $this->fail("Pinjaman {$status} tidak boleh dibatalkan.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('hanya pengajuan yang belum dicairkan', $e->errors()['loan'][0]);
            }
            $this->assertSame($status, $loan->fresh()->status);
        }
    }

    public function test_edit_after_disbursement_elsewhere_is_rejected(): void
    {
        $c = Livewire::test(Applications::class)->call('edit', $this->loan->id)->set('form.principal', 2000000);
        $this->loan->update(['status' => 'disetujui', 'decided_at' => now()]);
        $this->loan->update(['status' => 'berjalan', 'disbursed_at' => '2026-10-14', 'outstanding' => 1050000]);
        $c->call('save')->assertHasErrors('form.principal')->assertSee('hanya pengajuan yang belum dicairkan');
        $this->assertSame(1000000, (int) $this->loan->fresh()->principal);
    }

    public function test_kasir_forbidden(): void
    {
        $this->loginAs('kasir');
        Livewire::test(Applications::class)->call('edit', $this->loan->id)->assertForbidden();
        Livewire::test(Applications::class)->call('openCancel', $this->loan->id)->assertForbidden();
        Livewire::test(Applications::class)->call('confirmCancel')->assertForbidden();
    }

    public function test_form_loan_id_cannot_be_tampered(): void
    {
        $other = Loan::factory()->running()->create();
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(Applications::class)->call('edit', $this->loan->id)->set('form.loan_id', $other->id);
    }

    public function test_cancelling_approved_application_keeps_approval_record(): void
    {
        $approver = $this->loginAs('admin');
        app(LoanApplicationService::class)->decide($this->loan, 'disetujui', 'Memenuhi syarat', $approver->id);
        $this->travel(1)->hours();
        $canceller = $this->loginAs('pengurus');
        app(LoanApplicationService::class)->cancel($this->loan->fresh(), 'Anggota batal meminjam', $canceller->id);

        $fresh = $this->loan->fresh();
        $this->assertSame(['dibatalkan', 'Memenuhi syarat', $approver->id, '2026-10-14 10:00:00'], [$fresh->status, $fresh->decision_note, $fresh->decided_by, $fresh->decided_at->toDateTimeString()]);
        $this->assertSame([$canceller->id, 'Anggota batal meminjam', '2026-10-14 11:00:00'], [$fresh->cancelled_by, $fresh->cancel_reason, $fresh->cancelled_at->toDateTimeString()]);
        Livewire::test(Applications::class)->call('setTab', 'dibatalkan')->assertSee('Catatan: Memenuhi syarat')->assertSee('Alasan batal: Anggota batal meminjam');
    }
}
