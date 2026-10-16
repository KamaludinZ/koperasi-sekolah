<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Editor;
use App\Livewire\Panduan\Index;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Halaman editor panduan: sunting per bagian di lapisan override, kembalikan ke otomatis, validasi, akses. */
class GuideEditorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    private function editor(string $key = 'kas')
    {
        return Livewire::withQueryParams(['panduan' => $key])->test(Editor::class);
    }

    public function test_page_loads_effective_content_and_links(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        $this->get('/panduan/sunting?panduan=kas')->assertOk()->assertSeeText('Sunting Panduan')->assertSeeText($kas->title);
        $this->editor()->assertSet('title', $kas->title)->assertCount('steps', count($kas->steps))
            ->assertSeeHtml('data-editor-field="steps"')->assertDontSeeHtml('data-editor-overridden=');
        $this->editor('tidak-ada')->assertSet('key', GuideEntry::for('petugas')->value('key'));
        Livewire::test(Index::class)->call('open', 'kas')->assertSeeHtml('data-guide-edit-link');
    }

    public function test_save_section_as_override_survives_regeneration(): void
    {
        $c = $this->editor()->set('title', 'Kas harian versi koperasi kami')->assertSeeHtml('data-editor-dirty="title"')
            ->set('note', 'Sesuaikan nama')->call('save', 'title')->assertHasNoErrors()
            ->assertSeeHtml('data-editor-saved')->assertSeeHtml('data-editor-overridden="title"')->assertDontSeeHtml('data-editor-dirty="title"');
        $o = GuideOverride::where('field', 'title')->first();
        $this->assertSame('Kas harian versi koperasi kami', $o->value);
        $this->assertSame('Sesuaikan nama', ActivityLog::where('event', 'guide.overridden')->value('reason'));

        app(GuideGenerator::class)->sync();                                                  // disusun ulang → suntingan tetap
        $this->assertSame('Kas harian versi koperasi kami', app(GuideService::class)->all('petugas')['kas']['title']);
        $c->assertSeeHtml('data-editor-auto="title"');
    }

    public function test_steps_editing_add_move_remove_and_links(): void
    {
        $c = $this->editor();
        $first = $c->get('steps')[0];
        $n = count($c->get('steps'));
        $c->call('addStep')->set("steps.{$n}.text", 'Langkah tambahan dari admin')
            ->call('moveStep', 0, 1)->assertSet('steps.1', $first)
            ->call('removeStep', 0)
            ->call('save', 'steps')->assertHasNoErrors();
        $saved = GuideOverride::where('field', 'steps')->first()->value;
        $this->assertSame($first['text'], $saved[0]['text']);
        $this->assertSame('Langkah tambahan dari admin', end($saved)['text']);
        $this->assertArrayNotHasKey('route', end($saved));
    }

    public function test_validation_errors_are_shown_per_section(): void
    {
        $this->editor()->set('summary', 'pendek')->call('save', 'summary')->assertHasErrors('summary')->assertSeeHtml('data-editor-error="summary"');
        $this->editor()->set('steps', [])->call('save', 'steps')->assertHasErrors('steps');
        $this->editor()->set('title', '')->call('save', 'title')->assertHasErrors('title');
        $this->assertSame(0, GuideOverride::count());
    }

    public function test_revert_and_discard(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'tips', ['Tips versi admin untuk kas'], null);
        $this->editor()->assertSet('tips', ['Tips versi admin untuk kas'])->assertSeeHtml('data-editor-revert="tips"')
            ->call('revert', 'tips')->assertSet('tips', $kas->tips)->assertDontSeeHtml('data-editor-overridden="tips"');
        $this->assertSame(0, GuideOverride::count());
        $this->assertSame(1, ActivityLog::where('event', 'guide.reverted')->count());

        $this->editor()->set('title', 'Belum disimpan')->call('discard', 'title')->assertSet('title', $kas->title);
    }

    public function test_switching_guide_reloads_form(): void
    {
        $this->editor()->set('title', 'Perubahan yang tidak disimpan')->set('key', 'barang')
            ->assertSet('title', GuideEntry::where('key', 'barang')->value('title'));
    }

    public function test_managers_only(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->get('/panduan/sunting')->assertForbidden();
        Livewire::test(Index::class)->call('open', 'transaksi')->assertDontSeeHtml('data-guide-edit-link');
    }

    public function test_counters_preview_and_unsaved_marker(): void
    {
        $this->editor()->assertSeeHtml('data-dirty-count="0"')->assertSeeHtml('data-editor-count="title"')->assertSeeHtml('data-editor-preview')
            ->set('title', 'Judul baru')->assertSeeText('10/150')->assertSeeHtml('data-dirty-count="1"')->assertSeeHtml('data-editor-unsaved')
            ->assertSeeText('1 bagian belum disimpan: judul')
            ->set('steps.0.text', 'Langkah pertama yang diubah')->assertSeeHtml('data-dirty-count="2"')
            ->assertSeeText('Langkah pertama yang diubah');                                      // pratinjau pembaca ikut berubah
    }

    public function test_save_all_is_atomic_and_keeps_input_on_error(): void
    {
        $versions = GuideVersion::count();
        $this->editor()->set('title', 'Judul kas versi koperasi')->set('summary', 'pendek')->set('tips', ['Tips baru dari admin'])
            ->call('saveAll')->assertHasErrors('summary')
            ->assertSet('summary', 'pendek')->assertSet('title', 'Judul kas versi koperasi')->assertSeeHtml('data-dirty-count="3"');
        $this->assertSame(0, GuideOverride::count());                                        // tidak ada yang tersimpan sebagian

        $this->editor()->set('title', 'Judul kas versi koperasi')->set('tips', ['Tips baru dari admin'])->set('note', 'Simpan sekaligus')
            ->call('saveAll')->assertHasNoErrors()->assertSeeText('Judul, Tips disimpan sebagai suntingan.')->assertSeeHtml('data-dirty-count="0"');
        $this->assertEqualsCanonicalizing(['title', 'tips'], GuideOverride::pluck('field')->all());
        $this->assertSame($versions + 1, GuideVersion::count());                // satu versi untuk dua bagian
        $this->assertSame(2, ActivityLog::where('event', 'guide.overridden')->where('reason', 'Simpan sekaligus')->count());
    }

    public function test_save_rejected_when_section_changed_by_someone_else(): void
    {
        $c = $this->editor()->set('title', 'Judul versi saya');
        app(GuideService::class)->setOverride(GuideEntry::where('key', 'kas')->first(), 'title', 'Judul versi admin lain', null); // orang lain menyimpan dulu
        $c->call('save', 'title')->assertHasErrors('title')->assertSeeText('sudah diubah orang lain');
        $this->assertSame('Judul versi admin lain', GuideOverride::where('field', 'title')->value('value'));
    }

    public function test_revert_all_restores_every_section(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'title', 'Judul versi admin', null);
        app(GuideService::class)->setOverride($kas, 'steps', [['text' => 'Langkah versi admin']], null);
        $this->editor()->assertSeeHtml('data-editor-overrides-bar')->assertSeeText('2 bagian memakai suntingan manual')
            ->set('note', 'Kembali ke standar')->call('revertAll')
            ->assertSet('title', $kas->title)->assertDontSeeHtml('data-editor-overrides-bar')->assertSeeText('dikembalikan ke hasil otomatis');
        $this->assertSame(0, GuideOverride::count());
        $this->assertSame(2, ActivityLog::where('event', 'guide.reverted')->where('reason', 'Kembali ke standar')->count());
        $this->editor('barang')->assertDontSeeHtml('data-editor-revert-all')->call('revertAll')->assertHasNoErrors();
    }

    public function test_save_requires_confirmation_showing_before_and_after(): void
    {
        $kas = GuideEntry::where('key', 'kas')->first();
        $c = $this->editor()->set('title', 'Judul kas versi koperasi')->call('confirmSave', 'title')
            ->assertSet('confirming', 'title')->assertSeeHtml('data-editor-confirm="title"')->assertSeeHtml('data-confirm-diff="title"')
            ->assertSeeText($kas->title)->assertSeeText('Judul kas versi koperasi');
        $this->assertSame(0, GuideOverride::count());                                       // belum tersimpan sebelum dikonfirmasi
        $c->call('cancelSave')->assertSet('confirming', null)->assertSet('title', 'Judul kas versi koperasi');
        $this->assertSame(0, GuideOverride::count());
        $c->call('confirmSave', 'title')->call('confirmed')->assertSet('confirming', null)->assertSeeHtml('data-editor-overridden="title"');
        $this->assertSame('Judul kas versi koperasi', GuideOverride::where('field', 'title')->value('value'));

        $this->editor()->call('confirmSave', 'summary')->assertSet('confirming', null);       // tidak berubah → tidak perlu konfirmasi
        $this->editor()->set('summary', 'Ringkasan baru yang cukup panjang')->set('tips', ['Tips baru'])
            ->call('confirmSave', 'all')->assertSeeHtml('data-confirm-diff="summary"')->assertSeeHtml('data-confirm-diff="tips"')
            ->call('confirmed')->assertHasNoErrors();
        $this->assertSame(3, GuideOverride::count());
    }

    public function test_change_history_from_audit_trail(): void
    {
        $this->editor()->assertSeeHtml('data-change="created"')->assertDontSeeHtml('data-change="override"');   // dibuat oleh seeder
        $this->editor()->set('title', 'Judul kas versi koperasi')->set('note', 'Sesuaikan istilah')->call('save', 'title');
        $this->editor()->call('revert', 'title');
        $c = $this->editor()->assertSeeHtml('data-change="override"')->assertSeeHtml('data-change="revert"')->assertSeeHtml('data-change-field="title"')
            ->assertSeeText('Catatan: Sesuaikan istilah')->assertSeeText('Judul kas versi koperasi');
        $this->assertStringContainsString(User::where('email', 'admin@koperasi.test')->value('name'), $c->html());
        $this->editor('barang')->assertDontSeeHtml('data-change="override"');
    }
}
