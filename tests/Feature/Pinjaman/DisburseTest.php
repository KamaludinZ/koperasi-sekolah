<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DisburseTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'status' => 'disetujui', 'decided_at' => '2026-10-12 09:00',
            'principal' => 1000000, 'interest' => 50000, 'tenor_months' => 4, 'applied_at' => '2026-10-10']);
    }

    public function test_disburse_creates_schedule_and_runs_loan(): void
    {
        $user = $this->loginAs('pengurus');
        Livewire::test(Show::class, ['loan' => $this->loan])->assertSee('Catat pencairan')
            ->call('openDisburse')->assertSet('amount', 1000000)->assertSet('disbursedOn', '2026-10-14')
            ->set('disbursedOn', '2026-01-31')->call('disburse')->assertHasErrors('disbursedOn')
            ->assertSee('Tanggal pencairan tidak boleh sebelum tanggal pengajuan (10 Okt 2026).')
            ->set('disbursedOn', '2026-10-13')->assertSeeHtml('data-schedule-preview')->assertSee('Jadwal yang akan terbentuk (4 angsuran)')
            ->set('proof', 'Kuitansi 017')->call('disburse')->assertHasNoErrors()->assertSet('disbursing', false)
            ->assertSee('Dana PJM-2026-0001 dicairkan. 4 angsuran terjadwal mulai 13 Nov 2026.')
            ->assertSeeHtml('data-installment="4"')->assertDontSeeHtml('data-ready-banner');

        $loan = $this->loan->fresh(['installments', 'disbursements']);
        $this->assertSame(['berjalan', '2026-10-13', 1050000], [$loan->status, $loan->disbursed_at->toDateString(), (int) $loan->outstanding]);
        $this->assertSame([262500, 262500, 262500, 262500], $loan->installments->map(fn ($i) => (int) $i->amount)->all());
        $this->assertSame(['2026-11-13', '2026-12-13', '2027-01-13', '2027-02-13'], $loan->installments->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertSame(['Kuitansi 017', 1000000, $user->id], [$loan->disbursements[0]->proof, (int) $loan->disbursements[0]->amount, $loan->disbursements[0]->user_id]);
        $log = ActivityLog::where('event', 'pinjaman.dicairkan')->firstOrFail();
        $this->assertSame([1000000, 'disetujui', 'berjalan'], [$log->amount, $log->old_values['status'], $log->new_values['status']]);
    }

    public function test_validation(): void
    {
        $c = Livewire::test(Show::class, ['loan' => $this->loan])->call('openDisburse')
            ->set('amount', 900000)->set('disbursedOn', '2026-10-20')->set('proof', str_repeat('x', 201))->call('disburse')
            ->assertHasErrors(['amount' => 'in', 'disbursedOn' => 'before_or_equal', 'proof' => 'max'])
            ->assertSee('Nominal pencairan harus sama dengan pokok yang disetujui (Rp1.000.000).')
            ->set('disbursedOn', '')->call('disburse')->assertHasErrors(['disbursedOn' => 'required']);
        $this->assertSame('disetujui', $this->loan->fresh()->status);
        $this->assertSame(0, $this->loan->installments()->count());
    }

    public function test_schedule_rounding_and_month_end(): void
    {
        $rows = LoanDisbursementService::schedule(1000000, 3, '2026-01-31');
        $this->assertSame([333333, 333333, 333334], array_column($rows, 'amount'));
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], array_column($rows, 'due_date'));
        $this->assertSame(1000000, array_sum(array_column($rows, 'amount')));
        $this->assertSame([[ 'sequence' => 1, 'due_date' => '2026-11-05', 'amount' => 7 ]], LoanDisbursementService::schedule(7, 1, '2026-10-05'));
    }

    public function test_only_approved_loans_and_only_once(): void
    {
        $svc = app(LoanDisbursementService::class);
        $svc->disburse($this->loan, ['disbursed_on' => '2026-10-14', 'amount' => 1000000]);
        try {
            $svc->disburse($this->loan->fresh(), ['disbursed_on' => '2026-10-14', 'amount' => 1000000]);
            $this->fail('Pencairan ganda harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('hanya pengajuan yang sudah disetujui', $e->errors()['loan'][0]);
        }
        $this->assertSame(1, $this->loan->disbursements()->count());
        $this->assertSame(4, $this->loan->installments()->count());

        foreach (['diajukan', 'ditolak', 'dibatalkan'] as $status) {
            $other = Loan::factory()->create(['status' => $status]);
            Livewire::test(Show::class, ['loan' => $other])->assertDontSee('Catat pencairan')->call('openDisburse')->assertSet('disbursing', false);
        }

        // Form terbuka lalu pinjaman dicairkan di tab lain → pesan, tidak ganda.
        $again = Loan::factory()->create(['status' => 'disetujui', 'principal' => 500000, 'tenor_months' => 2]);
        $c = Livewire::test(Show::class, ['loan' => $again])->call('openDisburse');
        $svc->disburse($again, ['disbursed_on' => '2026-10-14', 'amount' => 500000]);
        $c->call('disburse')->assertSet('disbursing', false)->assertSee('hanya pengajuan yang sudah disetujui');
        $this->assertSame(1, $again->disbursements()->count());
    }

    public function test_kasir_forbidden(): void
    {
        $this->loginAs('kasir');
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openDisburse')->assertForbidden();
        Livewire::test(Show::class, ['loan' => $this->loan])->call('disburse')->assertForbidden();
        $this->assertSame('disetujui', $this->loan->fresh()->status);
    }
}
