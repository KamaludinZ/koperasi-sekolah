<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideService;
use App\Services\GuideVersionRecorder;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Service pencatatan versi saat penyimpanan panduan + jaring pengaman observer. */
class GuideVersionRecorderTest extends TestCase
{
    use RefreshDatabase;

    private GuideEntry $kas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->kas = GuideEntry::where('key', 'kas')->first();
    }

    private function versions(): int
    {
        return GuideVersion::where('guide_entry_id', $this->kas->id)->count();
    }

    public function test_seeded_guides_have_exactly_one_version(): void
    {
        $this->assertSame(GuideEntry::count(), GuideVersion::count());                       // observer tidak menggandakan versi seeder
    }

    public function test_direct_model_save_is_versioned_once(): void
    {
        $this->kas->update(['title' => 'Judul diubah langsung lewat model']);
        $this->assertSame(2, $this->versions());
        $v = GuideVersion::where('guide_entry_id', $this->kas->id)->orderByDesc('version')->first();
        $this->assertSame(['generate', 'Isi panduan disimpan', 'Judul diubah langsung lewat model'], [$v->change_type, $v->change_note, $v->snapshot['title']]);

        $this->kas->update(['is_stale' => true, 'stale_reason' => 'Cek']);                       // bukan isi → tanpa versi
        $this->assertSame(2, $this->versions());
    }

    public function test_service_saves_keep_their_own_annotated_version(): void
    {
        app(GuideService::class)->setOverride($this->kas, 'summary', 'Ringkasan dari service dengan catatan', User::first(), 'Catatan service');
        $this->assertSame(2, $this->versions());                                                  // bukan 3: cadangan observer dilewati
        $this->assertSame('Catatan service', GuideVersion::where('guide_entry_id', $this->kas->id)->orderByDesc('version')->value('change_note'));
    }

    public function test_direct_override_create_and_delete_are_versioned(): void
    {
        $o = GuideOverride::create(['guide_entry_id' => $this->kas->id, 'field' => 'title', 'value' => 'Judul override langsung']);
        $this->assertSame(2, $this->versions());
        $o->delete();
        $this->assertSame(3, $this->versions());
        $this->assertSame('Suntingan panduan dihapus', GuideVersion::where('guide_entry_id', $this->kas->id)->orderByDesc('version')->value('change_note'));
    }

    public function test_inside_transaction_version_is_written_after_commit(): void
    {
        DB::transaction(function () {
            $this->kas->update(['summary' => 'Ringkasan baru di dalam transaksi panjang']);
            $this->assertSame(1, $this->versions());                                              // belum: menunggu commit
        });
        $this->assertSame(2, $this->versions());
        try {
            DB::transaction(function () {
                $this->kas->update(['summary' => 'Ringkasan yang dibatalkan']);
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, $this->versions());                                                  // transaksi batal → tanpa versi
    }

    public function test_recorder_numbers_sequentially_and_skips_duplicates(): void
    {
        $r = app(GuideVersionRecorder::class);
        $this->assertNull($r->record($this->kas, 'generate', 'Sama', null));
        DB::table('guide_entries')->where('id', $this->kas->id)->update(['title' => 'Judul lewat query builder']); // tanpa event model
        $v = $r->record($this->kas->fresh(), 'generate', 'Dicatat manual', null);
        $this->assertSame(2, $v->version);
    }
}
