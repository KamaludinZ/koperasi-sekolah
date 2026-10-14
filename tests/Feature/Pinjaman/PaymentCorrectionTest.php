<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    private LoanPaymentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $m = Member::factory()->teacher()->create();
        $this->loan = Loan::factory()->create(['status' => 'disetujui', 'principal' => 900000, 'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]); // jatuh tempo 1 Agu, 1 Sep, 1 Okt
        $this->travelTo('2026-08-10 10:00:00');
        $this->svc = app(LoanPaymentService::class);
    }

    private function state(): array
    {
        $l = $this->loan->fresh('installments');

        return [$l->status, (int) $l->outstanding, $l->installments->map(fn ($i) => (int) $i->paid_amount)->all(), $l->installments->pluck('status')->all()];
    }

    public function test_replace_wrong_amount(): void
    {
        $a = $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $b = $this->svc->record($this->loan, ['amount' => 500000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai']); // salah, seharusnya 50.000
        $this->assertSame(['berjalan', 100000, [300000, 300000, 200000], ['dibayar', 'dibayar', 'belum']], $this->state());

        $user = $this->loginAs('pengurus');
        $new = $this->svc->correct($b, 'Salah ketik, seharusnya 50 ribu', ['amount' => 50000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'], $user->id);

        $this->assertSame(['berjalan', 550000, [300000, 50000, 0], ['dibayar', 'belum', 'belum']], $this->state());
        $b->refresh();
        $this->assertSame([true, 'Salah ketik, seharusnya 50 ribu', $user->id], [$b->voided_at !== null, $b->void_reason, $b->voided_by]);
        $this->assertSame([$b->id, [['installment_id' => $this->loan->installments()->where('sequence', 2)->value('id'), 'sequence' => 2, 'amount' => 50000]]], [$new->replaces_id, $new->allocations]);
        $this->assertSame(3, LoanPayment::count()); // tidak ada yang dihapus

        $log = ActivityLog::where('event', 'pinjaman.angsuran_koreksi')->sole();
        $this->assertSame([-450000, 'Salah ketik, seharusnya 50 ribu', 100000, 550000, 500000, 50000],
            [$log->amount, $log->reason, $log->old_values['outstanding'], $log->new_values['outstanding'], $log->old_values['payment']['amount'], $log->new_values['payment']['amount']]);
    }

    public function test_void_reopens_paid_off_loan_and_marks_overdue(): void
    {
        $p = $this->svc->record($this->loan, ['amount' => 900000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $this->assertSame('lunas', $this->loan->fresh()->status);
        $this->svc->correct($p, 'Uang belum diterima, salah catat');
        $this->assertSame(['menunggak', 900000, [0, 0, 0], ['menunggak', 'belum', 'belum']], $this->state()); // ke-1 jatuh tempo 1 Agu sudah lewat
        $this->assertNull($this->loan->fresh()->paid_off_at);
    }

    public function test_replacement_date_reorders_allocation(): void
    {
        $a = $this->svc->record($this->loan, ['amount' => 100000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $b = $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-03', 'payment_method' => 'tunai']);
        // a sebenarnya dibayar 4 Agu → b dulu baru a
        $this->svc->correct($a, 'Tanggal salah, seharusnya 4 Agustus', ['amount' => 100000, 'paid_at' => '2026-08-04', 'payment_method' => 'tunai']);
        $this->assertSame([[1], [2]], [array_column($b->fresh()->allocations, 'sequence'), array_column(LoanPayment::whereNull('voided_at')->latest('id')->first()->allocations, 'sequence')]);
        $this->assertSame([300000, 100000, 0], $this->state()[2]);
    }

    public function test_correction_validation(): void
    {
        $a = $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $b = $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-02', 'payment_method' => 'tunai']);
        $cases = [
            [fn () => $this->svc->correct($a, 'ok'), 'reason'],
            [fn () => $this->svc->correct($a, 'Nominal terlalu besar', ['amount' => 600001, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']), 'amount'],
            [fn () => $this->svc->correct($a, 'Tanggal sebelum cair', ['amount' => 1000, 'paid_at' => '2026-06-30', 'payment_method' => 'tunai']), 'paid_at'],
            [fn () => $this->svc->correct($a, 'Tanggal masa depan', ['amount' => 1000, 'paid_at' => '2026-08-11', 'payment_method' => 'tunai']), 'paid_at'],
            [fn () => $this->svc->correct($a, 'Transfer tanpa rujukan', ['amount' => 1000, 'paid_at' => '2026-08-01', 'payment_method' => 'transfer']), 'reference'],
        ];
        foreach ($cases as [$fn, $key]) {
            try {
                $fn();
                $this->fail("Harus gagal pada {$key}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }
        $this->assertNull($a->fresh()->voided_at);
        $this->assertSame(300000, (int) $this->loan->fresh()->outstanding);

        $this->svc->correct($a, 'Dibatalkan sekali');
        $this->expectException(ValidationException::class);
        $this->svc->correct($a->fresh(), 'Dibatalkan dua kali');
    }

    public function test_correction_dialog(): void
    {
        $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $p = LoanPayment::sole();
        Livewire::test(Show::class, ['loan' => $this->loan])->assertSeeHtml('data-correct="'.$p->id.'"')
            ->call('openCorrection', $p->id)->assertSeeHtml('data-correction-form')->assertSet('corrAmount', 300000)
            ->set('corrAmount', 250000)->call('saveCorrection')->assertHasErrors('corrReason')
            ->set('corrReason', 'Salah nominal, seharusnya 250 ribu')->call('saveCorrection')->assertHasNoErrors()
            ->assertSet('correctingId', null)->assertSee('Pembayaran dikoreksi. Sisa sekarang Rp650.000.')
            ->assertSee('Dikoreksi: Salah nominal, seharusnya 250 ribu')->assertSee('pengganti #'.$p->id);

        $replacement = LoanPayment::whereNull('voided_at')->sole();
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openCorrection', $p->id)->assertSet('correctingId', null) // sudah dikoreksi
            ->call('openCorrection', $replacement->id)->set('corrMode', 'batal')->set('corrReason', 'Ternyata belum dibayar')->call('saveCorrection')
            ->assertHasNoErrors()->assertSee('Pembayaran dibatalkan. Sisa sekarang Rp900.000.');
    }

    public function test_kasir_forbidden(): void
    {
        $p = $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $this->loginAs('kasir');
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openCorrection', $p->id)->assertForbidden();
        Livewire::test(Show::class, ['loan' => $this->loan])->call('saveCorrection')->assertForbidden();
        $this->assertNull($p->fresh()->voided_at);
    }
}
