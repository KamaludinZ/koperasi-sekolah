<?php

namespace App\Livewire\Barang;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\ProductImport;
use App\Services\ProductImportService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Barang → Impor → Pratinjau (Admin & Pengurus): setiap baris berkas diperiksa dengan aturan
 * ProductImportFormat terhadap kategori & barang yang ada. Ditandai baru / perbarui (dengan
 * perbandingan nilai lama → baru) / dilewati / bermasalah; baris bermasalah bisa diperbaiki
 * langsung atau dilewati, lalu disimpan ke data barang lewat ProductImportService.
 */
#[Layout('components.layouts.app')]
#[Title('Pratinjau Impor')]
class ImportPreview extends Component
{
    use NotifiesSave;

    public const FILTERS = ['semua' => 'Semua', 'masalah' => 'Bermasalah', 'baru' => 'Baru', 'perbarui' => 'Diperbarui', 'lewati' => 'Dilewati'];

    public int $importId;

    public string $filter = 'semua';

    public ?int $editing = null;

    /** @var array<string, string> isian baris yang sedang diperbaiki */
    public array $draft = [];

    public function mount(int $import): void
    {
        $this->importId = $import;
        abort_unless($this->model !== null, 404);
    }

    #[Computed]
    public function model(): ?ProductImport
    {
        return ProductImport::with('user')->find($this->importId);
    }

    /** @return array<string, mixed> bentuk ringkas impor (ProductImportService::toArray) */
    #[Computed]
    public function import(): array
    {
        return ProductImportService::toArray($this->model);
    }

    #[Computed]
    public function finished(): bool
    {
        return $this->model->status !== 'pratinjau';
    }

    #[Computed]
    public function evaluated(): array
    {
        return app(ProductImportService::class)->evaluate($this->model);
    }

    /** @return list<array{id: int, line: int, values: array, action: string, reason: ?string, errors: array, changes: array, skipped: bool}> */
    #[Computed]
    public function rows(): array
    {
        return $this->evaluated['rows'];
    }

    #[Computed]
    public function counts(): array
    {
        return $this->evaluated['counts'];
    }

    #[Computed]
    public function visible(): array
    {
        return $this->filter === 'semua' ? $this->rows : array_values(array_filter($this->rows, fn ($r) => $r['action'] === $this->filter));
    }

    private function refreshRows(): void
    {
        unset($this->model, $this->import, $this->finished, $this->evaluated, $this->rows, $this->counts, $this->visible);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'semua';
    }

    public function edit(int $line): void
    {
        $row = $this->model->rows()->where('line', $line)->first();
        if (! $row || $this->finished) {
            return;
        }
        $this->editing = $line;
        $this->draft = array_map('strval', $row->values);
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'draft');
    }

    public function saveRow(): void
    {
        if ($this->editing === null || $this->finished) {
            return;
        }
        $line = $this->editing;
        app(ProductImportService::class)->updateRow($this->model, $line, array_map(fn ($v) => (string) $v, $this->draft));
        $this->cancelEdit();
        $this->refreshRows();

        $row = collect($this->rows)->firstWhere('line', $line);
        $this->toast($row['errors'] ? 'error' : 'success', $row['errors'] ? "Baris {$line} masih bermasalah." : "Baris {$line} sudah benar.");
    }

    public function toggleSkip(int $line): void
    {
        $row = $this->model->rows()->where('line', $line)->first();
        if (! $row || $this->finished) {
            return;
        }
        app(ProductImportService::class)->setSkipped($this->model, $line, ! $row->skipped);
        $this->refreshRows();
    }

    public function skipProblems(): void
    {
        if ($this->finished) {
            return;
        }
        $n = app(ProductImportService::class)->skipProblems($this->model);
        $this->refreshRows();
        $this->toast('success', "{$n} baris bermasalah dilewati.");
    }

    public function commit()
    {
        if ($this->finished) {
            return null;
        }
        $c = $this->counts;
        if ($c['masalah'] > 0) {
            $this->filter = 'masalah';
            $this->toast('error', "Masih ada {$c['masalah']} baris bermasalah. Perbaiki atau lewati dulu.");

            return null;
        }

        try {
            $import = app(ProductImportService::class)->commit($this->model, auth()->user());
        } catch (ValidationException $e) {
            $this->refreshRows();
            $this->toast('error', collect($e->errors())->flatten()->first());

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => "{$import->created} barang baru ditambahkan, {$import->updated} diperbarui."]);

        return $this->redirectRoute('barang.impor.detail', ['import' => $import->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.barang.import-preview');
    }
}
