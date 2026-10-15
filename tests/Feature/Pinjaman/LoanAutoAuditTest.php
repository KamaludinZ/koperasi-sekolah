<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\AuditLog;
use App\Services\LoanApplicationService;
use App\Services\LoanAuditChain;
use App\Services\LoanDisbursementService;
use App\Services\LoanOverdueDetector;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoanAutoAuditTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $m->id, 'status' => 'diajukan', 'principal' => 300000, 'interest' => 0,
            'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-09-25', 'purpose' => null]);
    }

    private function auto()
    {
        return ActivityLog::where('event', 'pinjaman.data_berubah');
    }

    public function test_official_actions_are_not_double_logged(): void
    {
        $apps = app(LoanApplicationService::class);
        $apps->update($this->loan, ['principal' => 400000, 'interest' => 0, 'tenor_months' => 4, 'payment_method' => 'tunai', 'applied_at' => '2026-09-25'], auth()->id());
        $apps->decide($this->loan->fresh(), 'disetujui', 'OK', auth()->id());
        app(LoanDisbursementService::class)->disburse($this->loan->fresh(), ['disbursed_on' => '2026-10-01', 'amount' => 400000], auth()->id());
        $this->travelTo('2026-11-05 10:00:00');
        app(LoanPaymentService::class)->record($this->loan->fresh(), ['amount' => 100000, 'paid_at' => '2026-11-05', 'payment_method' => 'tunai'], auth()->id());
        $this->travelTo('2026-12-05 10:00:00');
        app(LoanOverdueDetector::class)->run();
        app(LoanPaymentService::class)->recalculate($this->loan->fresh(), auth()->id());

        $this->assertSame(0, $this->auto()->count());
        $this->assertGreaterThanOrEqual(5, ActivityLog::where('category', 'pinjaman')->count());
    }

    public function test_change_outside_official_actions_is_logged_with_before_after(): void
    {
        $this->loan->update(['purpose' => 'Renovasi', 'principal' => 350000]);

        $log = $this->auto()->sole();
        $this->assertSame(['principal' => 300000, 'purpose' => null], $log->old_values);
        $this->assertSame(['principal' => 350000, 'purpose' => 'Renovasi'], $log->new_values);
        $this->assertSame(['loan', $this->loan->id], [$log->subject_type, (int) $log->subject_id]);
        $this->assertSame(auth()->user()->name, $log->actor_name);
        $this->assertTrue($log->properties['otomatis']);
        $this->assertTrue(app(LoanAuditChain::class)->verify()['valid']); // ikut dirantai HMAC
    }

    public function test_multiple_updates_in_one_transaction_are_merged_and_rollback_leaves_nothing(): void
    {
        DB::transaction(function () {
            $this->loan->update(['purpose' => 'A']);
            $this->loan->update(['purpose' => 'B', 'tenor_months' => 6]);
        });
        $log = $this->auto()->sole();
        $this->assertSame(['purpose' => null, 'tenor_months' => 3], $log->old_values);
        $this->assertSame(['purpose' => 'B', 'tenor_months' => 6], $log->new_values);

        try {
            DB::transaction(function () {
                $this->loan->update(['purpose' => 'C']);
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }
        $this->loan->refresh()->update(['purpose' => 'B']); // tidak berubah → tidak dicatat
        DB::transaction(fn () => null); // transaksi kosong tidak menuliskan sisa catatan
        $this->assertSame(1, $this->auto()->count());
        $this->assertSame('B', $this->loan->fresh()->purpose);
    }

    public function test_rolled_back_savepoint_changes_are_dropped_but_outer_changes_logged(): void
    {
        DB::transaction(function () {
            $this->loan->update(['purpose' => 'Luar']);
            try {
                DB::transaction(function () {
                    Loan::whereKey($this->loan->id)->first()->update(['tenor_months' => 9]);
                    throw new \RuntimeException('batal dalam');
                });
            } catch (\RuntimeException) {
            }
            $this->loan->refresh()->update(['payment_method' => 'transfer']);
        });
        $log = $this->auto()->sole();
        $this->assertSame(['purpose' => 'Luar', 'payment_method' => 'transfer'], $log->new_values); // tenor (dibatalkan) tidak ikut
        $this->assertSame(3, $this->loan->fresh()->tenor_months);
    }

    public function test_change_reverted_within_transaction_and_quiet_mode_are_not_logged(): void
    {
        DB::transaction(function () {
            $this->loan->update(['purpose' => 'X']);
            $this->loan->update(['purpose' => null]);
        });
        AuditLog::quietly(fn () => $this->loan->update(['purpose' => 'Seeder']));
        $this->loan->update(['updated_at' => now()->addMinute()]); // kolom yang tidak dipantau
        $this->assertSame(0, $this->auto()->count());
    }

    public function test_payments_and_disbursements_outside_official_flow_are_logged(): void
    {
        app(LoanApplicationService::class)->decide($this->loan, 'disetujui', null, auth()->id());
        app(LoanDisbursementService::class)->disburse($this->loan->fresh(), ['disbursed_on' => '2026-10-01', 'amount' => 300000], auth()->id());
        $this->travelTo('2026-11-02 10:00:00');
        $official = app(LoanPaymentService::class)->record($this->loan->fresh(), ['amount' => 100000, 'paid_at' => '2026-11-01', 'payment_method' => 'tunai'], auth()->id());
        $this->assertSame(0, ActivityLog::whereIn('event', ['pinjaman.angsuran_otomatis', 'pinjaman.pencairan_otomatis'])->count()); // jalur resmi tidak dobel
        $before = $this->auto()->count();

        // pembayaran disisipkan lewat model tanpa layanan (mis. skrip) → tercatat
        $sneaky = \App\Models\LoanPayment::create(['loan_id' => $this->loan->id, 'paid_at' => '2026-11-02', 'amount' => 5000, 'payment_method' => 'tunai', 'allocations' => []]);
        $log = ActivityLog::where('event', 'pinjaman.angsuran_otomatis')->sole();
        $this->assertSame('dibuat', $log->action);
        $this->assertSame(['paid_at' => '2026-11-02', 'amount' => 5000, 'payment_method' => 'tunai'], $log->new_values);
        $this->assertSame([$sneaky->id, 5000], [$log->properties['id'], $log->amount]);
        $this->assertStringContainsString('Pembayaran angsuran PJM-A dicatat di luar alur resmi', $log->description);

        // nominal pembayaran resmi diubah langsung → tercatat sebelum/sesudah
        $official->update(['amount' => 120000]);
        $chg = ActivityLog::where('event', 'pinjaman.angsuran_otomatis')->latest('id')->first();
        $this->assertSame([['amount' => 100000], ['amount' => 120000], 'diubah'], [$chg->old_values, $chg->new_values, $chg->action]);

        // bukti pencairan diubah langsung
        $this->loan->disbursements()->first()->update(['proof' => 'kuitansi diganti']);
        $d = ActivityLog::where('event', 'pinjaman.pencairan_otomatis')->sole();
        $this->assertSame(['proof' => 'kuitansi diganti'], $d->new_values);

        // koreksi resmi (membatalkan & mengganti) tidak memicu catatan otomatis
        $count = ActivityLog::whereIn('event', ['pinjaman.angsuran_otomatis', 'pinjaman.pencairan_otomatis'])->count();
        app(LoanPaymentService::class)->correct($official->fresh(), 'Nominal salah ketik', ['amount' => 100000, 'paid_at' => '2026-11-01', 'payment_method' => 'tunai'], auth()->id());
        $this->assertSame($count, ActivityLog::whereIn('event', ['pinjaman.angsuran_otomatis', 'pinjaman.pencairan_otomatis'])->count());
        $this->assertSame($before, $this->auto()->count());
        $this->assertTrue(app(LoanAuditChain::class)->verify()['valid']);
    }

    public function test_listed_in_audit_trail_as_notable(): void
    {
        $this->loan->update(['payment_method' => 'transfer']);
        $this->get(route('pinjaman.audit', ['kelompok' => 'perhatian']))->assertOk()
            ->assertSeeHtml('data-audit="pinjaman.data_berubah"')->assertSee('Data pinjaman berubah (otomatis)');
    }
}
