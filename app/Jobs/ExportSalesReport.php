<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\AuditLog;
use App\Services\SalesExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Membuat berkas rekap penjualan (CSV/PDF) di antrean latar untuk satu ReportExport bertipe `penjualan`:
 * processing → done (berkas di disk privat exports/penjualan) atau failed (pesan galat singkat).
 */
class ExportSalesReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30];

    public function __construct(public int $exportId)
    {
        $this->onQueue(config('koperasi.queues.ekspor', 'ekspor'));
    }

    public function handle(SalesExport $builder): void
    {
        $export = ReportExport::find($this->exportId);
        if (! $export || $export->status === 'done') {
            return;
        }
        $export->update(['status' => 'processing', 'error' => null]);

        $from = Carbon::parse($export->params['from']);
        $to = Carbon::parse($export->params['to']);
        $file = $builder->build($from, $to, $export->params['sections'], $export->format);

        $path = 'exports/penjualan/'.Str::random(24).'.'.$export->format;
        Storage::disk('local')->put($path, $file['content']);
        $export->update(['status' => 'done', 'file_path' => $path, 'file_name' => $file['name'], 'rows' => $file['rows'], 'finished_at' => now()]);

        AuditLog::record('report.exported', 'laporan', 'Ekspor rekap penjualan '.strtoupper($export->format).' periode '.$from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            null, ['type' => 'penjualan', 'format' => $export->format, 'sections' => $export->params['sections'], 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            null, $export->user ? ['id' => $export->user->id, 'name' => $export->user->name, 'role' => $export->user->roleName()] : null);
    }

    public function failed(?Throwable $e): void
    {
        ReportExport::whereKey($this->exportId)->update([
            'status' => 'failed', 'error' => Str::limit($e?->getMessage() ?? 'Gagal membuat berkas.', 180), 'finished_at' => now(),
        ]);
    }
}
