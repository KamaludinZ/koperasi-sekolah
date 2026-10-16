<?php

namespace Tests\Feature\Sistem;

use App\Jobs\ExportCashRecap;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_connection_and_tables(): void
    {
        $db = config('queue.connections.database');
        $this->assertSame(['database', true, 330], [$db['driver'], $db['after_commit'], $db['retry_after']]);
        $this->assertGreaterThan((new ExportCashRecap(1))->timeout, $db['retry_after']);
        $this->assertSame('database-uuids', config('queue.failed.driver'));
        $this->assertTrue(Schema::hasTable('jobs') && Schema::hasTable('failed_jobs') && Schema::hasTable('job_batches'));
        $this->assertSame(['notifikasi', 'ekspor', 'default', 'cadangan'], array_values(config('koperasi.queues')));
    }

    public function test_export_job_queue_and_backoff(): void
    {
        $job = new ExportCashRecap(1);
        $this->assertSame(['ekspor', [30], 2], [$job->queue, $job->backoff, $job->tries]);
    }

    public function test_prune_schedules(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->join("\n");
        $this->assertStringContainsString('queue:prune-failed', $commands);
        $this->assertStringContainsString('--hours=720', $commands);
        $this->assertStringContainsString('queue:prune-batches', $commands);
    }

    public function test_queue_via_cron_only_runs_when_enabled(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'queue:work'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--queue=notifikasi,ekspor,default,cadangan', $event->command);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertSame('* * * * *', $event->expression);

        $this->assertFalse($event->filtersPass($this->app)); // bawaan: pekerja tetap (supervisord), bukan cron
        config(['koperasi.queue_via_cron' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
