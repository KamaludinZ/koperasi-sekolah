<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Penanda bagian panduan yang disunting manual di Pusat Panduan. */
class GuideEditedMarkersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'summary', 'Ringkasan kas versi koperasi kami', $this->admin);
        app(GuideService::class)->setOverride($kas, 'tips', ['Tips versi koperasi'], $this->admin);
    }

    public function test_managers_see_per_section_markers_with_editor(): void
    {
        $this->actingAs($this->admin, 'web');
        Livewire::test(Index::class)->assertSeeHtml('data-guide-edited-badge="kas"')->assertDontSeeHtml('data-guide-edited-badge="barang"')
            ->call('open', 'kas')
            ->assertSeeHtml('data-guide-edited="summary"')->assertSeeHtml('data-guide-edited="tips"')
            ->assertDontSeeHtml('data-guide-edited="title"')->assertDontSeeHtml('data-guide-edited="steps"')
            ->assertSeeText('Disunting oleh '.$this->admin->name)
            ->assertSeeHtml('data-guide-edited-summary')->assertSeeText('2 bagian disunting manual')
            ->assertDontSeeHtml('data-guide-edited-note')
            ->call('open', 'barang')->assertDontSeeHtml('data-guide-edited=');
    }

    public function test_readers_see_general_note_only(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        Livewire::test(Index::class)->assertDontSeeHtml('data-guide-edited-badge=')
            ->call('open', 'kas')->assertSeeHtml('data-guide-edited-note')->assertSeeText('Ringkasan kas versi koperasi kami')
            ->assertDontSeeHtml('data-guide-edited="summary"')->assertDontSeeText('Disunting oleh')
            ->call('open', 'kasir')->assertDontSeeHtml('data-guide-edited-note');
    }

    public function test_editor_name_is_escaped(): void
    {
        $this->admin->update(['name' => '<b>Admin</b>']);
        $this->actingAs($this->admin->fresh(), 'web');
        Livewire::test(Index::class)->call('open', 'kas')->assertSeeHtml('oleh &lt;b&gt;Admin&lt;/b&gt;')->assertDontSeeHtml('oleh <b>Admin</b>');
    }

    public function test_manager_can_revert_section_from_guide_center(): void
    {
        $this->actingAs($this->admin, 'web');
        Livewire::test(Index::class)->call('open', 'kas')->assertSeeHtml('data-guide-revert="summary"')
            ->call('revertSection', 'summary')->assertDontSeeHtml('data-guide-edited="summary"')->assertSeeHtml('data-guide-edited="tips"')
            ->assertDontSeeText('Ringkasan kas versi koperasi kami');
        $this->assertSame(['tips'], GuideOverride::pluck('field')->all());
        $this->assertSame('Dikembalikan dari Pusat Panduan', ActivityLog::where('event', 'guide.reverted')->value('reason'));

        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        Livewire::test(Index::class)->call('open', 'kas')->assertDontSeeHtml('data-guide-revert=')->call('revertSection', 'tips')->assertForbidden();
        $this->assertSame(['tips'], GuideOverride::pluck('field')->all());
    }
}
