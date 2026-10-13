<?php

namespace Tests\Feature\Sistem;

use App\Models\Backup;
use App\Models\Notification;
use App\Models\User;
use App\Services\BackupService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_failure_notifies_admin_once_and_success_clears_it(): void
    {
        Storage::disk('local')->put('cadangan', 'bukan folder');
        app(BackupService::class)->run('basis data', 'terjadwal');
        app(BackupService::class)->run('basis data', 'terjadwal');

        $n = Notification::where('dedupe_key', 'like', 'backup-failed-%')->sole();
        $this->assertSame(['sistem', 'admin', 'Cadangan data gagal'], [$n->type, $n->target, $n->title]);
        $this->assertStringContainsString('Saran:', $n->message);
        $this->assertContains($n->id, array_column(app(NotificationService::class)->list(auth()->user()), 'id'));
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->assertNotContains($n->id, array_column(app(NotificationService::class)->list($kasir), 'id'));

        Storage::disk('local')->delete('cadangan');
        $this->assertSame('berhasil', app(BackupService::class)->run('basis data', 'manual')->status);
        $this->assertSame(0, Notification::where('dedupe_key', 'like', 'backup-%')->count());
    }

    public function test_stale_check(): void
    {
        $this->assertTrue(app(BackupService::class)->checkStale()); // belum pernah ada cadangan
        $this->assertStringContainsString('Belum ada cadangan', Notification::where('dedupe_key', 'like', 'backup-stale-%')->sole()->message);
        $this->assertFalse(app(BackupService::class)->checkStale()); // sekali per hari

        Notification::query()->delete();
        Backup::factory()->create(['started_at' => now()->subHours(30)]);
        $this->artisan('cadangan:periksa')->expectsOutputToContain('terlambat')->assertSuccessful();
        $this->assertStringContainsString('30 jam lalu', Notification::where('dedupe_key', 'like', 'backup-stale-%')->sole()->message);

        Notification::query()->delete();
        Backup::factory()->create(['started_at' => now()->subHours(2)]);
        $this->assertFalse(app(BackupService::class)->checkStale());
        $this->assertSame(0, Notification::count());
    }
}
