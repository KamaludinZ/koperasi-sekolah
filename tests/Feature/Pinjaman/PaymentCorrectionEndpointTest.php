<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallmentChange;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCorrectionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    private LoanPayment $wrong;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-K', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 900000,
            'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        $this->travelTo('2026-08-05 10:00:00');
        $this->wrong = app(LoanPaymentService::class)->record($this->loan, ['amount' => 600000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'], auth()->id());
    }

    private function url(?LoanPayment $p = null): string
    {
        return route('pinjaman.angsuran.koreksi', $p ?? $this->wrong);
    }

    public function test_replace_payment_keeps_old_row_and_records_trail(): void
    {
        $res = $this->postJson($this->url(), ['alasan' => 'Salah ketik nominal', 'amount' => 300000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'])
            ->assertOk()
            ->assertJsonPath('data.voided.id', $this->wrong->id)->assertJsonPath('data.voided.voided', true)->assertJsonPath('data.voided.void_reason', 'Salah ketik nominal')
            ->assertJsonPath('data.replacement.amount', 300000)->assertJsonPath('data.replacement.replaces_id', $this->wrong->id)
            ->assertJsonPath('data.loan.outstanding', 600000)->assertJsonPath('data.balance.paid', 300000)
            ->assertJsonPath('message', 'Pembayaran dikoreksi menjadi Rp300.000. Sisa Rp600.000.');

        $this->assertSame(2, LoanPayment::count()); // tidak ada yang dihapus
        $changes = $res->json('data.changes');
        $this->assertSame([2], array_column($changes, 'sequence'));
        $this->assertSame(['from' => 300000, 'to' => 0], $changes[0]['changes']['paid_amount']);
        $this->assertSame('Koreksi', $changes[0]['event_label']);
        $this->assertSame('Salah ketik nominal', $changes[0]['reason']);
        $this->assertSame(auth()->user()->name, $changes[0]['actor']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'pinjaman.angsuran_koreksi']);
    }

    public function test_cancel_mode_ignores_replacement_fields(): void
    {
        $this->postJson($this->url(), ['mode' => 'batal', 'alasan' => 'Pembayaran tidak pernah diterima', 'amount' => 'abc'])->assertOk()
            ->assertJsonPath('data.replacement', null)->assertJsonPath('data.loan.outstanding', 900000)
            ->assertJsonPath('message', 'Pembayaran dibatalkan. Sisa Rp900.000.');
        $this->assertSame([1, 2], LoanInstallmentChange::where('event', 'koreksi')->where('loan_payment_id', $this->wrong->id)->orderBy('sequence')->pluck('sequence')->all()); // ke-1 & ke-2 kembali ke 0
    }

    public function test_validation_and_double_correction(): void
    {
        $this->postJson($this->url(), ['amount' => 300000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'])->assertUnprocessable()->assertJsonValidationErrors(['alasan']);
        $this->postJson($this->url(), ['alasan' => 'abc', 'mode' => 'batal'])->assertUnprocessable()->assertJsonValidationErrors(['alasan']);
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'mode' => 'hapus'])->assertUnprocessable()->assertJsonValidationErrors(['mode']);
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup'])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'paid_at', 'payment_method']);
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'amount' => 900001, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'amount' => 1000, 'paid_at' => '2026-08-06', 'payment_method' => 'tunai'])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'amount' => 1000, 'paid_at' => '2026-08-05', 'payment_method' => 'transfer'])->assertUnprocessable()->assertJsonValidationErrors(['reference']);
        $this->postJson($this->url(), ['alasan' => "Alasan\x00cukup", 'mode' => 'batal'])->assertUnprocessable()->assertJsonValidationErrors(['alasan']);
        $this->assertNull($this->wrong->fresh()->voided_at);

        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'mode' => 'batal'])->assertOk();
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'mode' => 'batal'])->assertUnprocessable()->assertJsonValidationErrors(['payment']);
        $this->postJson('/pinjaman/angsuran/pembayaran/999999/koreksi', ['alasan' => 'Alasan cukup', 'mode' => 'batal'])->assertNotFound();
    }

    public function test_trail_endpoint_lists_all_changes_newest_first_with_filters(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $this->postJson($this->url(), ['alasan' => 'Salah ketik nominal', 'amount' => 300000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'])->assertOk();

        $res = $this->getJson(route('pinjaman.angsuran.jejak', $this->loan))->assertOk()->assertJsonPath('meta.loan.code', 'PJM-K');
        $this->assertSame(['koreksi', 'pembayaran', 'pembayaran'], array_column($res->json('data'), 'event'));
        $this->assertSame(['id' => $this->wrong->id, 'amount' => 600000, 'paid_at' => '2026-08-05', 'voided' => true], $res->json('data.0.payment'));

        $this->assertSame(['pembayaran', 'pembayaran'], array_column($this->getJson(route('pinjaman.angsuran.jejak', [$this->loan, 'event' => 'pembayaran']))->json('data'), 'event'));
        // koreksi di 10 Sep: ke-2 (jatuh tempo 1 Sep) kembali belum lunas → langsung menunggak
        $this->assertSame(['from' => 'dibayar', 'to' => 'menunggak'], $res->json('data.0.changes.status'));
        $this->assertSame([2, 2], array_column($this->getJson(route('pinjaman.angsuran.jejak', [$this->loan, 'ke' => 2]))->json('data'), 'sequence'));
        $this->getJson(route('pinjaman.angsuran.jejak', [$this->loan, 'event' => 'hapus']))->assertUnprocessable();
        $this->getJson(route('pinjaman.angsuran.jejak', [$this->loan, 'ke' => 4]))->assertUnprocessable();
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'mode' => 'batal'])->assertForbidden();
        $this->getJson(route('pinjaman.angsuran.jejak', $this->loan))->assertForbidden();
        auth()->logout();
        $this->postJson($this->url(), ['alasan' => 'Alasan cukup', 'mode' => 'batal'])->assertUnauthorized();
        $this->assertNull($this->wrong->fresh()->voided_at);
    }
}
