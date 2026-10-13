<?php

namespace App\Livewire\Barang;

use App\Livewire\Concerns\NotifiesSave;
use App\Services\UploadPolicy;
use App\Support\MockData;
use App\Support\ProductImportFormat;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Barang → Impor barang (Admin & Pengurus): unggah berkas CSV/XLSX sesuai templat, pilih cara
 * menyimpan (tambah & perbarui, hanya tambah, hanya perbarui) dan apakah stok barang lama ikut
 * diperbarui. Berkas diperiksa UploadPolicy (jenis, ukuran, isi) lalu diteruskan ke pratinjau.
 * Tahap ini mencatat impor tiruan lewat MockData::createImport().
 */
#[Layout('components.layouts.app')]
#[Title('Impor Barang')]
class Import extends Component
{
    use NotifiesSave;
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    public string $mode = 'semua';

    public bool $updateStock = false;

    #[Computed]
    public function limits(): array
    {
        return UploadPolicy::limits('import');
    }

    public function updatedFile(): void
    {
        $this->validateOnly('file', $this->rules(), $this->messages());
    }

    protected function rules(): array
    {
        return [
            'file' => UploadPolicy::rules('import'),
            'mode' => ['required', 'in:'.implode(',', array_keys(ProductImportFormat::MODES))],
            'updateStock' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return UploadPolicy::messages('import', 'file') + ['file.required' => 'Pilih berkas CSV atau Excel terlebih dahulu.'];
    }

    public function removeFile(): void
    {
        $this->file?->delete();
        $this->reset('file');
        $this->resetValidation('file');
    }

    public function upload()
    {
        $this->validate($this->rules(), $this->messages());

        $import = MockData::createImport(
            $this->file->getClientOriginalName(), strtolower($this->file->getClientOriginalExtension()),
            $this->mode, $this->mode !== 'tambah' && $this->updateStock, auth()->user()->name,
        );
        $this->file->delete();
        $this->reset('file');

        if (Route::has('barang.impor.pratinjau')) {
            return $this->redirectRoute('barang.impor.pratinjau', ['import' => $import['id']], navigate: true);
        }
        $this->toast('success', "{$import['file_name']} diterima dan menunggu diperiksa.");
    }

    public function render()
    {
        return view('livewire.barang.import');
    }
}
