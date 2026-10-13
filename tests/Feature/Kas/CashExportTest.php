<?php

namespace Tests\Feature\Kas;

use App\Jobs\ExportCashRecap;
use App\Models\ActivityLog;
use App\Models\ReportExport;
use Database\Seeders\CashShiftSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 16:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_request_is_queued(): void
    {
        Queue::fake();

        $this->postJson('/kas/rekap/ekspor', ['periode' => '30-hari'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.from', '2026-09-15')
            ->assertJsonPath('data.download_url', null);

        Queue::assertPushed(ExportCashRecap::class, fn ($job) => $job->exportId === ReportExport::sole()->id);
    }

    public function test_job_builds_csv_and_owner_downloads_it(): void
    {
        $this->seed([ProductSeeder::class, TransactionSeeder::class, CashShiftSeeder::class]);

        // QUEUE_CONNECTION=sync di test: job langsung jalan.
        $id = $this->postJson('/kas/rekap/ekspor', ['dari' => '2026-10-08', 'sampai' => '2026-10-14'])->assertStatus(202)->json('data.id');
        $res = $this->getJson("/kas/rekap/ekspor/{$id}")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame('rekap-kas-20261008-20261014.csv', $res->json('data.file_name'));

        $csv = $this->get($res->json('data.download_url'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('REKAP PER HARI', $csv);
        $this->assertStringContainsString('RINCIAN SHIFT', $csv);
        $this->assertStringContainsString('11/10/2026;Tutup', $csv);
        $this->assertSame(1, ActivityLog::where('event', 'report.exported')->where('properties->type', 'kas_rekap')->count());
    }

    public function test_other_user_cannot_see_or_download(): void
    {
        $id = $this->postJson('/kas/rekap/ekspor', ['periode' => '7-hari'])->json('data.id');
        $this->loginAs('pengurus');

        $this->getJson("/kas/rekap/ekspor/{$id}")->assertNotFound();
        $this->get("/kas/rekap/ekspor/{$id}/unduh")->assertNotFound();
    }

    public function test_not_ready_and_validation_and_pending_limit(): void
    {
        Queue::fake();
        $id = $this->postJson('/kas/rekap/ekspor', ['periode' => '7-hari'])->json('data.id');
        $this->getJson("/kas/rekap/ekspor/{$id}/unduh")->assertStatus(409);

        $this->postJson('/kas/rekap/ekspor', ['dari' => '2025-01-01', 'sampai' => '2026-10-14'])->assertJsonValidationErrors('dari');
        $this->postJson('/kas/rekap/ekspor', ['periode' => 'setahun'])->assertJsonValidationErrors('periode');

        $this->postJson('/kas/rekap/ekspor', ['periode' => '7-hari'])->assertStatus(202);
        $this->postJson('/kas/rekap/ekspor', ['periode' => '7-hari'])->assertStatus(202);
        $this->postJson('/kas/rekap/ekspor', ['periode' => '7-hari'])->assertStatus(429);
    }

    public function test_failed_job_marks_export(): void
    {
        $export = ReportExport::create(['user_id' => auth()->id(), 'type' => 'kas_rekap', 'params' => ['from' => '2026-10-01', 'to' => '2026-10-02']]);
        (new ExportCashRecap($export->id))->failed(new \RuntimeException('Disk penuh'));

        $this->getJson("/kas/rekap/ekspor/{$export->id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'Disk penuh');
    }

    public function test_old_exports_are_pruned_with_files(): void
    {
        Storage::disk('local')->put('exports/kas/lama.csv', 'x');
        $old = ReportExport::create(['user_id' => auth()->id(), 'type' => 'kas_rekap', 'params' => [], 'status' => 'done', 'file_path' => 'exports/kas/lama.csv']);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $fresh = ReportExport::create(['user_id' => auth()->id(), 'type' => 'kas_rekap', 'params' => []]);

        Artisan::call('model:prune', ['--model' => [ReportExport::class]]);

        $this->assertModelMissing($old);
        $this->assertModelExists($fresh);
        Storage::disk('local')->assertMissing('exports/kas/lama.csv');
    }
}
