<?php

namespace Tests\Feature\Panduan;

use App\Models\ActivityLog;
use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideDraftService;
use App\Services\GuideGenerator;
use App\Services\GuideService;
use App\Services\GuideSyncRecorder;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Tabel draf & versi panduan: draf untuk panduan manual, terapkan/buang dengan audit, versi terhubung ke proses sinkronisasi. */
class GuideDraftsTest extends TestCase
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

    private function drafts(): GuideDraftService
    {
        return app(GuideDraftService::class);
    }

    /** Ubah isi tersimpan seperti penyuntingan sungguhan (versi ikut dicatat). */
    private function edit(string $key, array $attrs): void
    {
        $e = GuideEntry::where('key', $key)->first();
        $e->update($attrs);
        app(GuideService::class)->recordVersion($e, 'override', 'Disunting', null);
    }

    private function manualKas(): void
    {
        $this->edit('kas', ['source' => 'manual', 'title' => 'Kas versi koperasi kami']);
    }

    public function test_sync_stages_draft_for_changed_manual_guide_instead_of_overwriting(): void
    {
        $this->manualKas();
        ['job' => $job, 'stats' => $stats] = app(GuideSyncRecorder::class)->run('manual');
        $this->assertSame(['kas'], $stats['drafted']);
        $this->assertSame('Kas versi koperasi kami', GuideEntry::where('key', 'kas')->value('title'));
        $d = GuideDraft::where('key', 'kas')->first();
        $this->assertSame(['pending', ['title'], $job->id], [$d->status, $d->changed_fields, $d->sync_job_id]);
        $this->assertNotSame('Kas versi koperasi kami', $d->payload['title']);
        $this->assertSame(1, $job->fresh()->stats['drafted']);

        app(GuideGenerator::class)->sync();                                   // diulang: tetap satu draf pending
        $this->assertSame(1, GuideDraft::where('key', 'kas')->where('status', 'pending')->count());

        GuideEntry::where('key', 'barang')->update(['source' => 'manual']);   // manual tapi isinya sama → tanpa draf
        app(GuideGenerator::class)->sync();
        $this->assertNull(GuideDraft::where('key', 'barang')->first());
    }

    public function test_one_pending_draft_per_guide_enforced_by_database(): void
    {
        $this->manualKas();
        app(GuideGenerator::class)->sync();
        $this->expectException(QueryException::class);
        GuideDraft::create(['key' => 'kas', 'payload' => [], 'changed_fields' => [], 'status' => 'pending']);
    }

    public function test_apply_draft_updates_guide_versions_and_audits(): void
    {
        $this->manualKas();
        ['job' => $job] = app(GuideSyncRecorder::class)->run('scheduled');
        $d = GuideDraft::where('key', 'kas')->first();
        $entry = $this->drafts()->apply($d, $this->admin(), 'Pakai isi otomatis');
        $this->assertSame([$d->payload['title'], 'auto'], [$entry->title, $entry->source]);
        $this->assertSame(['applied', $this->admin()->id], [$d->fresh()->status, $d->fresh()->reviewed_by]);
        $v = GuideVersion::where('guide_entry_id', $entry->id)->latest('version')->first();
        $this->assertSame(['generate', 'Pakai isi otomatis', $job->id], [$v->change_type, $v->change_note, $v->sync_job_id]);
        $this->assertSame(1, ActivityLog::where('event', 'guide.draft_applied')->count());
        $this->expectException(ValidationException::class);
        $this->drafts()->apply($d->fresh(), $this->admin());                // sudah ditinjau
    }

    public function test_outdated_draft_is_rejected(): void
    {
        $this->manualKas();
        app(GuideGenerator::class)->sync();
        GuideEntry::where('key', 'kas')->update(['summary' => 'Ringkasan diubah lagi setelah draf dibuat']);
        $d = GuideDraft::where('key', 'kas')->first();
        $this->assertTrue($this->drafts()->isOutdated($d));
        try {
            $this->drafts()->apply($d, $this->admin());
            $this->fail('draf basi seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah berubah', $e->errors()['draft'][0]);
        }
        $this->assertSame('pending', $d->fresh()->status);
    }

    public function test_discard_requires_reason_and_audits(): void
    {
        $this->manualKas();
        app(GuideGenerator::class)->sync();
        $d = GuideDraft::where('key', 'kas')->first();
        try {
            $this->drafts()->discard($d, $this->admin(), 'no');
            $this->fail('alasan pendek');
        } catch (ValidationException) {
        }
        $this->drafts()->discard($d, $this->admin(), 'Isi manual lebih tepat untuk koperasi');
        $this->assertSame('discarded', $d->fresh()->status);
        $this->assertSame('Isi manual lebih tepat untuk koperasi', ActivityLog::where('event', 'guide.draft_discarded')->value('reason'));
        app(GuideGenerator::class)->sync();                                   // draf baru boleh dibuat lagi setelah dibuang
        $this->assertSame(1, GuideDraft::where('key', 'kas')->where('status', 'pending')->count());
    }

    public function test_versions_link_to_sync_job_and_new_guide_draft(): void
    {
        $this->edit('promo', ['title' => 'Promo lama']);
        ['job' => $job] = app(GuideSyncRecorder::class)->run('menu_changed');
        $this->assertSame($job->id, GuideVersion::latest('id')->value('sync_job_id'));
        $this->assertSame(1, GuideVersion::where('sync_job_id', $job->id)->count());
        $job->delete();                                                       // proses dihapus → tautan versi dikosongkan
        $this->assertNull(GuideVersion::latest('id')->value('sync_job_id'));

        $new = $this->drafts()->stage('panduan-baru', ['menu' => null, 'audience' => 'petugas', 'title' => 'Panduan baru', 'summary' => 'Ringkasan panduan baru', 'steps' => [['text' => 'Langkah']], 'tips' => [], 'related' => []], ['title']);
        $this->assertNull($new->base_hash);
        $entry = $this->drafts()->apply($new, $this->admin());
        $this->assertSame('Panduan baru', $entry->title);
        $this->assertSame(GuideEntry::max('position'), $entry->position);
    }
}
