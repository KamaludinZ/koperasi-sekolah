<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Applications;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class DecisionTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'principal' => 1000000, 'interest' => 50000]);
    }

    public function test_approve_with_optional_note(): void
    {
        $user = $this->loginAs('pengurus');
        Livewire::test(Applications::class)->assertSee('Setujui')
            ->call('openDecision', $this->loan->id, 'disetujui')->assertSeeHtml('data-decision="disetujui"')->assertSee('Setujui pengajuan?')
            ->call('decide')->assertHasNoErrors()->assertSet('decidingId', null)
            ->assertSee('Pengajuan PJM-2026-0001 (Bu Sari) disetujui dan siap dicairkan.')
            ->assertDontSeeHtml('data-application="PJM-2026-0001"'); // pindah dari tab "menunggu"

        $this->loan->refresh();
        $this->assertSame(['disetujui', null, $user->id, '2026-10-14 10:00:00'], [$this->loan->status, $this->loan->decision_note, $this->loan->decided_by, $this->loan->decided_at->toDateTimeString()]);
        $log = ActivityLog::where('event', 'pinjaman.disetujui')->firstOrFail();
        $this->assertSame(['diajukan', 'disetujui'], [$log->old_values['status'], $log->new_values['status']]);
    }

    public function test_reject_requires_reason(): void
    {
        $c = Livewire::test(Applications::class)->call('openDecision', $this->loan->id, 'ditolak')->assertSee('Alasan penolakan')
            ->call('decide')->assertHasErrors('decisionNote')->assertSee('Tulis alasan penolakan (minimal 5 karakter).')
            ->set('decisionNote', 'ok')->call('decide')->assertHasErrors('decisionNote');
        $this->assertSame('diajukan', $this->loan->fresh()->status);

        $c->set('decisionNote', '  Masih ada pinjaman menunggak  ')->call('decide')->assertHasNoErrors()->assertSee('ditolak.')
            ->call('setTab', 'ditolak')->assertSee('Masih ada pinjaman menunggak');
        $this->assertSame(['ditolak', 'Masih ada pinjaman menunggak'], [$this->loan->fresh()->status, $this->loan->fresh()->decision_note]);
        $this->assertSame('Masih ada pinjaman menunggak', ActivityLog::where('event', 'pinjaman.ditolak')->value('reason'));
    }

    public function test_only_pending_applications_can_be_decided_once(): void
    {
        $svc = app(LoanApplicationService::class);
        $svc->decide($this->loan, 'disetujui', 'Memenuhi syarat');
        try {
            $svc->decide($this->loan->fresh(), 'ditolak', 'Berubah pikiran');
            $this->fail('Pengajuan yang sudah diputuskan tidak boleh diputuskan lagi.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah disetujui', $e->errors()['decision'][0]);
        }
        $this->assertSame('disetujui', $this->loan->fresh()->status);

        // Dialog yang terbuka di tab lain lalu dikirim setelah diputuskan orang lain → pesan, bukan perubahan.
        $other = Loan::factory()->create();
        $c = Livewire::test(Applications::class)->call('openDecision', $other->id, 'disetujui');
        $svc->decide($other, 'ditolak', 'Dokumen kurang');
        $c->call('decide')->assertHasErrors('decisionNote')->assertSee('sudah ditolak');
        $this->assertSame('ditolak', $other->fresh()->status);

        Livewire::test(Applications::class)->call('openDecision', $this->loan->id, 'ditolak')->assertSet('decidingId', null)
            ->assertSee('Pengajuan ini sudah diputuskan atau tidak ditemukan.');
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.disetujui')->count());
    }

    public function test_invalid_decision_and_locked_props(): void
    {
        Livewire::test(Applications::class)->call('openDecision', $this->loan->id, 'lunas')->assertSet('decidingId', null);
        $this->expectException(ValidationException::class);
        app(LoanApplicationService::class)->decide($this->loan, 'lunas', null);
    }

    public function test_cannot_tamper_decision_target(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Applications::class)->call('openDecision', $this->loan->id, 'ditolak')->set('decision', 'disetujui');
    }

    public function test_kasir_forbidden(): void
    {
        $this->loginAs('kasir');
        Livewire::test(Applications::class)->call('openDecision', $this->loan->id, 'disetujui')->assertForbidden();
        Livewire::test(Applications::class)->call('decide')->assertForbidden();
        $this->assertSame('diajukan', $this->loan->fresh()->status);
    }
}
