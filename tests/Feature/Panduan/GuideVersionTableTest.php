<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideOverrideService;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Tabel riwayat versi panduan: sidik jari isi kanonis, sumber pemulihan, indeks. */
class GuideVersionTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    public function test_columns_and_hash_on_every_version(): void
    {
        foreach (['guide_entry_id', 'version', 'snapshot', 'content_hash', 'change_type', 'change_note', 'changed_by', 'sync_job_id', 'restored_from_version', 'created_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('guide_versions', $c), $c);
        }
        $this->assertSame(0, GuideVersion::whereNull('content_hash')->count());
        $v = GuideVersion::first();
        $this->assertSame(GuideVersion::hashOf($v->snapshot), $v->content_hash);
    }

    public function test_hash_is_canonical_for_key_order_but_not_list_order(): void
    {
        $this->assertSame(GuideVersion::hashOf(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]), GuideVersion::hashOf(['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
        $this->assertNotSame(GuideVersion::hashOf(['steps' => ['a', 'b']]), GuideVersion::hashOf(['steps' => ['b', 'a']]));
    }

    public function test_restore_records_source_version_and_duplicates_are_skipped(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        $admin = User::where('email', 'admin@koperasi.test')->first();
        app(GuideService::class)->setOverride($kas, 'title', 'Judul kas versi kami', $admin);
        $count = GuideVersion::count();
        $this->assertNull(app(GuideService::class)->recordVersion($kas->fresh(), 'override', 'Ulang', $admin)); // isi sama → tidak dicatat
        $this->assertSame($count, GuideVersion::count());

        app(GuideOverrideService::class)->restore($kas->fresh(), GuideVersion::where('guide_entry_id', $kas->id)->where('version', 1)->first(), $admin, 'Kembali ke awal');
        $v = GuideVersion::where('guide_entry_id', $kas->id)->orderByDesc('version')->first();
        $this->assertSame(['restore', 1], [$v->change_type, $v->restored_from_version]);
        $this->assertSame(GuideVersion::where('guide_entry_id', $kas->id)->where('version', 1)->value('content_hash'), $v->content_hash);
    }
}
