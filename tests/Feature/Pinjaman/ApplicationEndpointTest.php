<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $sari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 09:00:00');
        $this->sari = Member::factory()->teacher()->create(['name' => 'Bu Sari', 'member_code' => 'G-2026-031']);
    }

    private function payload(array $o = []): array
    {
        return ['member_id' => $this->sari->id, 'principal' => 2000000, 'interest' => 100000, 'tenor_months' => 10, 'payment_method' => 'potong_gaji', 'purpose' => 'Biaya sekolah', ...$o];
    }

    public function test_store_creates_pending_application(): void
    {
        $user = $this->loginAs('pengurus');
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload())->assertCreated()
            ->assertJsonPath('data.code', 'PJM-2026-0001')->assertJsonPath('data.status', 'diajukan')->assertJsonPath('data.monthly', 210000)
            ->assertJsonPath('data.applied_at', '2026-10-15')->assertJsonPath('message', 'Pengajuan PJM-2026-0001 untuk Bu Sari dicatat dan menunggu keputusan.');
        $this->assertSame($user->id, Loan::first()->created_by);
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.diajukan')->count());
    }

    public function test_store_with_percent_interest(): void
    {
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload(['interest' => null, 'interest_rate' => 4.5, 'principal' => 1500000]))
            ->assertCreated()->assertJsonPath('data.interest', 67500);
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload(['interest' => null, 'interest_rate' => 101]))
            ->assertUnprocessable()->assertJsonPath('errors.interest_rate.0', 'Persen bunga maksimal 100%.');
    }

    public function test_store_validation(): void
    {
        $this->postJson(route('pinjaman.pengajuan.store'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['member_id', 'principal', 'interest', 'tenor_months', 'payment_method']);
        $inactive = Member::factory()->teacher()->inactive()->create();
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload(['member_id' => $inactive->id, 'interest' => 3000000, 'tenor_months' => 0,
            'payment_method' => 'kartu', 'applied_at' => '2026-10-20', 'status' => 'berjalan']))
            ->assertUnprocessable()->assertJsonValidationErrors(['member_id', 'interest', 'tenor_months', 'payment_method', 'applied_at'])
            ->assertJsonPath('errors.member_id.0', 'Anggota tidak ditemukan atau sudah nonaktif.');
        $this->assertSame(0, Loan::count());

        // Kolom di luar aturan (status, outstanding, code) diabaikan.
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload(['status' => 'berjalan', 'outstanding' => 1, 'code' => 'HACK', 'created_by' => 999]))
            ->assertCreated()->assertJsonPath('data.status', 'diajukan')->assertJsonPath('data.code', 'PJM-2026-0001');
        $this->assertNotSame(999, Loan::first()->created_by);
    }

    public function test_index_by_stage(): void
    {
        Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $this->sari->id]);
        Loan::factory()->create(['code' => 'PJM-B', 'status' => 'disetujui']);
        Loan::factory()->create(['code' => 'PJM-C', 'status' => 'dibatalkan']);
        Loan::factory()->running()->create(['code' => 'PJM-D']);

        $this->getJson(route('pinjaman.pengajuan.data'))->assertOk()->assertJsonPath('meta.stage', 'diajukan')
            ->assertJsonPath('data.0.code', 'PJM-A')->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts', ['diajukan' => 1, 'disetujui' => 1, 'ditolak' => 0, 'dibatalkan' => 1]);
        $this->getJson(route('pinjaman.pengajuan.data', ['tahap' => 'dibatalkan']))->assertJsonPath('data.0.code', 'PJM-C')
            ->assertJsonPath('data.0.cancel_reason', 'Dibatalkan anggota');
        $this->getJson(route('pinjaman.pengajuan.data', ['q' => 'g-2026-031']))->assertJsonPath('data.0.code', 'PJM-A');
        $this->getJson(route('pinjaman.pengajuan.data', ['tahap' => 'berjalan']))->assertUnprocessable()
            ->assertJsonPath('errors.tahap.0', 'Tahap pengajuan tidak dikenal.');
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.pengajuan.data'))->assertForbidden();
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload())->assertForbidden();
        auth()->logout();
        $this->postJson(route('pinjaman.pengajuan.store'), $this->payload())->assertUnauthorized();
        $this->assertSame(0, Loan::count());
    }
}
