<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Audit\RoleImpersonation;
use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\RoleImpersonationTrail;
use App\Support\AuditGuard;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EndStartChainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function finishedSession(): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $this->post('/impersonasi/selesai');

        return Impersonation::latest('started_at')->first();
    }

    private function trail(): RoleImpersonationTrail
    {
        return app(RoleImpersonationTrail::class);
    }

    public function test_end_log_points_to_start_log_and_its_checksum(): void
    {
        $s = $this->finishedSession();
        $end = ActivityLog::find($s->end_log_id);
        $start = DB::table('activity_logs')->where('id', $s->start_log_id)->first();
        $this->assertSame([$s->start_log_id, $start->checksum], [$end->properties['start_log_id'], $end->properties['start_checksum']]);
        $this->assertTrue(AuditIntegrity::isValid($end)); // tautan ikut ditandatangani
        $this->assertSame(['start' => true, 'end' => true, 'linked' => true, 'ok' => true, 'problem' => null], $this->trail()->chain($s));
    }

    public function test_active_session_chain_is_ok_without_end_log(): void
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $c = $this->trail()->chain(Impersonation::sole());
        $this->assertTrue($c['ok']);
        $this->assertNull($c['end']);
    }

    public function test_tampered_start_log_breaks_the_chain(): void
    {
        $s = $this->finishedSession();
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $s->start_log_id)->update(['reason' => 'alasan dipalsukan']));
        $c = $this->trail()->chain($s);
        $this->assertFalse($c['ok']);
        $this->assertSame('Catatan mulai tidak cocok', $c['problem']);
    }

    public function test_replaced_start_log_with_new_valid_signature_still_breaks_link(): void
    {
        $s = $this->finishedSession();
        // penyerang yang memegang kunci menandatangani ulang catatan mulai yang diubah — tautan di catatan selesai tetap menunjuk tanda lama
        AuditGuard::suspend(function () use ($s) {
            $row = (array) DB::table('activity_logs')->where('id', $s->start_log_id)->first();
            $row['reason'] = 'alasan dipalsukan rapi';
            DB::table('activity_logs')->where('id', $s->start_log_id)->update(['reason' => $row['reason'], 'checksum' => AuditIntegrity::checksum($row)]);
        });
        $c = $this->trail()->chain($s);
        $this->assertTrue($c['start']);
        $this->assertFalse($c['linked']);
        $this->assertSame('Tautan selesai → mulai putus', $c['problem']);
    }

    public function test_deleted_logs_are_reported(): void
    {
        $s = $this->finishedSession();
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $s->end_log_id)->delete());
        $this->assertSame('Catatan selesai tidak ditemukan', $this->trail()->chain($s)['problem']);
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $s->start_log_id)->delete());
        $this->assertSame('Catatan mulai tidak ditemukan', $this->trail()->chain($s)['problem']);
    }

    public function test_audit_page_shows_chain_status(): void
    {
        $s = $this->finishedSession();
        Livewire::test(RoleImpersonation::class)->set('tab', 'impersonasi')->assertSeeHtml('data-seal="utuh"')->assertSeeText('mulai–selesai tertaut');
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $s->start_log_id)->update(['reason' => 'alasan dipalsukan']));
        Livewire::test(RoleImpersonation::class)->set('tab', 'impersonasi')->assertSeeHtml('data-seal="rusak"')->assertSeeText('Catatan mulai tidak cocok');
    }
}
