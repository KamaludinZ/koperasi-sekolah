<?php

namespace App\Livewire\Barang;

use App\Support\MockData;
use App\Support\ProductImportFormat;
use App\Support\SimpleXlsx;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Rincian satu impor barang: hasil (baru/diperbarui/dilewati/gagal), cara menyimpan, pengunggah &
 * waktu, kesalahan berkas, daftar baris gagal/dilewati beserta alasannya. Aksi: unduh laporan
 * hasil (CSV/Excel), salin ringkasan untuk dikirim ke pengurus, dan salin baris bermasalah
 * (dipisah tab, siap ditempel ke Excel). Tahap ini memakai MockData.
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
        return collect(MockData::importHistory())->firstWhere('id', $this->importId);
    }

    #[Computed]
    public function detail(): array
    {
        return MockData::importDetail($this->importId);
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

    /** @return list<list<scalar|null>> laporan hasil: ringkasan + baris bermasalah */
    private function reportRows(): array
    {
        $i = $this->import;
        $rows = [
            ['Berkas', $i['file_name']], ['Status', Catalog::IMPORT_STATUSES[$i['status']][0]], ['Diunggah', $i['user'].', '.$i['created_at']],
            ['Baris', $i['total_rows']], ['Baru', $i['created']], ['Diperbarui', $i['updated']], ['Dilewati', $i['skipped']], ['Gagal', $i['failed']],
        ];
        if ($this->detail['file_error']) {
            $rows[] = ['Kesalahan berkas', $this->detail['file_error']];
        }
        $rows[] = [];
        $rows[] = ['baris', 'kode', 'nama', 'status', 'alasan'];
        foreach ($this->detail['problems'] as $p) {
            $rows[] = [$p['line'], $p['kode'], $p['nama'], $p['status'], $p['message']];
        }

        return $rows;
    }

    /** Unduh laporan hasil impor (ringkasan + baris bermasalah) sebagai CSV atau Excel. */
    public function download(string $format = 'csv')
    {
        $name = 'hasil-impor-'.pathinfo($this->import['file_name'], PATHINFO_FILENAME);
        $rows = $this->reportRows();

        if ($format === 'xlsx') {
            $binary = SimpleXlsx::write($rows, 'Hasil impor');

            return response()->streamDownload(fn () => print ($binary), "{$name}.xlsx",
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
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
