<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\AuditIntegrityFailure;
use App\Models\User;
use App\Services\AuditLog;
use App\Support\AuditGuard;
use App\Support\AuditVerificationStatus;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityEndpointTest extends TestCase
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

    private function tamperOne(): ActivityLog
    {
        AuditLog::record('uji.aksi', 'kas', 'aksi asli', null);
        $log = ActivityLog::where('event', 'uji.aksi')->sole();
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $log->id)->update(['description' => 'diubah diam-diam']));

        return $log;
    }

    public function test_status_before_any_check(): void
    {
        $this->getJson('/audit/integritas')->assertOk()
            ->assertJsonPath('data.last', null)->assertJsonPath('data.open_failures.total', 0)->assertJsonPath('data.healthy', true);
    }

    public function test_run_check_reports_and_marks_failures(): void
    {
        $log = $this->tamperOne();
        $this->postJson('/audit/integritas/periksa')->assertOk()
            ->assertJsonPath('data.ok', false)->assertJsonPath('data.invalid', [$log->id])
            ->assertJsonPath('data.new_failures', 1)->assertJsonPath('data.last.source', 'manual')
            ->assertJsonPath('data.last.by', $this->admin->name);

        $this->getJson('/audit/integritas')->assertOk()
            ->assertJsonPath('data.healthy', false)->assertJsonPath('data.open_failures.total', 1)->assertJsonPath('data.open_failures.by_kind.checksum', 1);

        $this->getJson('/audit/integritas/kegagalan')->assertOk()
            ->assertJsonPath('data.0.kind', 'checksum')->assertJsonPath('data.0.activity_log.id', $log->id)
            ->assertJsonPath('data.0.activity_log.exists', true)->assertJsonPath('data.0.activity_log.description', 'diubah diam-diam')
            ->assertJsonPath('data.0.open', true);
    }

    public function test_failures_filters_and_deleted_log(): void
    {
        AuditIntegrityFailure::create(['kind' => 'rantai', 'activity_log_id' => 999999, 'impersonation_id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'event' => 'impersonation',
            'detail' => 'Catatan selesai tidak ditemukan', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        AuditIntegrityFailure::create(['kind' => 'checksum', 'activity_log_id' => 5, 'detail' => 'lama', 'first_detected_at' => now()->subDay(),
            'last_detected_at' => now()->subDay(), 'cleared_at' => now()]);

        $this->getJson('/audit/integritas/kegagalan')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_log.exists', false)->assertJsonPath('data.0.impersonation_id', 'aaaaaaaa-0000-0000-0000-000000000001');
        $this->getJson('/audit/integritas/kegagalan?status=pulih')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.open', false);
        $this->getJson('/audit/integritas/kegagalan?status=semua&jenis=rantai')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/audit/integritas/kegagalan?status=x')->assertUnprocessable();
        $this->getJson('/audit/integritas/kegagalan?jenis=x')->assertUnprocessable();
    }

    public function test_run_is_admin_only_and_rate_limited(): void
    {
        $this->postJson('/audit/integritas/periksa')->assertOk();
        $this->postJson('/audit/integritas/periksa')->assertOk();
        $this->postJson('/audit/integritas/periksa')->assertOk();
        $this->postJson('/audit/integritas/periksa')->assertStatus(429);

        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $this->getJson('/audit/integritas')->assertOk();                       // pengurus boleh membaca
        $this->getJson('/audit/integritas/kegagalan')->assertOk();
        $this->postJson('/audit/integritas/periksa')->assertForbidden();        // tapi tidak menjalankan
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first());
        $this->getJson('/audit/integritas')->assertForbidden();
    }
}
