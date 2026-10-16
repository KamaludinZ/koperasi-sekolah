<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Portal\Guide;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanDisbursementService;
use App\Services\PortalPinService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\FakesImpersonation;
use Tests\TestCase;

/** Endpoint panduan anggota berbasis sesi portal: pemberitahuan PIN, tunggakan, mode baca-saja; tanpa bocor PIN. */
class MemberGuideApiTest extends TestCase
{
    use FakesImpersonation, RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->andi = Member::factory()->create(['name' => 'Andi']);
        $this->actingAsMember($this->andi);
    }

    public function test_list_and_single_with_session_meta(): void
    {
        $res = $this->getJson('/portal/panduan/data')->assertOk();
        $this->assertSame(['portal.beranda', 'portal.simpanan', 'portal.pinjaman', 'portal.belanja', 'portal.riwayat'], array_column($res->json('data'), 'key'));
        $this->assertSame(['has_pin' => true, 'locked' => false, 'minutes' => 0, 'attempts_left' => 3], $res->json('meta.pin'));
        $this->assertSame(0, $res->json('meta.overdue_installments'));
        $this->assertFalse($res->json('meta.read_only'));
        $this->assertSame([], $res->json('data.0.notices'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('123456', $res->getContent());                         // nilai PIN tak pernah dikirim

        $this->getJson('/portal/panduan/data/portal.belanja')->assertOk()->assertJsonPath('data.key', 'portal.belanja')
            ->assertJsonPath('data.notices', [])->assertJsonPath('meta.pin.has_pin', true);
        $this->getJson('/portal/panduan/data/kasir')->assertNotFound();                             // panduan petugas
        $this->getJson('/portal/panduan/data/tidak-ada')->assertNotFound();
        $this->getJson('/portal/panduan/data?q=angsuran')->assertOk()->assertJsonPath('data.0.key', 'portal.pinjaman');
    }

    public function test_pin_not_set_and_locked_notices(): void
    {
        $this->andi->forceFill(['pin' => null])->save();
        $this->getJson('/portal/panduan/data/portal.belanja')->assertOk()->assertJsonPath('data.notices.0.code', 'pin_belum_diatur');
        $this->getJson('/portal/panduan/data/portal.riwayat')->assertOk()->assertJsonPath('data.notices', []);

        $this->andi->forceFill(['pin' => '123456'])->save();
        foreach (range(1, 3) as $_) {
            try {
                app(PortalPinService::class)->verify($this->andi->fresh(), '000000');
            } catch (ValidationException) {
            }
        }
        $res = $this->getJson('/portal/panduan/data/portal.belanja')->assertOk();
        $this->assertSame('pin_terkunci', $res->json('data.notices.0.code'));
        $this->assertTrue($res->json('meta.pin.locked'));
        $this->assertGreaterThan(0, $res->json('meta.pin.minutes'));
        Livewire::test(Guide::class)->call('open', 'portal.belanja')->assertSeeHtml('data-portal-guide-notice="pin_terkunci"');
    }

    public function test_overdue_installments_notice(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
        $loan = Loan::factory()->create(['code' => 'PJM-1', 'member_id' => $this->andi->id, 'status' => 'disetujui', 'principal' => 600000, 'interest' => 0, 'tenor_months' => 2, 'applied_at' => '2026-08-01']);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-08-10', 'amount' => 600000]);
        $this->travelTo('2026-09-12 08:00:00');
        $res = $this->getJson('/portal/panduan/data/portal.pinjaman')->assertOk();
        $this->assertSame(1, $res->json('meta.overdue_installments'));
        $this->assertSame('angsuran_menunggak', $res->json('data.notices.0.code'));
        $this->get('/portal/panduan?topik=portal.pinjaman')->assertOk()->assertSeeHtml('data-portal-guide-notice="angsuran_menunggak"');
    }

    public function test_impersonation_marks_read_only(): void
    {
        $admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($admin, 'web');
        session($this->impersonationRecord([
            'id' => 'aaaaaaaa-1111-2222-3333-444444444444', 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'target_type' => 'anggota', 'target_id' => $this->andi->id, 'target_name' => 'Andi', 'target_label' => $this->andi->member_code,
            'reason' => 'Membantu anggota yang tidak bisa memakai portal', 'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(30)->timestamp,
        ]));
        $res = $this->getJson('/portal/panduan/data/portal.belanja')->assertOk();
        $this->assertTrue($res->json('meta.read_only'));
        $this->assertSame('baca_saja', $res->json('data.notices.0.code'));
    }

    public function test_requires_portal_session(): void
    {
        auth('member')->logout();
        $this->getJson('/portal/panduan/data')->assertUnauthorized();
        $this->getJson('/portal/panduan/data/portal.belanja')->assertUnauthorized();
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');      // petugas bukan anggota
        $this->getJson('/portal/panduan/data/portal.belanja')->assertUnauthorized();
    }
}
