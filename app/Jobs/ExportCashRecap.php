<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\AuditLog;
use App\Services\CashRecapExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Membuat CSV rekap kas harian di antrean latar untuk satu ReportExport: status
 * processing → done (berkas di disk privat) atau failed (pesan galat singkat).
 */
class ExportCashRecap implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /** Jeda sebelum percobaan ulang (detik). */
    public array $backoff = [30];

    public function __construct(public int $exportId)
    {
        $this->onQueue(config('koperasi.queues.ekspor', 'ekspor'));
    }

    public function handle(CashRecapExport $builder): void
    {
        $export = ReportExport::find($this->exportId);
        if (! $export || $export->status === 'done') {
            return;
        }
        $export->update(['status' => 'processing', 'error' => null]);

        $from = Carbon::parse($export->params['from']);
        $to = Carbon::parse($export->params['to']);
        $result = $builder->csv($from, $to);

        $name = 'rekap-kas-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';
        $path = 'exports/kas/'.Str::random(24).'.csv';
        Storage::disk('local')->put($path, $result['content']);

        $export->update(['status' => 'done', 'file_path' => $path, 'file_name' => $name, 'rows' => $result['rows'], 'finished_at' => now()]);

        AuditLog::record('report.exported', 'laporan', 'Ekspor rekap kas harian CSV periode '.$from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            null, ['type' => 'kas_rekap', 'format' => 'csv', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $result['rows']],
            null, $export->user ? ['id' => $export->user->id, 'name' => $export->user->name, 'role' => $export->user->roleName()] : null);
    }

    public function failed(?Throwable $e): void
    {
        ReportExport::whereKey($this->exportId)->update([
            'status' => 'failed', 'error' => Str::limit($e?->getMessage() ?? 'Gagal membuat berkas.', 180), 'finished_at' => now(),
        ]);
    }
}
