<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\RoleImpersonation;
use App\Models\User;
use App\Services\AuditLog;
use App\Support\AuditVerificationStatus;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrityPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin);
        Cache::forget(AuditVerificationStatus::KEY);
    }

    /** Ubah satu baris log langsung (trigger append-only dilepas sementara — mensimulasikan akses DB ilegal). */
    private function tamper(int $id): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE activity_logs DISABLE TRIGGER USER');
            DB::table('activity_logs')->where('id', $id)->update(['description' => 'diubah diam-diam']);
            DB::statement('ALTER TABLE activity_logs ENABLE TRIGGER USER');
        } else {
            $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'activity_logs'");
            foreach ($triggers as $t) {
                DB::statement("DROP TRIGGER {$t->name}");
            }
            DB::table('activity_logs')->where('id', $id)->update(['description' => 'diubah diam-diam']);
            foreach ($triggers as $t) {
                DB::unprepared($t->sql);
            }
        }
    }

    public function test_panel_shows_never_checked_state(): void
    {
        $this->get('/audit/peran-impersonasi')->assertOk()->assertSeeHtml('data-verification-status="belum"')->assertSeeHtml('data-verify-now');
    }

    public function test_scheduled_command_result_is_shown(): void
    {
        AuditLog::record('uji.satu', 'kas', 'satu', null);
        $this->artisan('audit:verifikasi')->assertSuccessful();
        $last = AuditVerificationStatus::last();
        $this->assertSame(['jadwal', true], [$last['source'], $last['ok']]);
        $this->get('/audit/peran-impersonasi')->assertSeeHtml('data-verification-status="utuh"')->assertSeeText('pemeriksaan terjadwal');
    }

    public function test_manual_check_finds_tampered_rows_and_lists_them(): void
    {
        AuditLog::record('uji.satu', 'kas', 'satu', null);
        $bad = AuditLog::record('uji.dua', 'kas', 'dua', null);
        $this->tamper($bad->id);

        Livewire::test(RoleImpersonation::class)->call('verifyNow')
            ->assertDispatched('toast', type: 'error')
            ->assertSeeHtml('data-verification-status="rusak"')
            ->assertSeeHtml('data-invalid-rows')
            ->assertSeeText('#'.$bad->id)
            ->assertSeeText('diperiksa manual oleh '.$this->admin->name);
        $this->assertSame([$bad->id], AuditVerificationStatus::last()['invalid']);
        $this->artisan('audit:verifikasi')->assertFailed(); // jadwal juga mendeteksi & mengirim notifikasi
    }

    public function test_manual_check_is_admin_only_and_rate_limited(): void
    {
        $c = Livewire::test(RoleImpersonation::class);
        $c->call('verifyNow')->call('verifyNow')->call('verifyNow');
        $c->call('verifyNow')->assertDispatched('toast', type: 'error', message: 'Terlalu sering — coba lagi dalam 10 menit.');

        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        Cache::forget(AuditVerificationStatus::KEY);
        $this->get('/audit/peran-impersonasi')->assertOk()->assertDontSeeHtml('data-verify-now');
        Livewire::test(RoleImpersonation::class)->call('verifyNow')->assertDispatched('toast', type: 'error', message: 'Hanya Admin yang dapat menjalankan pemeriksaan.');
        $this->assertNull(AuditVerificationStatus::last());
    }

    public function test_partial_command_run_does_not_replace_last_full_result(): void
    {
        $this->artisan('audit:verifikasi')->assertSuccessful();
        $at = AuditVerificationStatus::last()['at'];
        $this->travel(1)->minutes();
        $this->artisan('audit:verifikasi', ['--dari' => 1])->assertSuccessful();
        $this->assertSame($at, AuditVerificationStatus::last()['at']);
    }
}
