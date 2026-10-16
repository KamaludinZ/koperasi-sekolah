<?php

namespace Tests\Feature\Panduan;

use App\Jobs\SyncGuides;
use App\Livewire\Panduan\Updates;
use App\Models\ActivityLog;
use App\Models\GuideSyncJob;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Endpoint pantau status sinkronisasi panduan: status, riwayat, rincian, antre manual, akses. */
class GuideSyncStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    public function test_idle_status_with_pending_changes(): void
    {
        $res = $this->getJson('/panduan/pembaruan/sinkron')->assertOk();
        $this->assertSame(['idle', [], null, null], [$res->json('data.state'), $res->json('data.active'), $res->json('data.last'), $res->json('data.pending_changes.trigger')]);
        Role::named('kasir')->update(['permissions' => [...Role::named('kasir')->permissions, 'laporan']]);
        $this->getJson('/panduan/pembaruan/sinkron')->assertJsonPath('data.pending_changes.trigger', 'permission_changed')
            ->assertJsonPath('data.pending_changes.changed.laporan', ['roles']);
    }

    public function test_status_history_and_detail(): void
    {
        $done = SyncGuides::enqueue('scheduled');                                                     // jalan langsung (antrean sync)
        GuideSyncJob::create(['status' => 'failed', 'trigger' => 'menu_changed', 'error' => 'RuntimeException: x', 'started_at' => now(), 'finished_at' => now()->addSecond()]);
        $res = $this->getJson('/panduan/pembaruan/sinkron')->assertOk();
        $this->assertSame('failed', $res->json('data.state'));
        $this->assertSame($done->fresh()->finished_at->toIso8601String(), $res->json('data.last_success_at'));
        $this->assertSame(['queued' => 0, 'running' => 0, 'done' => 1, 'failed' => 1], $res->json('data.counts'));
        $this->assertSame(2, $res->json('meta.total'));
        $this->getJson('/panduan/pembaruan/sinkron?status=done')->assertJsonPath('meta.total', 1)->assertJsonPath('history.0.trigger_label', 'Terjadwal');
        $this->getJson('/panduan/pembaruan/sinkron?status=aneh')->assertUnprocessable();
        $this->getJson('/panduan/pembaruan/sinkron/'.$done->id)->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.scope', null);
        $this->getJson('/panduan/pembaruan/sinkron/999999')->assertNotFound();
    }

    public function test_manual_enqueue_and_conflict(): void
    {
        Queue::fake();
        $this->postJson('/panduan/pembaruan/sinkron')->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.trigger', 'manual');
        Queue::assertPushed(SyncGuides::class, 1);
        $this->assertSame(1, ActivityLog::where('event', 'guide.sync_queued')->count());
        $this->getJson('/panduan/pembaruan/sinkron')->assertJsonPath('data.state', 'queued');
        $this->postJson('/panduan/pembaruan/sinkron')->assertStatus(409);
        Queue::assertPushed(SyncGuides::class, 1);
    }

    public function test_panel_polls_while_sync_active(): void
    {
        GuideSyncJob::create(['status' => 'running', 'trigger' => 'scheduled', 'started_at' => now()]);
        Livewire::withQueryParams(['tab' => 'riwayat'])->test(Updates::class)->assertSeeHtml('data-sync-polling')->assertSeeHtml('wire:poll.5s');
        GuideSyncJob::query()->update(['status' => 'done', 'finished_at' => now()]);
        Livewire::withQueryParams(['tab' => 'riwayat'])->test(Updates::class)->assertDontSeeHtml('data-sync-polling');
    }

    public function test_endpoints_for_guide_managers_only(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->getJson('/panduan/pembaruan/sinkron')->assertForbidden();
        $this->postJson('/panduan/pembaruan/sinkron')->assertForbidden();
        $this->getJson('/panduan/pembaruan/sinkron/1')->assertForbidden();
    }
}
