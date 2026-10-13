<?php

namespace Tests\Feature\Sistem;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Services\BackupService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    public function test_command_creates_zip_with_portable_dump_and_files(): void
    {
        Storage::disk('local')->put('koperasi/logo-abcdefghijklmnop.png', 'PNGDATA');
        $this->artisan('cadangan:buat', ['--manual' => true, '--catatan' => 'Uji cadangan'])->assertSuccessful();

        $b = Backup::sole();
        $this->assertSame(['berhasil', 'manual', 'lengkap', 'Uji cadangan'], [$b->status, $b->trigger, $b->type, $b->note]);
        Storage::disk('local')->assertExists($b->path);
        $abs = Storage::disk('local')->path($b->path);
        $this->assertSame(hash_file('sha256', $abs), $b->checksum);
        $this->assertSame(filesize($abs), $b->size);
        $this->assertContains('logo & berkas unggahan', $b->includes);

        $zip = new ZipArchive;
        $zip->open($abs);
        $data = json_decode($zip->getFromName('database.json'), true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('PNGDATA', $zip->getFromName('berkas/logo-abcdefghijklmnop.png'));
        $zip->close();
        $this->assertCount(\App\Models\Product::count(), $data['products']);
        $this->assertArrayNotHasKey('sessions', $data);
        $this->assertArrayNotHasKey('cache', $data);
        $this->assertSame(count($data['products']), $manifest['tables']['products']);
        $this->assertTrue(ActivityLog::where('event', 'backup.created')->exists());
    }

    public function test_database_only_and_invalid_type(): void
    {
        $this->artisan('cadangan:buat', ['--jenis' => 'basis data'])->assertSuccessful();
        $this->assertSame('terjadwal', Backup::sole()->trigger);
        $this->assertContains('basis data', Backup::sole()->includes);
        $this->assertNotContains('logo & berkas unggahan', Backup::sole()->includes); // pgsql: + salinan pg_dump
        $this->artisan('cadangan:buat', ['--jenis' => 'aneh'])->assertFailed();
    }

    public function test_failure_is_recorded(): void
    {
        Storage::disk('local')->put('cadangan', 'bukan folder'); // folder arsip terhalang berkas → zip gagal dibuat
        $b = app(BackupService::class)->run('basis data', 'manual');
        $this->assertSame(['gagal', null, null], [$b->status, $b->path, $b->checksum]);
        $this->assertNotEmpty($b->error);
        $this->assertTrue(ActivityLog::where('event', 'backup.failed')->exists());
    }

    public function test_prune_keeps_latest_successful(): void
    {
        foreach (range(1, 4) as $i) {
            Backup::factory()->create(['started_at' => now()->subDays($i), 'path' => "cadangan/b{$i}.zip"]);
            Storage::disk('local')->put("cadangan/b{$i}.zip", 'x');
        }
        Backup::factory()->failed()->create(['started_at' => now()->subDays(40)]);
        $this->assertSame(2, app(BackupService::class)->prune(2));
        $this->assertSame(['cadangan/b1.zip', 'cadangan/b2.zip'], Backup::succeeded()->latestFirst()->pluck('path')->all());
        Storage::disk('local')->assertMissing('cadangan/b4.zip');
        $this->assertSame(0, Backup::where('status', 'gagal')->count());
    }

    public function test_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'cadangan:buat') && $e->expression === '0 23 * * *'));
        $this->assertTrue($events->contains(fn ($e) => $e->description === 'health-heartbeat'));
    }
}
