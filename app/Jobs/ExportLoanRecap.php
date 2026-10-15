<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\AuditLog;
use App\Services\LoanRecapExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Membuat berkas rekap pinjaman (CSV atau PDF) di antrean latar untuk satu ReportExport: status
 * processing → done (berkas di disk privat) atau failed (pesan galat singkat).
 */
class ExportLoanRecap implements ShouldQueue
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

    public function handle(LoanRecapExport $builder): void
    {
        $export = ReportExport::find($this->exportId);
        if (! $export || $export->status === 'done') {
            return;
        }
        $export->update(['status' => 'processing', 'error' => null]);

        $from = Carbon::parse($export->params['from']);
        $to = Carbon::parse($export->params['to']);
        $format = $export->format === 'pdf' ? 'pdf' : 'csv';
        $result = $format === 'pdf' ? $builder->pdf($from, $to, $export->params['label'] ?? $from->format('d/m/Y').' – '.$to->format('d/m/Y')) : $builder->csv($from, $to);

        $name = 'rekap-pinjaman-'.$from->format('Ymd').'-'.$to->format('Ymd').'.'.$format;
        $path = 'exports/pinjaman/'.Str::random(24).'.'.$format;
        Storage::disk('local')->put($path, $result['content']);

        $export->update(['status' => 'done', 'file_path' => $path, 'file_name' => $name, 'rows' => $result['rows'], 'finished_at' => now()]);

        AuditLog::record('report.exported', 'laporan', 'Ekspor rekap pinjaman '.strtoupper($format).' periode '.$from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            null, ['type' => 'pinjaman_rekap', 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $result['rows']],
            null, $export->user ? ['id' => $export->user->id, 'name' => $export->user->name, 'role' => $export->user->roleName()] : null);
    }

    public function failed(?Throwable $e): void
    {
        ReportExport::whereKey($this->exportId)->update([
            'status' => 'failed', 'error' => Str::limit($e?->getMessage() ?? 'Gagal membuat berkas.', 180), 'finished_at' => now(),
        ]);
    }
}
