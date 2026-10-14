<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisbursementsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_ready_loans_and_recent_disbursements(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        $sari = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        Loan::factory()->create(['code' => 'PJM-2026-0002', 'member_id' => $sari->id, 'status' => 'disetujui', 'principal' => 1500000,
            'decided_at' => '2026-10-12 09:00:00', 'decision_note' => 'Cair awal bulan']);
        Loan::factory()->create(['code' => 'PJM-2026-0001', 'status' => 'disetujui', 'decided_at' => '2026-10-10 09:00:00']);
        Loan::factory()->create(['code' => 'PJM-2026-0003']); // diajukan: belum siap
        $run = Loan::factory()->running()->create(['code' => 'PJM-2026-0009', 'principal' => 700000, 'interest' => 0, 'outstanding' => 700000]);
        LoanDisbursement::create(['loan_id' => $run->id, 'disbursed_on' => '2026-10-01', 'amount' => 700000, 'proof' => 'Kuitansi 88', 'proof_file' => 'x.jpg']);

        $this->get('/pinjaman/pencairan')->assertOk()->assertSee('Pencairan Pinjaman')
            ->assertSeeInOrder(['data-ready="PJM-2026-0001"', 'data-ready="PJM-2026-0002"'], false) // terlama disetujui dulu
            ->assertSee('Bu Sari')->assertSee('Rp1.500.000')->assertSee('Cair awal bulan')
            ->assertDontSee('data-ready="PJM-2026-0003"', false)
            ->assertSee('data-disbursement="PJM-2026-0009"', false)->assertSee('Kuitansi 88')->assertSee('ada lampiran')
            ->assertDontSee('x.jpg');
    }

    public function test_empty_states_nav_and_detail_banner(): void
    {
        $this->get('/pinjaman/pencairan')->assertOk()->assertSee('data-empty="siap-cair"', false)->assertSee('Belum ada pencairan.');
        $this->get('/pinjaman')->assertSee(route('pinjaman.pencairan'), false);

        $ok = Loan::factory()->create(['status' => 'disetujui', 'decided_at' => now()]);
        $this->get(route('pinjaman.show', $ok))->assertSee('data-ready-banner', false)->assertSee('Siap dicairkan');
        $this->get(route('pinjaman.show', Loan::factory()->create()))->assertSee('Menunggu keputusan pengurus');
        $this->get(route('pinjaman.show', Loan::factory()->create(['status' => 'ditolak'])))->assertSee('tidak akan dicairkan');
    }

    public function test_access(): void
    {
        $this->loginAs('pengurus');
        $this->get('/pinjaman/pencairan')->assertOk();
        $this->loginAs('kasir');
        $this->get('/pinjaman/pencairan')->assertForbidden();
    }
}
