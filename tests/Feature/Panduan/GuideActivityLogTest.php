<?php

namespace Tests\Feature\Panduan;

use App\Jobs\SyncGuides;
use App\Models\ActivityLog;
use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\GuideDraftService;
use App\Services\GuideGenerator;
use App\Services\GuideOverrideService;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Setiap perubahan panduan tercatat di activity_logs (kategori panduan, ber-HMAC, pelaku & alasan). */
class GuideActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
    }

    public function test_scheduled_sync_with_changes_is_audited_as_system(): void
    {
        auth('web')->logout();                                                              // penjadwal/antrean: tanpa sesi petugas
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        GuideEntry::where('key', 'laporan')->update(['source' => 'manual']);
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'laporan']]);
        $job = SyncGuides::enqueue('scheduled')->fresh();
        $log = ActivityLog::where('event', 'guide.synced')->sole();
        $this->assertSame(['panduan', 'Sistem', 'guide_sync', $job->id], [$log->category, $log->actor_name, $log->subject_type, (int) $log->subject_id]);
        $this->assertSame(['kas'], $log->properties['updated']);
        $this->assertSame(['laporan'], $log->properties['stale']);
        $this->assertSame('scheduled', $log->properties['trigger']);
        $this->assertStringContainsString('Terjadwal, #'.$job->id, $log->description);

        SyncGuides::enqueue('scheduled');                                                     // tanpa perubahan → tanpa catatan baru
        $this->assertSame(1, ActivityLog::where('event', 'guide.synced')->count());
    }

    public function test_every_change_path_writes_valid_hmac_logs(): void
    {
        $this->actingAs($this->admin, 'web');
        $kas = GuideEntry::where('key', 'kas')->first();
        $svc = app(GuideOverrideService::class);
        $svc->save($kas, 'title', 'Judul admin', $this->admin, 'Sunting');
        $svc->revert($kas, 'title', $this->admin, 'Kembali');
        $svc->restore($kas->fresh(), GuideVersion::where('guide_entry_id', $kas->id)->where('version', 2)->first(), $this->admin, 'Pulihkan v2');
        app(GuideService::class)->markStale($kas, 'Prosedur berubah', $this->admin);
        app(GuideService::class)->markReviewed($kas->fresh(), $this->admin, 'Dicek');
        GuideEntry::where('key', 'barang')->update(['source' => 'manual', 'title' => 'Barang manual']);
        app(GuideGenerator::class)->sync($this->admin);
        $draft = GuideDraft::sole();
        app(GuideDraftService::class)->apply($draft, $this->admin, 'Terapkan');

        $events = ActivityLog::where('category', 'panduan')->pluck('event')->all();
        foreach (['guide.overridden', 'guide.reverted', 'guide.restored', 'guide.stale_marked', 'guide.reviewed', 'guide.draft_applied'] as $e) {
            $this->assertContains($e, $events, $e);
        }
        foreach (ActivityLog::where('category', 'panduan')->get() as $log) {
            $this->assertTrue(AuditIntegrity::isValid($log), "HMAC {$log->event}");
            $this->assertSame($this->admin->id, $log->actor_id, $log->event);
        }
    }
}
