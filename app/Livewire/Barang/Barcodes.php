<?php

namespace App\Livewire\Barang;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductBarcode;
use App\Support\BarcodeImage;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Barang → Barcode & label: status barcode semua barang (mengikuti kode / barcode khusus), pilih banyak
 * barang lalu buat nomor EAN-13 internal baru atau kembalikan ke kode barang sekaligus (ProductBarcode).
 * Pilihan tetap tersimpan saat berpindah halaman/filter.
 */
#[Layout('components.layouts.app')]
#[Title('Barcode & Label')]
class Barcodes extends Component
{
    use NotifiesSave, WithPagination;

    public const STATUSES = ['' => 'Semua barang', 'kode' => 'Mengikuti kode', 'khusus' => 'Barcode khusus'];

    #[Url(as: 'per', except: 50)]
    public int $perPage = 50;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $status = '';

    /** @var list<int> id barang terpilih */
    public array $selected = [];

    /** Timpa barcode khusus yang sudah ada saat membuat nomor baru. */
    public bool $overwrite = false;

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'selected')) {
            $this->selected = array_values(array_unique(array_map('intval', $this->selected)));
        }
        if ($property === 'perPage' && ! in_array($this->perPage, Index::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 50;
        }
        if (in_array($property, ['search', 'category', 'status', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    private function query()
    {
        $term = mb_strtolower(trim($this->search));

        return Product::query()->with('category:id,name')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(code) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(barcode) LIKE ?', ["%{$term}%"])))
            ->when($this->category !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('name', $this->category)))
            ->when($this->status === 'kode', fn ($q) => $q->whereNull('barcode'))
            ->when($this->status === 'khusus', fn ($q) => $q->whereNotNull('barcode'))
            ->orderBy('code');
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return $this->query()->paginate(in_array($this->perPage, Index::PER_PAGE_OPTIONS, true) ? $this->perPage : 50)->through(fn (Product $p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'category' => $p->category?->name,
            'barcode' => BarcodeImage::value($p), 'custom' => filled($p->barcode), 'is_active' => (bool) $p->is_active,
        ]);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Product::count(),
            'custom' => Product::whereNotNull('barcode')->count(),
        ];
    }

    #[Computed]
    public function categories(): array
    {
        return Category::orderBy('name')->pluck('name')->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->can('kelola-katalog');
    }

    /** Centang/lepas semua barang di halaman yang tampil. */
    public function togglePage(): void
    {
        $ids = collect($this->products->items())->pluck('id')->all();
        $this->selected = array_values(count(array_diff($ids, $this->selected)) === 0
            ? array_diff($this->selected, $ids)
            : array_unique([...$this->selected, ...$ids]));
    }

    /** Pilih semua barang yang cocok dengan filter (semua halaman). */
    public function selectAllMatching(): void
    {
        $this->selected = $this->query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function generate(): void
    {
        $this->apply('baru');
    }

    public function resetToCode(): void
    {
        $this->apply('kode');
    }

    private function apply(string $mode): void
    {
        abort_unless($this->canManage, 403);
        if (! $this->selected) {
            $this->toast('error', 'Pilih barang terlebih dahulu.');

            return;
        }
        $r = app(ProductBarcode::class)->assignMany($this->selected, $mode, $this->overwrite);
        unset($this->products, $this->stats);

        $done = $mode === 'baru' ? "Nomor barcode baru dibuat untuk {$r['changed']} barang." : "{$r['changed']} barang kembali memakai kode barang.";
        $skip = $r['skipped'] ? ' '.$r['skipped'].($mode === 'baru' ? ' barang sudah punya barcode khusus (dilewati).' : ' barang sudah memakai kode barang.') : '';
        $this->toast($r['changed'] ? 'success' : 'error', $done.$skip);
        if ($r['changed']) {
            $this->selected = [];
        }
    }

    public function render()
    {
        return view('livewire.barang.barcodes');
    }
}
