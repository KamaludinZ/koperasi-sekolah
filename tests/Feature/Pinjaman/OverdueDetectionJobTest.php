<?php

namespace Tests\Feature\Pinjaman;

use App\Jobs\DetectOverdueLoans;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanInstallmentChange;
use App\Models\Member;
use App\Models\Notification;
use App\Services\LoanDisbursementService;
use App\Services\LoanOverdueDetector;
use App\Services\LoanPaymentService;
use App\Services\QueueService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueDetectionJobTest extends TestCase
{
    use RefreshDatabase;

    private Loan $late;

    private Loan $current;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $this->late = $this->loan('PJM-L', 'Bu Lambat');
        $this->current = $this->loan('PJM-C', 'Pak Rajin');
        $this->travelTo('2026-08-01 10:00:00');
        app(LoanPaymentService::class)->record($this->current, ['amount' => 100000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        auth()->logout();
        $this->travelTo('2026-08-06 00:05:00'); // PJM-L ke-1 (1 Agt) lewat 5 hari
    }

    private function loan(string $code, string $name): Loan
    {
        $m = Member::factory()->teacher()->create(['name' => $name]);
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
            'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);

        return app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-07-01', 'amount' => 300000]);
    }

    public function test_job_marks_newly_overdue_loans_with_history_audit_and_notification(): void
    {
        $this->assertSame([$this->late->id], app(LoanOverdueDetector::class)->candidates());

        DetectOverdueLoans::dispatch(); // antrean sync di pengujian

        $late = $this->late->fresh();
        $this->assertSame(['menunggak', '2026-08-01'], [$late->status, $late->overdue_since->toDateString()]);
        $this->assertSame('berjalan', $this->current->fresh()->status);
        $this->assertSame('menunggak', $late->installments()->where('sequence', 1)->value('status'));
        $this->assertSame(1, LoanInstallmentChange::where('loan_id', $late->id)->where('event', 'status')->count());

        $log = ActivityLog::where('event', 'pinjaman.menunggak')->sole();
        $this->assertSame('Sistem', $log->actor_name);
        $this->assertSame(['status' => 'menunggak', 'overdue_since' => '2026-08-01'], $log->new_values);
        $n = Notification::where('dedupe_key', "pinjaman:menunggak:{$late->id}:2026-08-01")->sole();
        $this->assertSame(['sistem', 'pengurus'], [$n->type, $n->target]);
        $this->assertStringContainsString('1 angsuran lewat jatuh tempo, tertunggak Rp100.000 sejak 1 Agt 2026', $n->message);

        // dijalankan lagi: tidak ada kandidat, tidak ada notifikasi/audit ganda
        $this->assertSame([], app(LoanOverdueDetector::class)->candidates());
        DetectOverdueLoans::dispatch();
        $this->assertSame(1, Notification::where('dedupe_key', 'like', 'pinjaman:menunggak:%')->count());
        $this->assertSame(1, ActivityLog::where('event', 'pinjaman.menunggak')->count());
    }

    public function test_second_installment_overdue_keeps_since_and_does_not_renotify(): void
    {
        app(LoanOverdueDetector::class)->run();
        $this->travelTo('2026-09-03 00:05:00'); // ke-2 (1 Sep) ikut lewat
        $r = app(LoanOverdueDetector::class)->run();
        $this->assertSame(['PJM-C'], $r['overdue']); // PJM-L sudah menunggak; PJM-C baru menunggak (ke-2 belum dibayar)
        $this->assertSame(2, $r['installments']);    // ke-2 PJM-L & ke-2 PJM-C
        $this->assertSame('2026-08-01', $this->late->fresh()->overdue_since->toDateString());
        $this->assertSame('2026-09-01', $this->current->fresh()->overdue_since->toDateString());
        $this->assertSame(1, Notification::where('dedupe_key', "pinjaman:menunggak:{$this->late->id}:2026-08-01")->count());
        $this->assertSame(2, Notification::where('dedupe_key', 'like', 'pinjaman:menunggak:%')->count());
    }

    public function test_stale_overdue_recovers_when_schedule_moves(): void
    {
        app(LoanOverdueDetector::class)->run();
        // jadwal ke-1 digeser ke 10 Agt (mis. kesepakatan) → tidak lagi lewat jatuh tempo
        $this->late->installments()->where('sequence', 1)->first()->update(['due_date' => '2026-08-10']);
        $r = app(LoanOverdueDetector::class)->run();
        $this->assertSame(['PJM-L'], $r['recovered']);
        $this->assertSame(['berjalan', null], [$this->late->fresh()->status, $this->late->fresh()->overdue_since]);
        $this->assertSame('belum', $this->late->installments()->where('sequence', 1)->value('status'));
    }

    public function test_grace_period_is_respected(): void
    {
        config(['koperasi.loan_grace_days' => 7]);
        $this->assertSame([], app(LoanOverdueDetector::class)->candidates());
        $this->travelTo('2026-08-09 00:05:00');
        $this->assertSame(['PJM-L'], app(LoanOverdueDetector::class)->run()['overdue']);
    }

    public function test_command_schedule_queue_and_uniqueness(): void
    {
        $this->artisan('pinjaman:deteksi-tunggakan')->expectsOutputToContain('Menunggak: PJM-L')
            ->expectsOutputToContain('1 pinjaman diperiksa · 1 baru menunggak · 0 kembali lancar · 1 angsuran diperbarui.')->assertSuccessful();

        $job = new DetectOverdueLoans;
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(config('koperasi.queues.notifikasi'), $job->queue);
        $this->assertSame('Deteksi tunggakan pinjaman', QueueService::label(DetectOverdueLoans::class));

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'pinjaman:deteksi-tunggakan');
        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
    }
}
