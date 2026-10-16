<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Editor;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/** Tabel override panduan: kolom, catatan, sidik jari isi otomatis dasar suntingan, deteksi isi otomatis berubah. */
class GuideOverrideTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    public function test_schema(): void
    {
        foreach (['id', 'guide_entry_id', 'field', 'value', 'base_hash', 'note', 'updated_by', 'created_at', 'updated_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('guide_overrides', $c), $c);
        }
    }

    public function test_override_stores_note_and_base_hash(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        $o = app(GuideService::class)->setOverride($kas, 'summary', 'Ringkasan kas versi koperasi kami', null, '  Sesuaikan istilah  ');
        $this->assertSame('Sesuaikan istilah', $o->note);
        $this->assertSame(GuideOverride::hashOf($kas->summary), $o->base_hash);
        $this->assertFalse($o->autoChanged($kas->fresh()));
    }

    public function test_auto_change_after_override_is_detected_and_shown(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'summary', 'Ringkasan kas versi koperasi kami', null, 'Catatan admin');
        app(GuideService::class)->setOverride($kas, 'tips', ['Tips admin'], null);
        $kas->update(['summary' => 'Ringkasan otomatis baru dari penyusun']);                     // penyusun memperbarui isi otomatis ringkasan
        $kas = $kas->fresh();
        $this->assertTrue(GuideOverride::where('field', 'summary')->first()->autoChanged($kas));
        $this->assertFalse(GuideOverride::where('field', 'tips')->first()->autoChanged($kas));
        Livewire::withQueryParams(['panduan' => 'kas'])->test(Editor::class)
            ->assertSeeHtml('data-editor-auto-changed="summary"')->assertDontSeeHtml('data-editor-auto-changed="tips"')
            ->assertSeeHtml('data-editor-override-note="summary"');
        $this->getJson('/panduan/kelola/kas')->assertOk()->assertJsonPath('data.overrides.summary.auto_changed', true)
            ->assertJsonPath('data.overrides.summary.note', 'Catatan admin');
    }

    public function test_legacy_override_without_base_is_unknown(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        $o = GuideOverride::create(['guide_entry_id' => $kas->id, 'field' => 'title', 'value' => 'Judul lama', 'updated_by' => null]);
        $this->assertNull($o->autoChanged($kas));
    }
}
