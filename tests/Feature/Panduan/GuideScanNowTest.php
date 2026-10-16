<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Updates;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\GuideSyncInProgress;
use App\Services\GuideSyncRecorder;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** Tombol pemicu pemindaian manual: jalan langsung, tercatat, satu proses sekaligus, dibatasi frekuensinya. */
class GuideScanNowTest extends TestCase
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

    public function test_scan_now_syncs_records_run_and_audits(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        Livewire::test(Updates::class)->assertSeeHtml('data-guide-scan-now')->call('scanNow')
            ->assertSet('scanFailed', false)->assertSeeText('Pemindaian selesai: 0 baru, 1 diperbarui')
            ->assertSeeHtml('data-last-run-status="done"');
        $this->assertNotSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));
        $job = GuideSyncJob::latest('id')->first();
        $this->assertSame(['manual', 'done', $this->admin()->id], [$job->trigger, $job->status, $job->triggered_by]);
        $this->assertSame(1, ActivityLog::where('event', 'guide.synced')->count());

        Livewire::test(Updates::class)->call('scanNow')->assertSeeText('0 diperbarui');   // tidak ada perubahan → tanpa audit baru
        $this->assertSame(1, ActivityLog::where('event', 'guide.synced')->count());
        $this->assertSame(2, GuideSyncJob::count());
    }

    public function test_only_one_sync_at_a_time(): void
    {
        $lock = Cache::lock(GuideSyncRecorder::LOCK, 60);
        $this->assertTrue($lock->get());
        Livewire::test(Updates::class)->call('scanNow')->assertSet('scanFailed', true)->assertSeeText('Sinkronisasi panduan lain sedang berjalan');
        $this->assertSame(0, GuideSyncJob::count());
        try {
            app(GuideSyncRecorder::class)->run('scheduled');
            $this->fail('harus ditolak');
        } catch (GuideSyncInProgress) {
        }
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        Livewire::test(Updates::class)->call('check')->call('apply')->assertHasErrors('note');
        $this->assertSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));
        $lock->release();
        Livewire::test(Updates::class)->call('scanNow')->assertSet('scanFailed', false);
    }

    public function test_lock_released_after_failure(): void
    {
        $this->mock(GuideGenerator::class)->shouldReceive('sync')->once()->andThrow(new \RuntimeException('rusak'));
        Livewire::test(Updates::class)->call('scanNow')->assertSet('scanFailed', true)->assertSeeText('Rinciannya ada di Riwayat sinkronisasi');
        $this->assertSame('failed', GuideSyncJob::latest('id')->value('status'));
        $this->assertTrue(Cache::lock(GuideSyncRecorder::LOCK, 1)->get());                     // kunci sudah dilepas
    }

    public function test_rate_limited_per_user(): void
    {
        $c = Livewire::test(Updates::class);
        foreach (range(1, 5) as $_) {
            $c->call('scanNow')->assertSet('scanFailed', false);
        }
        $c->call('scanNow')->assertSet('scanFailed', true)->assertSeeText('Terlalu sering');
        $this->assertSame(5, GuideSyncJob::count());
        RateLimiter::clear('panduan-pindai:'.$this->admin()->id);
    }

    public function test_non_manager_cannot_trigger(): void
    {
        $c = Livewire::test(Updates::class);
        $this->admin()->roles()->detach();
        $this->admin()->update(['role_id' => Role::named('kasir')->id]);
        $this->actingAs($this->admin()->fresh(), 'web');
        $c->call('scanNow')->assertForbidden();
        $this->assertSame(0, GuideSyncJob::count());
    }
}
