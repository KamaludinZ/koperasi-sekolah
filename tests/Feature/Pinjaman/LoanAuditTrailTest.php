<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Setiap langkah siklus pinjaman & angsuran meninggalkan jejak audit dengan pelaku, subjek pinjaman, dan nilai sebelum/sesudah. */
class LoanAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_lifecycle_is_audited_and_verifiable(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-07-01 09:00:00');
        $staff = $this->loginAs('pengurus');
        $member = Member::factory()->teacher()->create(['name' => 'Bu Rina']);

        $id = $this->postJson(route('pinjaman.pengajuan.store'), ['member_id' => $member->id, 'principal' => 900000, 'interest' => 0, 'tenor_months' => 3,
            'payment_method' => 'transfer', 'applied_at' => '2026-07-01'])->assertCreated()->json('data.id');
        $loan = Loan::findOrFail($id);
        $this->putJson(route('pinjaman.pengajuan.update', $loan), ['principal' => 600000, 'interest' => 0, 'tenor_months' => 2,
            'payment_method' => 'transfer', 'applied_at' => '2026-07-01'])->assertOk();
        $this->postJson(route('pinjaman.pengajuan.approve', $loan), ['catatan' => 'OK'])->assertOk();
        $disb = $this->post(route('pinjaman.pencairan.store', $loan), ['amount' => 600000, 'disbursed_on' => '2026-07-01',
            'bukti' => UploadedFile::fake()->image('kuitansi.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $disbursementId = $loan->fresh()->disbursements()->value('id');
        $this->get(route('pinjaman.pencairan.bukti', ['disbursement' => $disbursementId, 'lihat' => 1]))->assertOk();
        $this->get(route('pinjaman.pencairan.bukti', $disbursementId))->assertOk();

        $this->travelTo('2026-08-01 10:00:00');
        $this->flushSession(); // sesi lama kedaluwarsa (batas diam) — petugas masuk lagi
        $this->actingAs($staff);
        $pay = $this->postJson(route('pinjaman.installments.store', $loan), ['amount' => 300000, 'reference' => 'TRX-1'])->assertCreated()->json('data.payment.id');
        $this->postJson(route('pinjaman.angsuran.koreksi', $pay), ['alasan' => 'Salah ketik nominal', 'amount' => 250000, 'paid_at' => '2026-08-01',
            'payment_method' => 'transfer', 'reference' => 'TRX-1'])->assertOk();
        $loan->installments()->where('sequence', 2)->first()->update(['due_date' => '2026-09-05']);
        $this->travelTo('2026-09-06 10:00:00');
        $this->flushSession();
        $this->actingAs($staff);
        $this->postJson(route('pinjaman.recalculate', $loan))->assertOk();

        $other = Loan::factory()->create(['member_id' => $member->id]);
        $this->postJson(route('pinjaman.pengajuan.cancel', $other), ['alasan' => 'Anggota membatalkan'])->assertOk();

        $events = ActivityLog::where('category', 'pinjaman')->orderBy('id')->pluck('event')->all();
        foreach (['pinjaman.diajukan', 'pinjaman.diubah', 'pinjaman.disetujui', 'pinjaman.dicairkan', 'pinjaman.bukti_dibuka',
            'pinjaman.angsuran', 'pinjaman.angsuran_koreksi', 'pinjaman.jadwal_diubah', 'pinjaman.hitung_ulang', 'pinjaman.dibatalkan'] as $e) {
            $this->assertContains($e, $events, "jejak {$e} tidak ada");
        }
        $this->assertSame(2, ActivityLog::where('event', 'pinjaman.bukti_dibuka')->count());
        $this->assertSame(['lihat', 'unduh'], ActivityLog::where('event', 'pinjaman.bukti_dibuka')->orderBy('id')->get()->pluck('properties.mode')->all());

        // semua jejak pinjaman ini: subjek pinjaman, pelaku petugas yang masuk
        $rows = ActivityLog::where('subject_type', 'loan')->where('subject_id', $loan->id)->get();
        $this->assertTrue($rows->every(fn ($r) => $r->actor_id === $staff->id && $r->actor_name === $staff->name), 'pelaku harus petugas');
        $fix = $rows->firstWhere('event', 'pinjaman.angsuran_koreksi');
        $this->assertSame('Salah ketik nominal', $fix->reason);
        $this->assertSame(['status' => 'berjalan', 'outstanding' => 300000], array_intersect_key($fix->old_values, array_flip(['status', 'outstanding'])));
        $sched = $rows->firstWhere('event', 'pinjaman.jadwal_diubah');
        $this->assertSame(['due_date' => '2026-09-01'], $sched->old_values);
        $this->assertSame(['due_date' => '2026-09-05'], $sched->new_values);

        // endpoint jejak audit per pinjaman
        $res = $this->getJson(route('pinjaman.audit.loan', $loan))->assertOk()->assertJsonPath('meta.loan.code', $loan->code);
        $this->assertSame($rows->count(), $res->json('meta.count'));
        $this->assertSame('pinjaman.hitung_ulang', $res->json('data.0.event')); // terbaru dulu
        $this->assertGreaterThan(0, $res->json('meta.installment_changes'));
        $this->assertCount(1, $this->getJson(route('pinjaman.audit.loan', [$loan, 'event' => 'pinjaman.angsuran_koreksi']))->json('data'));
        $this->getJson(route('pinjaman.audit.loan', [$loan, 'event' => 'DROP TABLE']))->assertUnprocessable();
        $this->assertSame(1, LoanPayment::whereNotNull('voided_at')->count());

        // rantai HMAC jejak audit tetap utuh
        $this->artisan('audit:verifikasi')->assertSuccessful();
    }

    public function test_audit_endpoint_restricted(): void
    {
        $loan = Loan::factory()->create();
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.audit.loan', $loan))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.audit.loan', $loan))->assertUnauthorized();
    }
}
