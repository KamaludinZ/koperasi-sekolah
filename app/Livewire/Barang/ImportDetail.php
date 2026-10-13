<?php

namespace App\Livewire\Barang;

use App\Support\MockData;
use App\Support\ProductImportFormat;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Rincian satu impor barang: hasil (baru/diperbarui/dilewati/gagal), cara menyimpan, pengunggah &
 * waktu, kesalahan berkas, daftar baris gagal/dilewati beserta alasannya, dan unduh baris
 * bermasalah sebagai CSV untuk diperbaiki lalu diimpor ulang. Tahap ini memakai MockData.
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

    /** Unduh baris bermasalah (CSV) untuk diperbaiki dan diimpor ulang. */
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
