<?php

namespace Tests\Feature\Panduan;

use App\Models\ActivityLog;
use App\Models\GuideChange;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API kembalikan bagian panduan ke hasil otomatis: satu bagian, semua bagian (satu versi), bentrok, akses. */
class GuideRevertApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private GuideEntry $kas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin, 'web');
        $this->kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($this->kas, 'title', 'Judul kas versi admin', $this->admin);
        app(GuideService::class)->setOverride($this->kas, 'tips', ['Tips versi admin'], $this->admin);
    }

    public function test_revert_one_section(): void
    {
        $this->deleteJson('/panduan/kelola/kas/title', ['catatan' => 'Kembali'])->assertOk()
            ->assertJsonPath('reverted', true)->assertJsonPath('data.effective.title', $this->kas->title)->assertJsonMissingPath('data.overrides.title');
        $this->deleteJson('/panduan/kelola/kas/title')->assertOk()->assertJsonPath('reverted', false);
        $this->assertSame('Kembali', ActivityLog::where('event', 'guide.reverted')->value('reason'));
    }

    public function test_revert_with_fingerprint_conflict(): void
    {
        $fp = $this->getJson('/panduan/kelola/kas')->assertOk()->json('data.fingerprints.title');
        app(GuideService::class)->setOverride($this->kas, 'title', 'Judul diubah admin lain', $this->admin);
        $this->deleteJson('/panduan/kelola/kas/title', ['sidik' => $fp])->assertStatus(409);
        $this->assertSame('Judul diubah admin lain', GuideOverride::where('field', 'title')->value('value'));
        $fresh = $this->getJson('/panduan/kelola/kas')->json('data.fingerprints.title');
        $this->deleteJson('/panduan/kelola/kas/title', ['sidik' => $fresh])->assertOk()->assertJsonPath('reverted', true);
        $this->deleteJson('/panduan/kelola/kas/title', ['sidik' => 'pendek'])->assertUnprocessable();
    }

    public function test_revert_all_sections_in_one_version(): void
    {
        $versions = GuideVersion::count();
        $this->deleteJson('/panduan/kelola/kas', ['catatan' => 'Standar koperasi'])->assertOk()
            ->assertJsonPath('reverted', ['title', 'tips'])->assertJsonPath('data.overrides', []);
        $this->assertSame($versions + 1, GuideVersion::count());
        $this->assertSame(2, GuideChange::where('action', 'revert')->where('note', 'Standar koperasi')->count());
        $this->deleteJson('/panduan/kelola/kas')->assertOk()->assertJsonPath('reverted', []);
        $this->deleteJson('/panduan/kelola/tidak-ada')->assertNotFound();
    }

    public function test_managers_only(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->deleteJson('/panduan/kelola/kas/title')->assertForbidden();
        $this->deleteJson('/panduan/kelola/kas')->assertForbidden();
        $this->assertSame(2, GuideOverride::count());
    }
}
