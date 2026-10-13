<?php

namespace Tests\Feature\Sistem;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Product;
use App\Models\RestoreTest;
use App\Services\BackupService;
use App\Services\RestoreTestService;
use Database\Seeders\MemberSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestoreTestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([ProductSeeder::class, MemberSeeder::class]);
    }

    public function test_successful_restore_test_compares_counts(): void
    {
        $backup = app(BackupService::class)->run('basis data', 'manual');
        $this->assertSame('berhasil', $backup->status);
        Product::factory()->create(); // data setelah cadangan

        $t = app(RestoreTestService::class)->run($backup, auth()->user());
        $this->assertSame(['berhasil', 0, null], [$t->status, $t->mismatches, $t->error]);
        $this->assertSame(array_keys(RestoreTestService::STEPS), array_column($t->steps, 'key'));
        $products = collect($t->tables)->firstWhere('table', 'products');
        $this->assertSame([$products['backup'], true, $products['backup'] + 1], [$products['restored'], $products['ok'], $products['live']]);
        $members = collect($t->tables)->firstWhere('table', 'members');
        $this->assertGreaterThan(0, $members['restored']);
        $this->assertSame('Admin Koperasi', $t->toRow()['by']);
        $this->assertFalse(is_file(storage_path('app/restore-test-'.$t->id.'.sqlite')));
        $this->assertTrue(ActivityLog::where('event', 'backup.restore_tested')->exists());
        $this->assertSame(Product::count(), Product::query()->count()); // data asli utuh
    }

    public function test_tampered_archive_fails_checksum(): void
    {
        $backup = app(BackupService::class)->run('basis data', 'manual');
        file_put_contents(Storage::disk('local')->path($backup->path), 'rusak', FILE_APPEND);
        $t = app(RestoreTestService::class)->run($backup);
        $this->assertSame('gagal', $t->status);
        $this->assertStringContainsString('Checksum arsip tidak cocok', $t->error);
        $this->assertSame([['key' => 'verify', 'label' => 'Periksa checksum arsip', 'ok' => false]], $t->steps);
    }

    public function test_failed_or_missing_backup(): void
    {
        $failed = Backup::factory()->failed()->create();
        $this->assertStringContainsString('tidak berhasil dibuat', app(RestoreTestService::class)->run($failed)->error);
        $missing = Backup::factory()->create(['path' => 'cadangan/hilang.zip']);
        $this->assertStringContainsString('tidak ditemukan', app(RestoreTestService::class)->run($missing)->error);
        $this->assertSame(2, RestoreTest::where('status', 'gagal')->count());
    }
}
