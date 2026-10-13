<?php

namespace Tests\Feature\Sistem;

use App\Models\Backup;
use App\Models\Notification;
use App\Models\User;
use App\Services\HealthCheckService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthAlertTest extends TestCase
{
    use RefreshDatabase;

    private function alerts(): \Illuminate\Support\Collection
    {
        return Notification::where('dedupe_key', 'like', 'health-%')->orderBy('id')->get();
    }

    public function test_degradation_alerts_once_and_recovery_notifies(): void
    {
        Backup::factory()->create(['started_at' => now()->subHour()]);
        Cache::put('health:scheduler_heartbeat', now()->toDateTimeString());
        HealthCheckService::record();
        $this->assertCount(0, $this->alerts()); // semua normal

        Cache::put('health:scheduler_heartbeat', now()->subMinutes(30)->toDateTimeString());
        HealthCheckService::record();
        HealthCheckService::record(); // tetap gangguan → tidak dobel
        $alert = $this->alerts()->sole();
        $this->assertSame(['Gangguan: Penjadwal tugas', 'admin', 'sistem'], [$alert->title, $alert->target, $alert->type]);
        $this->assertContains($alert->id, array_column(app(NotificationService::class)->list(auth()->user()), 'id'));
        $this->assertNotContains($alert->id, array_column(app(NotificationService::class)->list(User::where('email', 'kasir@koperasi.test')->first()), 'id'));

        Cache::put('health:scheduler_heartbeat', now()->toDateTimeString());
        HealthCheckService::record();
        $this->assertSame(['Penjadwal tugas kembali normal'], $this->alerts()->pluck('title')->all());
    }

    public function test_warning_then_worse_replaces_alert(): void
    {
        Backup::factory()->create(['started_at' => now()->subHour()]);
        HealthCheckService::record(); // penjadwal belum ada denyut → perhatian
        $this->assertSame('Perlu perhatian: Penjadwal tugas', $this->alerts()->firstWhere('dedupe_key', 'health-alert-scheduler')->title);
        Cache::put('health:scheduler_heartbeat', now()->subMinutes(30)->toDateTimeString());
        HealthCheckService::record();
        $this->assertSame(['Gangguan: Penjadwal tugas'], $this->alerts()->where('dedupe_key', 'health-alert-scheduler')->pluck('title')->values()->all());
    }
}
