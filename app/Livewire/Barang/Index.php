<?php

namespace App\Livewire\Barang;

use App\Livewire\Forms\ProductForm;
use App\Models\Product;
use App\Services\ProductCatalog;
use App\Services\ProductListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Halaman daftar barang: semua barang beserta harga dan sisa stok.
 * Data dibaca dari tabel products lewat ProductListing.
 */
#[Layout('components.layouts.app')]
#[Title('Barang & Stok')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'per', except: 10)]
    public int $perPage = 10;

    public const PER_PAGE_OPTIONS = ProductListing::PER_PAGE_OPTIONS;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    /** Filter status: '' (semua), 'menipis', 'habis', 'nonaktif'. */
    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public ProductForm $form;

    public bool $editing = false;

    public ?string $flash = null;

    /** Barang yang sedang dilihat di panel detail. */
    #[Url(as: 'lihat', except: null)]
    public ?int $viewing = null;

    public const STATUSES = [
        '' => 'Semua status',
        'menipis' => 'Stok menipis',
        'habis' => 'Stok habis',
        'nonaktif' => 'Nonaktif',
    ];

    public const SORTS = [
        'name' => 'Nama (A–Z)',
        'stock' => 'Stok paling sedikit',
        'price' => 'Harga termurah',
        'price_desc' => 'Harga termahal',
    ];

    /** Halaman aktif dari hasil filter (query database berpaginasi). */
    #[Computed]
    public function paginated(): LengthAwarePaginator
    {
        return app(ProductListing::class)->paginate([
            'search' => $this->search,
            'category' => $this->category,
            'status' => $this->status,
            'sort' => $this->sort,
        ], $this->perPage, $this->getPage());
    }

    /** Barang aktif yang perlu segera ditambah stoknya (habis + menipis). */
    #[Computed]
    public function lowStock(): array
    {
        return app(ProductListing::class)->lowStock();
    }

    /** Filter apa pun berubah → kembali ke halaman 1. */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'status', 'sort', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function stats(): array
    {
        return app(ProductListing::class)->stats();
    }

    /** Klik kartu ringkasan: aktifkan filter status, klik lagi untuk melepas. */
    public function toggleStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    /** @return array<string, mixed>|null barang di panel detail (dengan nilai barcode) */
    #[Computed]
    public function detail(): ?array
    {
        $p = $this->viewing ? Product::with('category')->find($this->viewing) : null;
        if (! $p) {
            return null;
        }

        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'category' => $p->category?->name,
            'unit' => $p->unit, 'price' => (int) $p->price, 'cost_price' => (int) $p->cost_price,
            'stock' => (int) $p->stock, 'min_stock' => (int) $p->min_stock, 'is_active' => (bool) $p->is_active,
            'barcode' => \App\Support\BarcodeImage::value($p),
            'updated_at' => $p->updated_at?->toDateTimeString(),
        ];
    }

    public function show(int $productId): void
    {
        $this->viewing = $productId;
        unset($this->detail);
    }

    public function closeDetail(): void
    {
        $this->viewing = null;
    }

    /** Kosongkan barcode → label & scan memakai kode barang. */
    public function useCodeAsBarcode(): void
    {
        $this->form->barcode = '';
        $this->resetValidation('form.barcode');
    }

    /** Isi barcode dengan nomor EAN-13 internal baru (belum disimpan sampai form disimpan). */
    public function generateBarcode(): void
    {
        $this->form->barcode = app(\App\Services\ProductBarcode::class)->generate($this->form->id);
        $this->resetValidation('form.barcode');
    }

    public function create(): void
    {
        $this->form->blank();
        $this->flash = null;
        $this->editing = true;
    }

    public function edit(int $productId): void
    {
        $model = Product::with('category:id,name')->find($productId);
        $product = $model ? ProductCatalog::toArray($model) : null;
        if (! $product) {
            return;
        }

        $this->form->load($product);
        $this->flash = null;
        $this->viewing = null;
        $this->editing = true;
    }

    public function save(): void
    {
        $isNew = $this->form->id === null;
        $product = $this->form->save();

        $this->editing = false;
        $this->flash = $isNew
            ? "{$product['name']} ditambahkan."
            : "Perubahan {$product['name']} disimpan.";
        unset($this->paginated, $this->stats, $this->lowStock);
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    #[On('min-stock-updated')]
    public function refreshAfterMinStock(string $message): void
    {
        $this->flash = $message;
        unset($this->paginated, $this->stats, $this->lowStock);
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'category', 'status', 'sort');
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.barang.index', [
            'categories' => app(ProductListing::class)->categories(),
        ]);
    }
}
