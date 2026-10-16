<?php

namespace Tests\Feature\Panduan;

use App\Jobs\SyncGuides;
use App\Models\GuideEntry;
use App\Models\GuideMenuSnapshot;
use App\Models\GuideSyncJob;
use App\Models\Role;
use App\Services\GuideMetadataService;
use App\Services\GuideSyncRecorder;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Job sinkronisasi panduan terjadwal & berbasis perubahan menu/izin. */
class GuideSyncJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    public function test_job_runs_sync_records_history_and_refreshes_baseline(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        $job = SyncGuides::enqueue('scheduled', 'Sinkronisasi harian');                      // antrean sync di tes → langsung jalan
        $job->refresh();
        $this->assertSame(['done', 'scheduled', 'Sinkronisasi harian', 1], [$job->status, $job->trigger, $job->note, $job->stats['updated']]);
        $this->assertNotSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));
    }

    public function test_job_is_queued_on_default_queue_and_unique_per_trigger(): void
    {
        Queue::fake();
        $a = SyncGuides::enqueue('menu_changed');
        Queue::assertPushedOn('default', SyncGuides::class, fn ($j) => $j->trigger === 'menu_changed' && $j->syncJobId === $a->id);
        $this->assertSame('queued', $a->status);
        $this->assertSame('menu_changed', (new SyncGuides('menu_changed'))->uniqueId());
    }

    public function test_duplicate_delivery_is_ignored(): void
    {
        $job = SyncGuides::enqueue('scheduled');
        $before = $job->fresh()->finished_at;
        (new SyncGuides('scheduled', $job->id))->handle(app(GuideSyncRecorder::class));
        $this->assertEquals($before, $job->fresh()->finished_at);
        $this->assertSame(1, GuideSyncJob::count());
    }

    public function test_busy_lock_releases_back_to_queue(): void
    {
        Queue::fake();
        $record = SyncGuides::enqueue('scheduled');
        $lock = Cache::lock(GuideSyncRecorder::LOCK, 60);
        $lock->get();
        $job = (new SyncGuides('scheduled', $record->id))->withFakeQueueInteractions();
        $job->handle(app(GuideSyncRecorder::class));
        $job->assertReleased(60);
        $this->assertSame('queued', $record->fresh()->status);
        $lock->release();
    }

    public function test_failed_marks_history(): void
    {
        Queue::fake();
        $record = SyncGuides::enqueue('scheduled');
        (new SyncGuides('scheduled', $record->id))->failed(new \RuntimeException('rusak'));
        $this->assertSame(['failed', 'RuntimeException: rusak'], [$record->fresh()->status, $record->fresh()->error]);
    }

    public function test_change_detection_command_queues_matching_trigger(): void
    {
        Queue::fake();
        $this->artisan('panduan:periksa', ['--antre' => true])->expectsOutput('Tidak ada perubahan menu/izin.')->assertSuccessful();
        Queue::assertNothingPushed();

        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'laporan']]);
        $this->artisan('panduan:periksa')->expectsOutputToContain('laporan (roles)')->assertSuccessful();
        Queue::assertNothingPushed();                                                       // tanpa --antre hanya melapor
        $this->artisan('panduan:periksa', ['--antre' => true])->expectsOutputToContain('Sinkronisasi diantre')->assertSuccessful();
        Queue::assertPushed(SyncGuides::class, fn ($j) => $j->trigger === 'permission_changed');
        $this->artisan('panduan:periksa', ['--antre' => true])->expectsOutput('Sinkronisasi serupa sudah mengantre.')->assertSuccessful();
        Queue::assertPushed(SyncGuides::class, 1);
    }

    public function test_full_sync_refreshes_baseline_so_change_is_handled_once(): void
    {
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'laporan']]);
        $this->artisan('panduan:periksa', ['--antre' => true])->assertSuccessful();           // sync antrean → langsung jalan
        $this->assertSame('permission_changed', GuideSyncJob::latest('id')->value('trigger'));
        $this->assertSame('done', GuideSyncJob::latest('id')->value('status'));
        $this->assertSame([], app(GuideMetadataService::class)->diff()['changed']);
        $this->artisan('panduan:periksa', ['--antre' => true])->expectsOutput('Tidak ada perubahan menu/izin.');
    }

    public function test_trigger_mapping_and_first_scan(): void
    {
        $this->assertNull(GuideMetadataService::triggerFor(['added' => [], 'removed' => [], 'changed' => [], 'first_scan' => false]));
        $this->assertSame('permission_changed', GuideMetadataService::triggerFor(['added' => [], 'removed' => [], 'changed' => ['kas' => ['roles']], 'first_scan' => false]));
        $this->assertSame('menu_changed', GuideMetadataService::triggerFor(['added' => [], 'removed' => [], 'changed' => ['kas' => ['roles', 'pages']], 'first_scan' => false]));
        $this->assertSame('menu_changed', GuideMetadataService::triggerFor(['added' => ['baru'], 'removed' => [], 'changed' => [], 'first_scan' => false]));
        GuideMenuSnapshot::query()->delete();
        $this->artisan('panduan:periksa', ['--antre' => true])->expectsOutput('Garis dasar metadata menu & izin disimpan.');
        $this->assertSame(0, GuideSyncJob::count());
    }

    public function test_scheduled_every_five_minutes_and_daily(): void
    {
        $events = collect(app(Schedule::class)->events());
        $check = $events->first(fn ($e) => str_contains((string) $e->command, 'panduan:periksa'));
        $this->assertSame('*/5 * * * *', $check->expression);
        $daily = $events->first(fn ($e) => $e->description === 'panduan:sinkron-harian');
        $this->assertSame('30 3 * * *', $daily->expression);
    }
}
