<?php

namespace Tests\Feature\Sistem;

use App\Models\HealthCheck;
use App\Services\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HealthHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function snap(int $minutesAgo, string $schedulerStatus): void
    {
        HealthCheck::create(['status' => $schedulerStatus === 'ok' ? 'ok' : $schedulerStatus, 'duration_ms' => 12, 'created_at' => now()->subMinutes($minutesAgo),
            'results' => ['database' => ['status' => 'ok', 'detail' => 'Terhubung'], 'scheduler' => ['status' => $schedulerStatus, 'detail' => "Penjadwal {$schedulerStatus}"]]]);
    }

    public function test_command_records_snapshot_and_prunes(): void
    {
        HealthCheck::create(['status' => 'ok', 'results' => [], 'created_at' => now()->subDays(31)]);
        $this->artisan('kesehatan:periksa')->expectsOutputToContain('Kesehatan sistem:')->assertSuccessful();
        $row = HealthCheck::sole();
        $this->assertArrayHasKey('database', $row->results);
        $this->assertSame('ok', $row->results['database']['status']);
    }

    public function test_history_strip_and_incidents_from_transitions(): void
    {
        $this->snap(180, 'ok');
        $this->snap(120, 'gangguan');
        $this->snap(115, 'peringatan');
        $this->snap(60, 'ok');

        $hist = HealthCheckService::history(24);
        $this->assertCount(24, $hist['scheduler']);
        $statuses = array_values(array_filter(array_column($hist['scheduler'], 'status')));
        $this->assertContains('gangguan', $statuses);

        $incidents = collect(HealthCheckService::report()['incidents'])->where('check', 'scheduler')->values();
        $this->assertCount(1, $incidents);
        $this->assertSame(['gangguan', 'Penjadwal gangguan'], [$incidents[0]['status'], $incidents[0]['message']]);
        $this->assertNotNull($incidents[0]['resolved_at']);

        Livewire::test(\App\Livewire\Sistem\Health::class)->assertSee('Riwayat 24 jam')->assertSeeHtml('data-health-history');
    }

    public function test_empty_history_message_and_schedule(): void
    {
        Livewire::test(\App\Livewire\Sistem\Health::class)->assertSee('Belum ada riwayat');
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'kesehatan:periksa') && $e->expression === '*/5 * * * *'));
    }
}
