<?php

namespace App\Livewire\Barang;

use App\Models\ProductImport;
use App\Services\ProductImportService;
use App\Support\ProductImportFormat;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Rincian satu impor barang: hasil (baru/diperbarui/dilewati/gagal), cara menyimpan, pengunggah &
 * waktu, kesalahan berkas, daftar baris gagal/dilewati beserta alasannya. Aksi: unduh laporan
 * hasil (CSV/Excel), salin ringkasan untuk dikirim ke pengurus, dan salin baris bermasalah
 * (dipisah tab, siap ditempel ke Excel). Data dari ProductImportService.
 */
#[Layout('components.layouts.app')]
#[Title('Rincian Impor')]
class ImportDetail extends Component
{
    public int $importId;

    public function mount(int $import): void
    {
        $this->importId = $import;
        abort_unless($this->import !== null, 404);
    }

    #[Computed]
    public function import(): ?array
    {
        $import = ProductImport::with('user')->find($this->importId);

        return $import ? ProductImportService::toArray($import) : null;
    }

    #[Computed]
    public function detail(): array
    {
        return app(ProductImportService::class)->detail(ProductImport::findOrFail($this->importId));
    }

    /** Ringkasan satu paragraf untuk disalin (mis. dikirim lewat pesan ke pengurus). */
    #[Computed]
    public function summaryText(): string
    {
        $i = $this->import;
        $when = \Illuminate\Support\Carbon::parse($i['created_at'])->translatedFormat('d M Y H:i');
        $status = Catalog::IMPORT_STATUSES[$i['status']][0];
        $text = "Impor barang {$i['file_name']} ({$when}, oleh {$i['user']}) — {$status}.";
        if ($this->detail['file_error']) {
            return $text.' '.$this->detail['file_error'];
        }

        return $text." {$i['total_rows']} baris: {$i['created']} baru, {$i['updated']} diperbarui, {$i['skipped']} dilewati, {$i['failed']} gagal.";
    }

    /** Baris bermasalah dipisah tab (judul kolom di baris pertama), siap ditempel ke Excel. */
    #[Computed]
    public function problemsTsv(): string
    {
        $clean = fn ($v) => str_replace(["\t", "\r", "\n"], ' ', (string) $v);

        return collect($this->detail['problems'])
            ->map(fn ($p) => implode("\t", array_map($clean, [$p['line'], $p['kode'], $p['nama'], $p['status'], $p['message']])))
            ->prepend(implode("\t", ['baris', 'kode', 'nama', 'status', 'alasan']))->implode("\n");
    }

    /** Unduh laporan hasil impor (ringkasan + hasil setiap baris) sebagai CSV atau Excel. */
    public function download(string $format = 'csv')
    {
        return app(ProductImportService::class)->download(ProductImport::findOrFail($this->importId), $format === 'xlsx' ? 'xlsx' : 'csv');
    }

    /** Unduh hanya baris bermasalah (CSV) untuk diperbaiki dan diimpor ulang. */
    public function downloadProblems()
    {
        $problems = $this->detail['problems'];
        $name = pathinfo($this->import['file_name'], PATHINFO_FILENAME);

        return response()->streamDownload(function () use ($problems) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['baris', 'kode', 'nama', 'status', 'alasan'], ';');
            foreach ($problems as $p) {
                fputcsv($out, [$p['line'], $p['kode'], $p['nama'], $p['status'], $p['message']], ';');
            }
            fclose($out);
        }, "baris-bermasalah-{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        return view('livewire.barang.import-detail', ['modes' => ProductImportFormat::MODES]);
    }
}
