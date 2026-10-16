<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Updates;
use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\GuideSyncRecorder;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Status & riwayat sinkronisasi panduan (guide_sync_jobs): pencatatan proses, panel riwayat, saring status. */
class GuideSyncRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs($this->admin(), 'web');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@koperasi.test')->first();
    }

    public function test_recorder_logs_successful_run(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        ['job' => $job] = app(GuideSyncRecorder::class)->run('manual', $this->admin(), 'Uji', ['kas']);
        $job->refresh();
        $this->assertSame(['done', 'manual', $this->admin()->id, ['kas'], 'Uji'], [$job->status, $job->trigger, $job->triggered_by, $job->scope, $job->note]);
        $this->assertSame(1, $job->stats['updated']);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull($job->durationSeconds());
    }

    public function test_recorder_logs_failure_and_rethrows(): void
    {
        $this->mock(GuideGenerator::class)->shouldReceive('sync')->andThrow(new \RuntimeException('Pemindaian rute gagal'));
        try {
            app(GuideSyncRecorder::class)->run('scheduled');
            $this->fail('galat seharusnya dilempar ulang');
        } catch (\RuntimeException) {
        }
        $job = GuideSyncJob::latest('id')->first();
        $this->assertSame('failed', $job->status);
        $this->assertSame('RuntimeException: Pemindaian rute gagal', $job->error);
        $this->assertNotNull($job->finished_at);
    }

    public function test_panel_apply_and_command_create_runs(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        Livewire::test(Updates::class)->call('check')->call('apply');
        $this->artisan('panduan:susun')->assertSuccessful();
        $runs = GuideSyncJob::orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame([$this->admin()->id, null], $runs->pluck('triggered_by')->all());
        $this->assertSame(['done', 'done'], $runs->pluck('status')->all());
        $this->assertNull($runs[0]->scope);
    }

    public function test_history_tab_lists_runs_and_filters_by_status(): void
    {
        GuideSyncJob::create(['status' => 'done', 'trigger' => 'scheduled', 'stats' => ['created' => 0, 'updated' => 2, 'unchanged' => 12, 'skipped_manual' => 1], 'started_at' => now()->subSeconds(3), 'finished_at' => now()]);
        GuideSyncJob::create(['status' => 'failed', 'trigger' => 'menu_changed', 'error' => 'RuntimeException: <b>gagal</b>', 'started_at' => now(), 'finished_at' => now()]);
        $c = Livewire::withQueryParams(['tab' => 'riwayat'])->test(Updates::class)
            ->assertSeeHtml('data-guide-sync-runs')->assertSeeHtml('data-run-status="done"')->assertSeeHtml('data-run-status="failed"')
            ->assertSeeText('2 diperbarui')->assertSeeText('1 manual')->assertSeeText('Terjadwal')->assertSeeText('Menu berubah')
            ->assertSeeText('Semua (2)')->assertSeeHtml('&lt;b&gt;gagal&lt;/b&gt;');                       // galat di-escape
        $c->set('runStatus', 'failed')->assertDontSeeHtml('data-run-status="done"')->assertSeeHtml('data-run-status="failed"')
            ->set('runStatus', 'aneh')->assertSet('runStatus', '');
        Livewire::withQueryParams([])->test(Updates::class)->assertSeeHtml('data-last-run-status="failed"');
    }

    public function test_empty_history(): void
    {
        Livewire::withQueryParams(['tab' => 'riwayat'])->test(Updates::class)->assertSeeHtml('data-runs-empty');
        Livewire::withQueryParams([])->test(Updates::class)->assertDontSeeHtml('data-last-run-status');
    }
}
