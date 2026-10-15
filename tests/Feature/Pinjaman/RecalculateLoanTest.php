<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanInstallmentChange;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecalculateLoanTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    private LoanPaymentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-R', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 900000,
            'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        $this->svc = app(LoanPaymentService::class);
        $this->travelTo('2026-08-01 10:00:00');
        $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai'], auth()->id());
    }

    public function test_nothing_to_change_is_reported_without_audit(): void
    {
        $row = $this->svc->recalculate($this->loan);
        $this->assertFalse($row['changed']);
        $this->assertSame(0, $row['installments_fixed']);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'pinjaman.hitung_ulang']);
    }

    public function test_marks_overdue_when_due_date_passes_without_payment(): void
    {
        $this->travelTo('2026-09-03 01:00:00');
        $row = $this->svc->recalculate($this->loan);

        $this->assertTrue($row['changed']);
        $this->assertSame(['berjalan', 'menunggak'], [$row['before']['status'], $row['after']['status']]);
        $this->assertSame(0, $row['installments_fixed']); // hanya status karena lewat jatuh tempo
        $this->assertSame('menunggak', $this->loan->fresh()->status);
        $change = LoanInstallmentChange::where('event', 'status')->sole();
        $this->assertSame([2, ['status' => ['from' => 'belum', 'to' => 'menunggak']]], [$change->sequence, $change->changes]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'pinjaman.hitung_ulang']);

        // pembayaran menyusul → kembali berjalan
        $this->svc->record($this->loan->fresh(), ['amount' => 300000, 'paid_at' => '2026-09-03', 'payment_method' => 'tunai'], auth()->id());
        $this->assertSame('berjalan', $this->loan->fresh()->status);
    }

    public function test_repairs_drifted_installments_and_outstanding_from_payments(): void
    {
        // data rusak: diubah langsung di database (bukan lewat aplikasi)
        DB::table('loan_installments')->where('loan_id', $this->loan->id)->where('sequence', 1)->update(['paid_amount' => 0, 'paid_at' => null, 'payment_method' => null, 'status' => 'belum']);
        DB::table('loans')->where('id', $this->loan->id)->update(['outstanding' => 900000]);

        $row = $this->svc->recalculate($this->loan, auth()->id(), 'Selaraskan data');
        $this->assertSame([900000, 600000], [$row['before']['outstanding'], $row['after']['outstanding']]);
        $this->assertSame(1, $row['installments_fixed']);
        $fix = LoanInstallmentChange::where('event', 'koreksi')->sole();
        $this->assertSame('Selaraskan data', $fix->reason);
        $this->assertSame(['from' => 0, 'to' => 300000], $fix->changes['paid_amount']);
        $this->assertSame('dibayar', LoanInstallment::where('loan_id', $this->loan->id)->where('sequence', 1)->value('status'));
    }

    public function test_sets_and_clears_paid_off_status(): void
    {
        $this->svc->record($this->loan->fresh(), ['amount' => 600000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai'], auth()->id());
        $this->assertSame('lunas', $this->loan->fresh()->status);

        DB::table('loans')->where('id', $this->loan->id)->update(['status' => 'berjalan', 'outstanding' => 5000, 'paid_off_at' => null]);
        $row = $this->svc->recalculate($this->loan);
        $this->assertSame(['lunas', 0], [$row['after']['status'], $row['after']['outstanding']]);
        $this->assertSame('2026-08-01', $row['after']['paid_off_at']);

        // pembayaran pelunasan dikoreksi batal → tidak lunas lagi
        $last = $this->loan->payments()->whereNull('voided_at')->reorder()->orderByDesc('id')->first();
        $this->svc->correct($last, 'Uang belum diterima', null, auth()->id());
        $this->assertSame(['berjalan', 600000, null], [$this->loan->fresh()->status, (int) $this->loan->fresh()->outstanding, $this->loan->fresh()->paid_off_at]);
    }

    public function test_legacy_loan_without_payment_rows_is_not_wiped(): void
    {
        $legacy = Loan::factory()->running()->create(['code' => 'PJM-OLD', 'principal' => 200000, 'interest' => 0, 'outstanding' => 100000, 'tenor_months' => 2, 'disbursed_at' => '2026-06-01']);
        LoanInstallment::create(['loan_id' => $legacy->id, 'sequence' => 1, 'due_date' => '2026-07-01', 'amount' => 100000, 'paid_amount' => 100000, 'paid_at' => '2026-07-01', 'payment_method' => 'tunai', 'status' => 'dibayar']);
        LoanInstallment::create(['loan_id' => $legacy->id, 'sequence' => 2, 'due_date' => '2026-08-01', 'amount' => 100000]);

        $row = $this->svc->recalculate($legacy);
        $this->assertSame(100000, $row['after']['outstanding']);
        $this->assertSame(0, $row['installments_fixed']);
        $this->assertSame(100000, (int) LoanInstallment::where('loan_id', $legacy->id)->where('sequence', 1)->value('paid_amount'));
    }

    public function test_not_disbursed_loans_are_skipped(): void
    {
        $draft = Loan::factory()->create(['status' => 'diajukan']);
        $this->assertTrue($this->svc->recalculate($draft)['skipped']);
        $this->postJson(route('pinjaman.recalculate', $draft))->assertUnprocessable();
    }

    public function test_endpoint_and_access(): void
    {
        $this->travelTo('2026-09-03 01:00:00');
        $this->postJson(route('pinjaman.recalculate', $this->loan))->assertOk()
            ->assertJsonPath('data.changed', true)->assertJsonPath('data.after.status', 'menunggak')
            ->assertJsonPath('data.balance.remaining', 600000)->assertJsonPath('message', 'Sisa & status pinjaman diperbarui.');
        $this->postJson(route('pinjaman.recalculate', $this->loan))->assertOk()->assertJsonPath('data.changed', false)
            ->assertJsonPath('message', 'Sisa & status pinjaman sudah sesuai.');

        $this->loginAs('kasir');
        $this->postJson(route('pinjaman.recalculate', $this->loan))->assertForbidden();
    }

    public function test_command_dry_run_and_apply(): void
    {
        $this->travelTo('2026-09-03 01:00:00');
        $this->artisan('pinjaman:hitung-ulang', ['--uji' => true])->expectsOutputToContain('Uji coba (tidak disimpan): 1 pinjaman berubah.')->assertSuccessful();
        $this->assertSame('berjalan', $this->loan->fresh()->status);
        $this->assertSame(0, LoanInstallmentChange::where('event', 'status')->count());

        $this->artisan('pinjaman:hitung-ulang', ['--kode' => 'PJM-TIDAK-ADA'])->expectsOutputToContain('tidak ditemukan')->assertFailed();
        $this->artisan('pinjaman:hitung-ulang', ['--kode' => 'PJM-R', '--uji' => true])->expectsOutputToContain('1 pinjaman berubah')->assertSuccessful();
        $this->assertSame('berjalan', $this->loan->fresh()->status);

        $this->artisan('pinjaman:hitung-ulang')->expectsOutputToContain('1 pinjaman berubah.')->assertSuccessful();
        $this->assertSame('menunggak', $this->loan->fresh()->status);
        $this->artisan('pinjaman:hitung-ulang')->expectsOutputToContain('Semua pinjaman sudah sesuai.')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'pinjaman:hitung-ulang'));
        $this->assertCount(1, $events);
        $this->assertSame('15 0 * * *', $events->first()->expression);
    }
}
