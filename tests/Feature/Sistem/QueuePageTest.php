<?php

namespace Tests\Feature\Sistem;

use App\Jobs\ExportCashRecap;
use App\Livewire\Sistem\Queue;
use App\Models\ActivityLog;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class QueuePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']); // ulangi tugas masuk ke tabel jobs
    }

    private function payload(string $class): string
    {
        $command = $class === ExportCashRecap::class ? serialize(new ExportCashRecap(1)) : 'O:8:"stdClass":0:{}';

        return json_encode(['uuid' => 'x', 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $class, 'command' => $command]]);
    }

    private function failedJob(string $uuid, string $class = ExportCashRecap::class): void
    {
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'ekspor', 'payload' => $this->payload($class),
            'exception' => "RuntimeException: Disk penuh\n#0 stack", 'failed_at' => now()]);
    }

    public function test_page_in_nav(): void
    {
        $this->get('/sistem')->assertSee(route('sistem.antrean'));
        $this->get('/sistem/antrean')->assertOk()->assertSee('Pekerja antrean')->assertSee('Antrean kosong.')->assertSee('Tidak ada tugas gagal.')
            ->assertSee('Belum ada pekerjaan selesai yang tercatat.')->assertSee('queue:work');
    }

    public function test_lists_pending_failed_and_worker_status(): void
    {
        DB::table('jobs')->insert(['queue' => 'ekspor', 'payload' => $this->payload(ExportCashRecap::class), 'attempts' => 1, 'available_at' => time(), 'created_at' => time() - 45 * 60]);
        $this->failedJob('u-1');
        $c = Livewire::test(Queue::class);
        $this->assertSame([['Ekspor rekap kas', 45, 1]], array_map(fn ($p) => [$p['name'], $p['waiting_min'], $p['attempts']], $c->instance()->pending));
        $this->assertFalse($c->instance()->worker['running']);
        $c->assertSee('RuntimeException: Disk penuh')->assertDontSee('#0 stack')->assertSee('Pekerja antrean tidak aktif');

        \Illuminate\Support\Facades\Cache::put('health:worker_heartbeat', now()->toDateTimeString());
        $this->assertTrue(Livewire::test(Queue::class)->instance()->worker['running']);
    }

    public function test_retry_and_forget_are_real(): void
    {
        $this->failedJob('u-1');
        $this->failedJob('u-2');
        $c = Livewire::test(Queue::class)->call('retry', 'u-1')->assertDispatched('toast');
        $this->assertSame(['u-2'], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertSame(1, DB::table('jobs')->count()); // kembali ke antrean
        $c->call('forget', 'u-2');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertTrue(ActivityLog::where('event', 'queue.retried')->exists());
        $this->assertTrue(ActivityLog::where('event', 'queue.forgotten')->exists());

        $this->failedJob('a');
        $this->failedJob('b');
        Livewire::test(Queue::class)->call('retryAll')->assertDispatched('toast');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_finished_jobs_are_recorded(): void
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('getJobId')->andReturn('7');
        $job->shouldReceive('resolveName')->andReturn(ExportCashRecap::class);
        $job->shouldReceive('getQueue')->andReturn('ekspor');
        QueueService::jobStarted($job);
        QueueService::jobFinished($job);
        $recent = app(QueueService::class)->recent();
        $this->assertSame(['Ekspor rekap kas', 'ekspor'], [$recent[0]['name'], $recent[0]['queue']]);
        $this->assertSame(1, app(QueueService::class)->worker()['processed_today']);
    }

    public function test_api_and_admin_only(): void
    {
        $this->failedJob('u-9');
        $this->getJson(route('sistem.antrean.data'))->assertOk()->assertJsonPath('failed.0.uuid', 'u-9')->assertJsonStructure(['worker', 'pending', 'failed', 'recent']);
        $this->postJson(route('sistem.antrean.retry', 'tidak-ada'))->assertNotFound();
        $this->deleteJson(route('sistem.antrean.forget', 'u-9'))->assertOk();
        $this->getJson(route('sistem.kesehatan.riwayat', ['jam' => 6]))->assertOk()->assertJsonStructure(['history', 'incidents']);
        $this->loginAs('kasir@koperasi.test');
        $this->get('/sistem/antrean')->assertForbidden();
        $this->getJson(route('sistem.antrean.data'))->assertForbidden();
    }
}
