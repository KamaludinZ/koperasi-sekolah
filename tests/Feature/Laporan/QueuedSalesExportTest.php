<?php

namespace Tests\Feature\Laporan;

use App\Jobs\ExportSalesReport;
use App\Livewire\Laporan\Index;
use App\Models\ReportExport;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class QueuedSalesExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([ProductSeeder::class, TransactionSeeder::class]);
    }

    public function test_export_is_queued_and_listed_until_ready(): void
    {
        Queue::fake();
        $c = Livewire::test(Index::class)->set('exportFrom', now()->subDays(6)->toDateString())->set('exportTo', now()->toDateString())
            ->call('export')->assertHasNoErrors()->assertNoFileDownloaded()->assertDispatched('toast');
        Queue::assertPushedOn('ekspor', ExportSalesReport::class);

        $export = ReportExport::sole();
        $this->assertSame(['penjualan', 'pending', 'csv'], [$export->type, $export->status, $export->format]);
        $c->assertSee('Unduhan terakhir')->assertSee('Menunggu antrean');

        (new ExportSalesReport($export->id))->handle(app(\App\Services\SalesExport::class));
        $export->refresh();
        $this->assertSame('done', $export->status);
        Storage::disk('local')->assertExists($export->file_path);
        $c->call('refreshExports')->assertSee('Unduh '.$export->file_name);

        $this->get(route('laporan.unduhan', $export))->assertOk()->assertDownload($export->file_name);
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first() ?? User::factory()->create());
        $this->get(route('laporan.unduhan', $export))->assertNotFound();
    }

    public function test_failed_job_and_pending_limit(): void
    {
        Queue::fake();
        foreach (range(1, 3) as $_) {
            Livewire::test(Index::class)->call('export');
        }
        Livewire::test(Index::class)->call('export')->assertHasErrors('exportFrom');
        $this->assertSame(3, ReportExport::count());

        $job = new ExportSalesReport(ReportExport::first()->id);
        $job->failed(new \RuntimeException('Disk penuh'));
        $this->assertSame(['failed', 'Disk penuh'], [ReportExport::first()->status, ReportExport::first()->error]);
        $this->get(route('laporan.unduhan', ReportExport::first()))->assertStatus(409);
    }
}
