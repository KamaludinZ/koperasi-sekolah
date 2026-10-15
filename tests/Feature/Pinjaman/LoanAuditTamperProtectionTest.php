<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\AuditTrail;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Notification;
use App\Services\LoanAuditGuard;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use App\Support\AuditGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LoanAuditTamperProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(LoanAuditGuard::CACHE_KEY);
        $this->travelTo('2026-10-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $loan = Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
            'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-09-25']);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-10-01', 'amount' => 300000], auth()->id());
        app(LoanPaymentService::class)->record($loan, ['amount' => 100000, 'paid_at' => '2026-10-01', 'payment_method' => 'tunai'], auth()->id());
    }

    private function tamper(): int
    {
        $id = ActivityLog::where('event', 'pinjaman.angsuran')->value('id');
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $id)->update(['amount' => 1]));

        return $id;
    }

    public function test_intact_chain_command_and_banner(): void
    {
        Livewire::test(AuditTrail::class)->assertSeeHtml('data-integrity="belum"');
        $this->artisan('pinjaman:verifikasi-audit')->expectsOutputToContain('Rantai jejak audit pinjaman utuh.')->assertSuccessful();
        Livewire::test(AuditTrail::class)->assertSeeHtml('data-integrity="utuh"')->assertSeeText('Rantai HMAC jejak audit utuh');
        $this->assertSame(0, Notification::where('dedupe_key', 'like', 'pinjaman:audit:%')->count());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'pinjaman:verifikasi-audit'));
        $this->assertSame('40 2 * * *', $event?->expression);
    }

    public function test_tampering_is_detected_notified_once_and_recorded(): void
    {
        $id = $this->tamper();
        $this->artisan('pinjaman:verifikasi-audit')->expectsOutputToContain('Rantai tidak utuh: 1 baris diubah.')->assertFailed();
        $this->artisan('pinjaman:verifikasi-audit')->assertFailed(); // dijalankan lagi: tidak menggandakan

        $this->assertSame(['admin', 'pengurus'], Notification::where('dedupe_key', 'like', 'pinjaman:audit:%')->orderBy('target')->pluck('target')->all());
        $log = ActivityLog::where('event', 'pinjaman.audit_tidak_utuh')->sole();
        $this->assertSame([['type' => 'activity', 'id' => $id]], $log->properties['tampered']);
        $this->assertFalse(LoanAuditGuard::last()['valid']);

        Livewire::test(AuditTrail::class)->assertSeeHtml('data-integrity="rusak"')->assertSeeText('Jejak audit terindikasi diubah: 1 baris diubah')
            ->call('verifyChain')->assertDispatched('toast')
            ->call('setGroup', 'perhatian')->assertSeeHtml('data-audit="pinjaman.audit_tidak_utuh"');

        $this->getJson(route('pinjaman.audit.verifikasi'))->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.tampered', 1)
            ->assertJsonPath('message', 'Rantai tidak utuh: 1 baris diubah.');
    }

    public function test_exported_file_verification(): void
    {
        $res = $this->get(route('pinjaman.audit.ekspor'))->assertOk();
        $csv = $res->getContent();
        $sig = $res->headers->get('X-Audit-Signature');
        $upload = fn (string $content) => UploadedFile::fake()->createWithContent('jejak.csv', $content);

        $this->post(route('pinjaman.audit.verifikasi-berkas'), ['berkas' => $upload($csv), 'tanda' => $sig], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.exported_by', auth()->user()->name);
        $this->post(route('pinjaman.audit.verifikasi-berkas'), ['berkas' => $upload(str_replace('Rp100.000', 'Rp10.000', $csv)), 'tanda' => $sig], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.valid', false);
        $this->post(route('pinjaman.audit.verifikasi-berkas'), ['berkas' => $upload($csv), 'tanda' => 'bukan-tanda'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tanda']);
        $this->post(route('pinjaman.audit.verifikasi-berkas'), ['tanda' => $sig], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['berkas']);
    }

    public function test_tables_cannot_be_truncated_or_edited_and_access_is_restricted(): void
    {
        foreach (['loan_audit_chain', 'loan_installment_changes'] as $t) {
            try {
                DB::transaction(fn () => DB::table($t)->where('id', '>', 0)->delete());
                $this->fail("{$t} seharusnya tidak bisa dihapus");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
            if (DB::getDriverName() === 'pgsql') {
                try {
                    DB::transaction(fn () => DB::statement("TRUNCATE {$t} CASCADE"));
                    $this->fail("{$t} seharusnya tidak bisa di-TRUNCATE");
                } catch (QueryException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertTrue(app(LoanAuditGuard::class)->check()['valid']);

        foreach (range(1, 10) as $i) {
            $this->getJson(route('pinjaman.audit.verifikasi'))->assertOk();
        }
        $this->getJson(route('pinjaman.audit.verifikasi'))->assertStatus(429); // batas 10/menit per petugas
        $this->getJson(route('pinjaman.audit.data'))->assertOk();               // rute lain tidak ikut terbatas

        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.audit.verifikasi'))->assertForbidden();
        $this->postJson(route('pinjaman.audit.verifikasi-berkas'), [])->assertForbidden();
    }
}
