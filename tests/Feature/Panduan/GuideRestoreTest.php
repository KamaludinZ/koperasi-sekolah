<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Versions;
use App\Models\ActivityLog;
use App\Models\GuideChange;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideOverrideService;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Pulihkan versi panduan: dialog konfirmasi (pratinjau, alasan wajib) & service pemulihan. */
class GuideRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private GuideEntry $kas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($this->kas, 'title', 'Judul kas versi kami', $this->admin);          // v2
        app(GuideService::class)->setOverride($this->kas, 'tips', ['Tips versi kami'], $this->admin);              // v3
    }

    public function test_dialog_previews_changes_and_requires_reason(): void
    {
        $this->actingAs($this->admin, 'web');
        $c = Livewire::withQueryParams(['panduan' => 'kas'])->test(Versions::class)
            ->assertSeeHtml('data-restore-start="1"')->assertDontSeeHtml('data-restore-start="3"')                       // v3 aktif
            ->call('startRestore', 1)->assertSeeHtml('data-restore-dialog="1"')
            ->assertSeeHtml('data-restore-field="title"')->assertSeeHtml('data-restore-field="tips"')->assertDontSeeHtml('data-restore-field="steps"')
            ->call('confirmRestore')->assertHasErrors('reason');
        $this->assertSame(2, GuideOverride::count());
        $c->call('cancelRestore')->assertDontSeeHtml('data-restore-dialog=');
        $c->call('startRestore', 1)->set('reason', 'Kembali ke versi standar koperasi')->call('confirmRestore')->assertHasNoErrors()
            ->assertDontSeeHtml('data-restore-dialog=')->assertSeeText('versi aktif v4');
        $this->assertSame(0, GuideOverride::count());                                                                     // v1 = isi otomatis → suntingan dilepas
        $v4 = GuideVersion::where('guide_entry_id', $this->kas->id)->where('version', 4)->first();
        $this->assertSame('restore', $v4->change_type);
        $this->assertStringContainsString('Dipulihkan dari versi 1', $v4->change_note);
        $this->assertSame('Kembali ke versi standar koperasi', ActivityLog::where('event', 'guide.restored')->value('reason'));
        $this->assertSame(2, GuideChange::where('action', 'restore')->count());
    }

    public function test_restore_to_version_with_override_recreates_it(): void
    {
        app(GuideOverrideService::class)->revertMany($this->kas, null, $this->admin, 'Reset');                           // v4 = otomatis
        $v2 = GuideVersion::where('guide_entry_id', $this->kas->id)->where('version', 2)->first();
        $r = app(GuideOverrideService::class)->restore($this->kas, $v2, $this->admin, 'Pakai judul lokal lagi');
        $this->assertSame(['title'], $r['changed']);
        $this->assertSame('Judul kas versi kami', app(GuideService::class)->effective($this->kas->fresh())['title']);
        $this->assertStringStartsWith('Dipulihkan dari versi 2', GuideOverride::where('field', 'title')->value('note'));
        $this->assertSame(['version' => null, 'changed' => []], app(GuideOverrideService::class)->restore($this->kas->fresh(), $v2, $this->admin, 'Sudah sama isinya'));
    }

    public function test_validation_and_foreign_version(): void
    {
        $other = GuideVersion::where('guide_entry_id', GuideEntry::where('key', 'barang')->value('id'))->first();
        $this->expectException(ValidationException::class);
        app(GuideOverrideService::class)->restore($this->kas, $other, $this->admin, 'Alasan cukup panjang');
    }

    public function test_only_managers_can_restore(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        Livewire::withQueryParams(['panduan' => 'kas'])->test(Versions::class)->assertDontSeeHtml('data-restore-start=')
            ->call('startRestore', 1)->assertForbidden();
    }
}
