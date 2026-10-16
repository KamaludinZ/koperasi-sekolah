<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuideOverrideVersionTest extends TestCase
{
    use RefreshDatabase;

    private GuideService $svc;

    private User $admin;

    private GuideEntry $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->svc = app(GuideService::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = GuideEntry::where('key', 'kasir')->first();
    }

    public function test_seeding_records_one_generate_version_per_guide_and_not_again_when_unchanged(): void
    {
        $this->assertSame(GuideEntry::count(), GuideVersion::where('change_type', 'generate')->count());
        $this->seed(GuideSeeder::class);
        $this->assertSame(GuideEntry::count(), GuideVersion::count()); // isi sama → tak ada versi baru
        $this->assertSame(1, $this->kasir->versions()->first()->version);
    }

    public function test_override_replaces_only_that_part_and_is_versioned(): void
    {
        $this->svc->setOverride($this->kasir, 'title', '  Judul   kasir versi koperasi ', $this->admin, 'disesuaikan istilah sekolah');
        $g = $this->svc->effective($this->kasir->fresh());
        $this->assertSame('Judul kasir versi koperasi', $g['title']);
        $this->assertSame($this->kasir->summary, $g['summary']); // bagian lain tetap otomatis
        $this->assertSame(['title'], $g['overridden']);

        $v = $this->kasir->versions()->first();
        $this->assertSame([2, 'override', 'disesuaikan istilah sekolah', $this->admin->id], [$v->version, $v->change_type, $v->change_note, $v->changed_by]);
        $this->assertSame('Judul kasir versi koperasi', $v->snapshot['title']);
        $this->assertNotSame('Judul kasir versi koperasi', GuideEntry::find($this->kasir->id)->title); // isi otomatis tidak diubah
    }

    public function test_override_survives_regeneration_and_revert_restores_auto(): void
    {
        $this->svc->setOverride($this->kasir, 'summary', 'Ringkasan suntingan admin yang lebih panjang.', $this->admin);
        $this->seed(GuideSeeder::class); // menyusun ulang isi otomatis
        $this->assertSame('Ringkasan suntingan admin yang lebih panjang.', $this->svc->effective($this->kasir->fresh())['summary']);

        $this->assertTrue($this->svc->clearOverride($this->kasir, 'summary', $this->admin));
        $this->assertSame($this->kasir->summary, $this->svc->effective($this->kasir->fresh())['summary']);
        $this->assertSame('revert', $this->kasir->versions()->first()->change_type);
        $this->assertFalse($this->svc->clearOverride($this->kasir, 'summary', $this->admin)); // tak ada lagi
        $this->assertSame(3, $this->kasir->versions()->count());
    }

    public function test_steps_and_tips_are_cleaned(): void
    {
        $this->svc->setOverride($this->kasir, 'steps', [['text' => ' Buka shift dulu ', 'route' => 'kas'], '', ['text' => 'Langkah tanpa rute', 'route' => 'rute.tidak.ada'], 'Langkah teks biasa'], $this->admin);
        $this->assertSame([['text' => 'Buka shift dulu', 'route' => 'kas'], ['text' => 'Langkah tanpa rute'], ['text' => 'Langkah teks biasa']],
            $this->svc->effective($this->kasir->fresh())['steps']);
        $this->svc->setOverride($this->kasir, 'tips', ['  tip satu ', '', 'tip dua'], $this->admin);
        $this->assertSame(['tip satu', 'tip dua'], $this->svc->effective($this->kasir->fresh())['tips']);
    }

    public function test_invalid_overrides_are_rejected(): void
    {
        foreach ([['title', ''], ['title', str_repeat('x', 151)], ['summary', 'pendek'], ['steps', []], ['steps', 'bukan daftar'],
            ['steps', array_fill(0, 21, 'langkah')], ['tips', array_fill(0, 11, 'tip')], ['related', ['x' => 'y']], ['menu_key', 'kasir']] as [$f, $v]) {
            try {
                $this->svc->setOverride($this->kasir, $f, $v, $this->admin);
                $this->fail("harus ditolak: {$f}");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, GuideOverride::count());
        $this->assertSame(1, $this->kasir->versions()->count());
    }

    public function test_same_content_twice_is_one_version_and_db_constraints(): void
    {
        $this->svc->setOverride($this->kasir, 'title', 'Judul baru', $this->admin);
        $this->svc->setOverride($this->kasir, 'title', 'Judul baru', $this->admin);
        $this->assertSame(2, $this->kasir->versions()->count());
        $this->assertSame(1, GuideOverride::count());

        $this->expectException(QueryException::class);
        DB::table('guide_versions')->insert(['guide_entry_id' => $this->kasir->id, 'version' => 2, 'snapshot' => '{}', 'change_type' => 'override', 'created_at' => now()]);
    }

    public function test_deleting_entry_cascades_and_deleting_editor_keeps_history(): void
    {
        $editor = User::factory()->admin()->create();
        $this->svc->setOverride($this->kasir, 'title', 'Judul editor sementara', $editor);
        $editor->roles()->detach();
        $editor->delete();
        $this->assertNull(GuideOverride::first()->updated_by);
        $this->assertNull($this->kasir->versions()->first()->changed_by);

        $this->kasir->delete();
        $this->assertSame(0, GuideOverride::count());
        $this->assertSame(0, GuideVersion::where('guide_entry_id', $this->kasir->id)->count());
    }
}
