<?php

namespace App\Livewire\Barang;

use App\Livewire\Concerns\NotifiesSave;
use App\Services\UploadPolicy;
use App\Services\ProductImportService;
use App\Support\ProductImportFormat;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Barang → Impor barang (Admin & Pengurus): unggah berkas CSV/XLSX sesuai templat, pilih cara
 * menyimpan (tambah & perbarui, hanya tambah, hanya perbarui) dan apakah stok barang lama ikut
 * diperbarui. Berkas diperiksa UploadPolicy (jenis, ukuran, isi) lalu diteruskan ke pratinjau.
 * Berkas disimpan & dibaca oleh ProductImportService; berkas yang gagal dibaca menuju rinciannya.
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

        try {
            $import = app(ProductImportService::class)->upload($this->file, $this->mode, $this->updateStock, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => collect($e->errors())->flatten()->first()]);
        }
        $this->reset('file');

        if ($import->status === 'gagal') {
            session()->flash('toast', ['type' => 'error', 'message' => "{$import->file_name} tidak dapat dibaca."]);

            return $this->redirectRoute('barang.impor.detail', ['import' => $import->id], navigate: true);
        }

        return $this->redirectRoute('barang.impor.pratinjau', ['import' => $import->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.barang.import');
    }
}
