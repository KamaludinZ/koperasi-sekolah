<?php

namespace Tests\Feature\Panduan;

use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditIntegrity;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideAdminEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    public function test_show_returns_auto_effective_overrides_and_versions(): void
    {
        $this->getJson('/panduan/kelola/kasir')->assertOk()
            ->assertJsonPath('data.key', 'kasir')->assertJsonPath('data.source', 'auto')
            ->assertJsonPath('data.auto.title', 'Melayani penjualan di layar Kasir')
            ->assertJsonPath('data.effective.title', 'Melayani penjualan di layar Kasir')
            ->assertJsonPath('data.overrides', [])
            ->assertJsonPath('data.versions.0.version', 1)->assertJsonPath('data.versions.0.type', 'generate');
        $this->getJson('/panduan/kelola/tidak-ada')->assertNotFound();
    }

    public function test_edit_a_part_records_version_and_hmac_audit(): void
    {
        $res = $this->putJson('/panduan/kelola/kasir/title', ['value' => 'Cara melayani pembeli di Kasir', 'catatan' => 'istilah lebih akrab'])->assertOk();
        $res->assertJsonPath('data.effective.title', 'Cara melayani pembeli di Kasir')
            ->assertJsonPath('data.auto.title', 'Melayani penjualan di layar Kasir')       // isi otomatis tetap
            ->assertJsonPath('data.overrides.title.updated_by', $this->admin->name)
            ->assertJsonPath('data.versions.0.version', 2)->assertJsonPath('data.versions.0.note', 'istilah lebih akrab');

        $log = ActivityLog::where('event', 'guide.overridden')->sole();
        $this->assertSame(['panduan', 'guide', $this->admin->id, 'istilah lebih akrab'], [$log->category, $log->subject_type, $log->actor_id, $log->reason]);
        $this->assertSame(['title' => 'Melayani penjualan di layar Kasir'], $log->old_values);
        $this->assertSame(['title' => 'Cara melayani pembeli di Kasir'], $log->new_values);
        $this->assertSame(2, $log->properties['version']);
        $this->assertTrue(AuditIntegrity::isValid($log));

        // pengguna lain melihat isi suntingan
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first());
        $this->getJson('/panduan/data/kasir')->assertJsonPath('data.title', 'Cara melayani pembeli di Kasir');
    }

    public function test_edit_steps_and_tips_then_revert(): void
    {
        $this->putJson('/panduan/kelola/kas/steps', ['value' => [['text' => 'Buka shift dengan modal awal', 'route' => 'kas'], 'Tutup shift dan hitung laci']])->assertOk()
            ->assertJsonPath('data.effective.steps', [['text' => 'Buka shift dengan modal awal', 'route' => 'kas'], ['text' => 'Tutup shift dan hitung laci']]);
        $this->putJson('/panduan/kelola/kas/tips', ['value' => ['Selalu hitung dua kali']])->assertOk();

        $this->deleteJson('/panduan/kelola/kas/steps', ['catatan' => 'kembali ke otomatis'])->assertOk()
            ->assertJsonPath('reverted', true)->assertJsonPath('data.effective.steps', GuideEntry::where('key', 'kas')->first()->steps)
            ->assertJsonPath('data.versions.0.type', 'revert');
        $this->deleteJson('/panduan/kelola/kas/steps')->assertOk()->assertJsonPath('reverted', false);
        $this->assertSame(['tips'], GuideOverride::pluck('field')->all());
        $this->assertSame(1, ActivityLog::where('event', 'guide.reverted')->count());
    }

    public function test_validation_and_unknown_fields(): void
    {
        $this->putJson('/panduan/kelola/kasir/title', ['value' => ''])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->putJson('/panduan/kelola/kasir/steps', ['value' => 'bukan daftar'])->assertUnprocessable();
        $this->putJson('/panduan/kelola/kasir/title', [])->assertUnprocessable();
        $this->putJson('/panduan/kelola/kasir/related', ['value' => ['x']])->assertNotFound();
        $this->putJson('/panduan/kelola/kasir/title', ['value' => 'ok judul', 'catatan' => str_repeat('x', 201)])->assertUnprocessable();
        $this->assertSame(0, GuideOverride::count());
    }

    public function test_only_admin_can_edit(): void
    {
        foreach (['kasir@koperasi.test', 'pengurus@koperasi.test'] as $email) {
            $this->actingAs(User::where('email', $email)->first());
            $this->getJson('/panduan/kelola/kasir')->assertForbidden();
            $this->putJson('/panduan/kelola/kasir/title', ['value' => 'diretas'])->assertForbidden();
        }
        $multi = User::factory()->role('kasir')->create();
        $multi->roles()->attach(Role::named('admin')->id);
        $this->actingAs($multi->fresh());
        $this->putJson('/panduan/kelola/kasir/title', ['value' => 'oleh admin multi peran'])->assertOk();
        auth()->logout();
        $this->putJson('/panduan/kelola/kasir/title', ['value' => 'x'])->assertUnauthorized();
    }

    public function test_override_survives_regeneration(): void
    {
        $this->putJson('/panduan/kelola/kasir/summary', ['value' => 'Ringkasan dari admin untuk kasir baru.'])->assertOk();
        $this->artisan('panduan:susun')->assertSuccessful();
        $this->getJson('/panduan/kelola/kasir')->assertJsonPath('data.effective.summary', 'Ringkasan dari admin untuk kasir baru.');
    }
}
