<?php

namespace App\Livewire\Barang;

use App\Services\ProductImportService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Barang → Impor → Riwayat (Admin & Pengurus): semua proses impor barang dengan status, jumlah
 * baris baru/diperbarui/dilewati/gagal, pengunggah, dan lama proses; saring status & cari nama
 * berkas/pengunggah. Data dari ProductImportService::history().
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Impor')]
class ImportHistory extends Component
{
    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, Catalog::IMPORT_STATUSES) && $this->status !== $status ? $status : '';
    }

    #[Computed]
    public function all(): array
    {
        return app(ProductImportService::class)->history();
    }

    #[Computed]
    public function imports(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect($this->all)
            ->when($this->status !== '', fn ($c) => $c->where('status', $this->status))
            ->when($term !== '', fn ($c) => $c->filter(fn ($i) => str_contains(mb_strtolower($i['file_name'].' '.$i['user']), $term)))
            ->values()->all();
    }

    #[Computed]
    public function totals(): array
    {
        $all = collect($this->all);

        return [
            'imports' => $all->count(),
            'created' => $all->sum('created'),
            'updated' => $all->sum('updated'),
            'failed' => $all->sum('failed'),
            'by_status' => collect(Catalog::IMPORT_STATUSES)->map(fn ($s, $k) => $all->where('status', $k)->count())->all(),
        ];
    }

    /** Lama proses yang mudah dibaca, mis. "2 menit". */
    public static function duration(array $import): ?string
    {
        if (! $import['finished_at']) {
            return null;
        }
        $min = (int) Carbon::parse($import['created_at'])->diffInMinutes(Carbon::parse($import['finished_at']));

        return $min < 1 ? '< 1 menit' : $min.' menit';
    }

    public function render()
    {
        return view('livewire.barang.import-history');
    }
}
