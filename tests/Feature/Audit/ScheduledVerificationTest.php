<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\RoleImpersonation;
use App\Models\ActivityLog;
use App\Models\AuditIntegrityFailure;
use App\Models\Impersonation;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\AuditLog;
use App\Services\AuditVerificationService;
use App\Services\UserService;
use App\Support\AuditGuard;
use App\Support\AuditVerificationStatus;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledVerificationTest extends TestCase
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
        Cache::forget(AuditVerificationStatus::KEY);
    }

    private function tamper(int $id, array $values): void
    {
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $id)->update($values));
    }

    private function impersonationSession(): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $this->post('/impersonasi/selesai');

        return Impersonation::latest('started_at')->first();
    }

    public function test_both_schedules_registered(): void
    {
        $cmds = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);
        $this->assertTrue($cmds->contains(fn ($c) => str_contains($c, 'audit:verifikasi') && ! str_contains($c, '--cepat')));
        $this->assertTrue($cmds->contains(fn ($c) => str_contains($c, 'audit:verifikasi') && str_contains($c, '--cepat')));
    }

    public function test_clean_run_marks_nothing(): void
    {
        $this->impersonationSession();
        $this->artisan('audit:verifikasi')->expectsOutputToContain('Semua tanda cocok')->assertSuccessful();
        $this->assertSame(0, AuditIntegrityFailure::count());
        $this->assertTrue(AuditVerificationStatus::last()['ok']);
    }

    public function test_tampered_log_is_marked_alerted_once_and_kept_open(): void
    {
        app(UserService::class)->addRole($this->kasir, 'pengurus', $this->admin);
        $log = ActivityLog::where('event', 'user.role_changed')->sole();
        $this->tamper($log->id, ['reason' => 'dipalsukan']);

        $this->artisan('audit:verifikasi')->expectsOutputToContain('Kegagalan baru: 1')->assertFailed();
        $f = AuditIntegrityFailure::sole();
        $this->assertSame(['checksum', $log->id, 'user.role_changed', null], [$f->kind, $f->activity_log_id, $f->event, $f->cleared_at]);
        $this->assertSame(1, Notification::where('dedupe_key', 'audit:integritas:'.now()->toDateString())->count());
        $this->assertSame(1, ActivityLog::where('event', 'audit.integrity_failed')->count());

        // jalan lagi: tetap satu penanda, tidak ada kegagalan "baru", last_detected_at maju
        $this->travel(1)->hours();
        $this->artisan('audit:verifikasi', ['--cepat' => true])->expectsOutputToContain('Kegagalan baru: 0')->assertFailed();
        $this->assertSame(1, AuditIntegrityFailure::count());
        $this->assertTrue(AuditIntegrityFailure::sole()->last_detected_at->gt($f->first_detected_at));
        $this->assertSame(1, ActivityLog::where('event', 'audit.integrity_failed')->count());
    }

    public function test_broken_impersonation_chain_is_marked(): void
    {
        $s = $this->impersonationSession();
        $this->tamper($s->start_log_id, ['reason' => 'alasan dipalsukan']);
        $sum = app(AuditVerificationService::class)->run('cepat');
        $this->assertSame(1, $sum['chain_failures']);
        $chain = AuditIntegrityFailure::where('kind', 'rantai')->sole();
        $this->assertSame($s->id, $chain->impersonation_id);
        $this->assertStringContainsString('Catatan mulai tidak cocok', $chain->detail);
        $this->assertSame(1, AuditIntegrityFailure::where('kind', 'checksum')->count()); // catatan mulai juga gagal checksum
    }

    public function test_failure_is_cleared_when_no_longer_detected(): void
    {
        AuditLog::record('uji.aksi', 'kas', 'aksi', null);
        $log = ActivityLog::where('event', 'uji.aksi')->sole();
        $raw = (array) DB::table('activity_logs')->where('id', $log->id)->first();
        $this->tamper($log->id, ['description' => 'diubah']);
        app(AuditVerificationService::class)->run('penuh');
        $this->assertSame(1, AuditIntegrityFailure::open()->count());

        $this->tamper($log->id, ['description' => $raw['description']]); // dikembalikan (mis. dari cadangan)
        app(AuditVerificationService::class)->run('penuh');
        $this->assertSame(0, AuditIntegrityFailure::open()->count());
        $this->assertNotNull(AuditIntegrityFailure::sole()->cleared_at);
        $this->assertTrue(AuditIntegrity::isValid($log));
    }

    public function test_quick_scope_does_not_clear_failures_outside_its_scope(): void
    {
        AuditLog::record('uji.aksi', 'kas', 'aksi biasa', null); // bukan catatan peran/impersonasi
        $log = ActivityLog::where('event', 'uji.aksi')->sole();
        $this->tamper($log->id, ['description' => 'diubah']);
        app(AuditVerificationService::class)->run('penuh');
        app(AuditVerificationService::class)->run('cepat'); // tidak memeriksa baris ini → jangan dianggap pulih
        $this->assertSame(1, AuditIntegrityFailure::open()->count());
    }

    public function test_manual_check_from_page_uses_same_service(): void
    {
        $s = $this->impersonationSession();
        $this->tamper($s->end_log_id, ['reason' => 'dipalsukan']);
        Livewire::test(RoleImpersonation::class)->call('verifyNow')->assertDispatched('toast', type: 'error');
        $this->assertSame(1, AuditIntegrityFailure::where('kind', 'rantai')->count());
        $this->assertSame('manual', AuditVerificationStatus::last()['source']);
    }
}
