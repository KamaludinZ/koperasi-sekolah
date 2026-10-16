<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideChange;
use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Models\User;
use App\Services\GuideDraftService;
use App\Services\GuideGenerator;
use App\Services\GuideService;
use App\Services\GuideSyncRecorder;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Tabel riwayat perubahan panduan per bagian (guide_changes) dari semua jalur perubahan. */
class GuideChangesTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
    }

    private function kas(): GuideEntry
    {
        return GuideEntry::where('key', 'kas')->first();
    }

    public function test_schema_and_created_entries_from_seeder(): void
    {
        foreach (['guide_entry_id', 'guide_version_id', 'field', 'action', 'before', 'after', 'note', 'changed_by', 'sync_job_id', 'created_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('guide_changes', $c), $c);
        }
        $this->assertSame(GuideEntry::count(), GuideChange::where('action', 'created')->count());
        $this->assertNotNull(GuideChange::where('action', 'created')->first()->guide_version_id);
    }

    public function test_override_and_revert_store_full_values(): void
    {
        $long = str_repeat('Ringkasan panjang. ', 50);
        app(GuideService::class)->setOverride($this->kas(), 'summary', $long, $this->admin, 'Perjelas');
        app(GuideService::class)->clearOverride($this->kas(), 'summary', $this->admin);
        [$o, $r] = GuideChange::whereIn('action', ['override', 'revert'])->orderBy('id')->get();
        $this->assertSame(['override', 'summary', $this->kas()->summary, trim($long), 'Perjelas', $this->admin->id], [$o->action, $o->field, $o->before, $o->after, $o->note, $o->changed_by]);
        $this->assertGreaterThan(500, mb_strlen($o->after));                                    // utuh, tidak dipotong seperti audit
        $this->assertSame([trim($long), $this->kas()->summary], [$r->before, $r->after]);
        $this->assertNotNull($o->guide_version_id);

        app(GuideService::class)->setOverride($this->kas(), 'title', $this->kas()->title, $this->admin); // isi sama → tanpa versi & tanpa riwayat
        $this->assertSame(0, GuideChange::where('action', 'override')->where('field', 'title')->count());
    }

    public function test_generate_logs_changed_fields_with_sync_job(): void
    {
        $steps = $this->kas()->steps;
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama', 'steps' => json_encode(array_slice($steps, 1))]);
        ['job' => $job] = app(GuideSyncRecorder::class)->run('manual');
        $rows = GuideChange::where('action', 'generate')->where('guide_entry_id', $this->kas()->id)->orderBy('field')->get();
        $this->assertSame(['steps', 'title'], $rows->pluck('field')->all());
        $this->assertSame(['Judul lama', $this->kas()->title], [$rows[1]->before, $rows[1]->after]);
        $this->assertSame($job->id, $rows[0]->sync_job_id);
        $this->assertCount(count($steps) - 1, $rows[0]->before);
    }

    public function test_stale_review_and_draft_logged(): void
    {
        app(GuideService::class)->markStale($this->kas(), 'Prosedur berubah', $this->admin);
        app(GuideService::class)->markReviewed($this->kas(), $this->admin, 'Sudah dicek');
        $this->assertSame(['stale', 'review'], GuideChange::whereIn('action', ['stale', 'review'])->orderBy('id')->pluck('action')->all());
        $this->assertSame('Prosedur berubah', GuideChange::where('action', 'review')->value('before'));

        GuideEntry::where('key', 'barang')->update(['source' => 'manual', 'title' => 'Barang manual']);
        app(GuideGenerator::class)->sync();
        app(GuideDraftService::class)->apply(GuideDraft::first(), $this->admin, 'Pakai otomatis');
        $d = GuideChange::where('action', 'draft')->first();
        $this->assertSame(['title', 'Barang manual', 'Pakai otomatis'], [$d->field, $d->before, $d->note]);
    }

    public function test_lines_helper(): void
    {
        $this->assertSame(['a', 'b'], GuideChange::lines([['text' => 'a', 'route' => 'kas'], ['text' => 'b']]));
        $this->assertSame(['Kas (kas)'], GuideChange::lines(['kas' => 'Kas']));
        $this->assertSame(['x'], GuideChange::lines('x'));
        $this->assertSame([], GuideChange::lines(null));
    }
}
