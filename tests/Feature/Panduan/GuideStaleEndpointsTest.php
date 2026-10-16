<?php

namespace Tests\Feature\Panduan;

use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Endpoint tinjau & hapus tanda usang panduan. */
class GuideStaleEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin, 'web');
    }

    public function test_mark_list_and_clear_one(): void
    {
        $this->getJson('/panduan/pembaruan/usang')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/panduan/pembaruan/usang/kas', ['alasan' => 'Prosedur tutup shift berubah'])->assertOk()
            ->assertJsonPath('data.is_stale', true)->assertJsonPath('data.reason', 'Prosedur tutup shift berubah');
        $this->postJson('/panduan/pembaruan/usang/kas', ['alasan' => 'Prosedur tutup shift berubah'])->assertStatus(409);
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        app(GuideService::class)->setOverride(GuideEntry::where('key', 'kas')->first(), 'tips', ['Tips versi admin'], null);

        $g = $this->getJson('/panduan/pembaruan/usang')->assertOk()->json('data.guides.0');
        $this->assertSame(['kas', 'Prosedur tutup shift berubah', ['tips'], ['title']], [$g['key'], $g['reason'], $g['overridden'], $g['draft_changes']]);

        $this->deleteJson('/panduan/pembaruan/usang/kas', ['catatan' => 'Sudah dicek'])->assertOk()->assertJsonPath('data.is_stale', false);
        $this->deleteJson('/panduan/pembaruan/usang/kas')->assertStatus(409);
        $this->assertSame([$this->admin->id, 'Sudah dicek'], [ActivityLog::where('event', 'guide.reviewed')->value('actor_id'), ActivityLog::where('event', 'guide.reviewed')->value('reason')]);
        $this->assertSame($this->admin->id, ActivityLog::where('event', 'guide.stale_marked')->value('actor_id'));
    }

    public function test_validation_and_unknown_key(): void
    {
        $this->postJson('/panduan/pembaruan/usang/kas', ['alasan' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson('/panduan/pembaruan/usang/kas', [])->assertUnprocessable();
        $this->postJson('/panduan/pembaruan/usang/tidak-ada', ['alasan' => 'Alasan yang cukup'])->assertNotFound();
        $this->deleteJson('/panduan/pembaruan/usang/tidak-ada')->assertNotFound();
        $this->deleteJson('/panduan/pembaruan/usang/kas', ['catatan' => str_repeat('a', 201)])->assertUnprocessable();
    }

    public function test_clear_all(): void
    {
        foreach (['kas', 'barang', 'promo'] as $k) {
            $this->postJson("/panduan/pembaruan/usang/{$k}", ['alasan' => 'Perlu ditinjau ulang'])->assertOk();
        }
        $this->deleteJson('/panduan/pembaruan/usang', ['catatan' => 'Tinjau massal'])->assertOk()
            ->assertJsonPath('data.count', 3)->assertJsonPath('data.reviewed', ['kas', 'barang', 'promo']);
        $this->assertSame(0, GuideEntry::where('is_stale', true)->count());
        $this->deleteJson('/panduan/pembaruan/usang')->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_managers_only(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->getJson('/panduan/pembaruan/usang')->assertForbidden();
        $this->postJson('/panduan/pembaruan/usang/kas', ['alasan' => 'Alasan yang cukup'])->assertForbidden();
        $this->deleteJson('/panduan/pembaruan/usang/kas')->assertForbidden();
        $this->deleteJson('/panduan/pembaruan/usang')->assertForbidden();
        $this->assertFalse(GuideEntry::where('key', 'kas')->value('is_stale'));
    }
}
