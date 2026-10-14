<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id]);
    }

    public function test_approve(): void
    {
        $user = $this->loginAs('pengurus');
        $this->postJson(route('pinjaman.pengajuan.approve', $this->loan), ['catatan' => 'Memenuhi syarat'])->assertOk()
            ->assertJsonPath('data.status', 'disetujui')->assertJsonPath('data.decision_note', 'Memenuhi syarat')
            ->assertJsonPath('data.decided_by', $user->id)->assertJsonPath('data.decided_at', '2026-10-15 09:00:00')
            ->assertJsonPath('message', 'Pengajuan PJM-2026-0001 (Bu Sari) disetujui dan siap dicairkan.');
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.disetujui')->count());
    }

    public function test_reject_requires_reason(): void
    {
        $this->postJson(route('pinjaman.pengajuan.reject', $this->loan), [])->assertUnprocessable()->assertJsonPath('errors.alasan.0', 'Tulis alasan penolakan.');
        $this->postJson(route('pinjaman.pengajuan.reject', $this->loan), ['alasan' => 'ok'])->assertUnprocessable();
        $this->postJson(route('pinjaman.pengajuan.reject', $this->loan), ['alasan' => str_repeat('x', 201)])->assertUnprocessable();
        $this->assertSame('diajukan', $this->loan->fresh()->status);

        $this->postJson(route('pinjaman.pengajuan.reject', $this->loan), ['alasan' => 'Masih ada tunggakan'])->assertOk()
            ->assertJsonPath('data.status', 'ditolak')->assertJsonPath('data.decision_note', 'Masih ada tunggakan');
    }

    public function test_cannot_decide_twice_or_non_pending(): void
    {
        $this->postJson(route('pinjaman.pengajuan.approve', $this->loan))->assertOk();
        $this->postJson(route('pinjaman.pengajuan.reject', $this->loan), ['alasan' => 'Berubah pikiran'])->assertUnprocessable()
            ->assertJsonPath('errors.decision.0', 'Pengajuan PJM-2026-0001 sudah disetujui; muat ulang halaman.');
        $this->postJson(route('pinjaman.pengajuan.approve', $this->loan))->assertUnprocessable();
        $this->assertSame(1, ActivityLog::where('event', 'like', 'pinjaman.disetujui')->count());

        $running = Loan::factory()->running()->create();
        $this->postJson(route('pinjaman.pengajuan.approve', $running))->assertUnprocessable();
        $this->assertSame('berjalan', $running->fresh()->status);
        $this->postJson('/pinjaman/pengajuan/999999/setujui')->assertNotFound();
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->postJson(route('pinjaman.pengajuan.approve', $this->loan))->assertForbidden();
        $this->postJson(route('pinjaman.pengajuan.reject', $this->loan), ['alasan' => 'Tidak boleh'])->assertForbidden();
        auth()->logout();
        $this->postJson(route('pinjaman.pengajuan.approve', $this->loan))->assertUnauthorized();
        $this->assertSame('diajukan', $this->loan->fresh()->status);
    }
}
