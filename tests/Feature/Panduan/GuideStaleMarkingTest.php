<?php

namespace Tests\Feature\Panduan;

use App\Jobs\SyncGuides;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideMenuSnapshot;
use App\Models\Role;
use App\Services\GuideMetadataService;
use App\Services\GuideService;
use App\Services\GuideStaleMarker;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Logika penandaan panduan usang dari perubahan menu/halaman/izin. */
class GuideStaleMarkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function diff(array $changed = [], array $removed = []): array
    {
        return ['added' => [], 'removed' => $removed, 'changed' => $changed, 'first_scan' => false];
    }

    private function marker(): GuideStaleMarker
    {
        return app(GuideStaleMarker::class);
    }

    public function test_plain_auto_guides_are_not_marked(): void
    {
        $this->assertSame([], $this->marker()->mark($this->diff(['kas' => ['pages'], 'barang' => ['roles']])));
        $this->assertSame(0, GuideEntry::where('is_stale', true)->count());
    }

    public function test_manual_guide_marked_for_any_aspect(): void
    {
        GuideEntry::where('key', 'kas')->update(['source' => 'manual']);
        $this->assertSame(['kas'], $this->marker()->mark($this->diff(['kas' => ['roles']])));
        $e = GuideEntry::where('key', 'kas')->first();
        $this->assertTrue($e->is_stale);
        $this->assertSame('Panduan manual: peran pemegang menu Kas Harian berubah sejak panduan ditulis.', $e->stale_reason);
        $this->assertSame(1, ActivityLog::where('event', 'guide.stale_marked')->count());

        $this->assertSame([], $this->marker()->mark($this->diff(['kas' => ['roles']])));         // tidak diulang
        $this->assertSame(1, ActivityLog::where('event', 'guide.stale_marked')->count());
    }

    public function test_overridden_auto_guide_marked_only_when_content_aspects_change(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'steps', [['text' => 'Langkah versi admin untuk kas']], null);
        $this->assertSame([], $this->marker()->mark($this->diff(['kas' => ['roles']])));         // peran saja: suntingan tetap sah
        $this->assertSame(['kas'], $this->marker()->mark($this->diff(['kas' => ['pages', 'roles']])));
        $this->assertStringStartsWith('Suntingan admin pada langkah mungkin tidak sesuai lagi: halaman dan peran pemegang', GuideEntry::where('key', 'kas')->value('stale_reason'));

        $barang = GuideEntry::where('key', 'barang')->first();
        app(GuideService::class)->setOverride($barang, 'title', 'Judul barang versi admin', null);
        $this->assertSame([], $this->marker()->mark($this->diff(['barang' => ['pages']])));      // judul saja tidak terdampak
    }

    public function test_removed_menu_is_marked(): void
    {
        $this->assertSame(['promo'], $this->marker()->mark($this->diff([], ['promo'])));
        $this->assertSame('Menu Diskon & Promo sudah tidak ada di sistem.', GuideEntry::where('key', 'promo')->value('stale_reason'));
        $this->assertSame([], $this->marker()->mark(['added' => [], 'removed' => ['promo'], 'changed' => [], 'first_scan' => true]));
    }

    public function test_sync_job_marks_stale_after_permission_change_and_records_count(): void
    {
        GuideEntry::where('key', 'laporan')->update(['source' => 'manual']);
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'laporan']]);
        $job = SyncGuides::enqueue('permission_changed')->fresh();
        $this->assertSame(1, $job->stats['stale']);
        $this->assertTrue(GuideEntry::where('key', 'laporan')->value('is_stale'));
        $this->assertSame([], app(GuideMetadataService::class)->diff()['changed']);  // garis dasar diperbarui

        $again = SyncGuides::enqueue('scheduled')->fresh();                                          // tidak ada perubahan baru
        $this->assertSame(0, $again->stats['stale']);
    }

    public function test_page_change_on_auto_guide_is_rebuilt_not_marked(): void
    {
        GuideMenuSnapshot::where('menu_key', 'kas')->update(['pages_hash' => str_repeat('0', 64)]);
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        $job = SyncGuides::enqueue('menu_changed')->fresh();
        $this->assertSame([0, 1], [$job->stats['stale'], $job->stats['updated']]);
        $this->assertFalse(GuideEntry::where('key', 'kas')->value('is_stale'));
    }
}
