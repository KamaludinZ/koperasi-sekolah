<?php

namespace App\Livewire\Barang;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Support\MockData;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Barang → Kategori & impor: halaman utama pengelolaan katalog. Daftar kategori (urutan, jumlah
 * barang, stok menipis, nilai stok) dan pintu masuk impor massal barang dari CSV/Excel beserta
 * riwayat impor terbaru. Admin & Pengurus mengelola kategori: tambah/ubah (nama & warna),
 * hapus (barangnya dipindah ke kategori lain), dan atur urutan lewat CategoryService. Riwayat impor
 * masih memakai MockData sampai backend impor tersedia.
 */
#[Layout('components.layouts.app')]
#[Title('Kategori & Impor')]
class Catalog extends Component
{
    use NotifiesSave;

    /** Status impor → [label, kelas lencana]. */
    public const IMPORT_STATUSES = [
        'pratinjau' => ['Menunggu diperiksa', 'bg-sky-100 text-sky-800'],
        'selesai' => ['Selesai', 'bg-emerald-100 text-emerald-800'],
        'sebagian' => ['Sebagian tersimpan', 'bg-amber-100 text-amber-900'],
        'gagal' => ['Gagal', 'bg-red-100 text-red-800'],
    ];

    /** Warna titik kategori. */
    public const COLORS = [
        'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-400', 'sky' => 'bg-sky-500',
        'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500', 'rose' => 'bg-rose-500', 'stone' => 'bg-stone-400',
    ];

    public bool $formOpen = false;

    public ?int $editId = null;

    public string $name = '';

    public string $color = 'emerald';

    public ?int $deleteId = null;

    public ?int $moveTo = null;

    public bool $reordering = false;

    /** @var list<int> urutan id saat mengatur urutan */
    public array $order = [];

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('kelola-katalog');
    }

    public function create(): void
    {
        if (! $this->guard()) {
            return;
        }
        $this->reset('editId', 'name');
        $this->color = collect(Category::COLORS)->first(fn ($c) => ! collect($this->categories)->contains('color', $c)) ?? 'stone';
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $c = collect($this->categories)->firstWhere('id', $id);
        if (! $c || ! $this->guard()) {
            return;
        }
        $this->fill(['editId' => $id, 'name' => $c['name'], 'color' => $c['color'], 'formOpen' => true]);
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! $this->guard()) {
            return;
        }
        $this->name = preg_replace('/\s+/', ' ', trim($this->name));
        $this->saveWithToast(function () {
            $rules = CategoryService::rules($this->editId);
            $this->validate(['name' => $rules['name'], 'color' => $rules['color']], CategoryService::messages());
            $service = app(CategoryService::class);
            $this->editId
                ? $service->update(Category::findOrFail($this->editId), ['name' => $this->name, 'color' => $this->color])
                : $service->create(['name' => $this->name, 'color' => $this->color]);
            unset($this->categories, $this->summary, $this->rows);
            $this->formOpen = false;
        }, $this->editId ? "Kategori {$this->name} diperbarui." : "Kategori {$this->name} ditambahkan.");
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetValidation();
    }

    public function confirmDelete(int $id): void
    {
        if (! $this->guard()) {
            return;
        }
        $this->deleteId = $id;
        $this->moveTo = null;
        $this->resetValidation();
    }

    public function cancelDelete(): void
    {
        $this->reset('deleteId', 'moveTo');
        $this->resetValidation();
    }

    public function delete(): void
    {
        $c = $this->deleting;
        if (! $c || ! $this->guard()) {
            $this->cancelDelete();

            return;
        }
        try {
            $target = app(CategoryService::class)->delete(Category::findOrFail($c['id']), $this->moveTo ? (int) $this->moveTo : null);
        } catch (ValidationException $e) {
            $this->addError('moveTo', $e->errors()['pindah_ke'][0] ?? 'Pilih kategori tujuan.');

            return;
        }
        unset($this->categories, $this->summary, $this->rows, $this->deleting);
        $target = $target?->name;
        $this->cancelDelete();
        $this->toast('success', "Kategori {$c['name']} dihapus".($target ? ", barangnya dipindah ke {$target}." : '.'));
    }

    #[Computed]
    public function deleting(): ?array
    {
        return $this->deleteId ? collect($this->categories)->firstWhere('id', $this->deleteId) : null;
    }

    public function startReorder(): void
    {
        if (! $this->guard()) {
            return;
        }
        $this->order = collect($this->categories)->pluck('id')->all();
        $this->reordering = true;
    }

    public function move(int $id, int $step): void
    {
        $i = array_search($id, $this->order, true);
        $j = $i === false ? false : $i + ($step < 0 ? -1 : 1);
        if ($j === false || $j < 0 || $j >= count($this->order)) {
            return;
        }
        [$this->order[$i], $this->order[$j]] = [$this->order[$j], $this->order[$i]];
    }

    public function saveOrder(): void
    {
        if (! $this->guard()) {
            return;
        }
        app(CategoryService::class)->reorder($this->order);
        unset($this->categories, $this->rows);
        $this->reordering = false;
        $this->toast('success', 'Urutan kategori disimpan.');
    }

    public function cancelReorder(): void
    {
        $this->reset('reordering', 'order');
    }

    /** Kategori sesuai urutan sementara saat mengatur urutan. */
    #[Computed]
    public function rows(): array
    {
        if (! $this->reordering) {
            return $this->categories;
        }
        $byId = collect($this->categories)->keyBy('id');

        return collect($this->order)->filter(fn ($id) => $byId->has($id))
            ->map(fn ($id, $i) => [...$byId[$id], 'position' => $i + 1])->values()->all();
    }

    private function guard(): bool
    {
        if ($this->canManage) {
            return true;
        }
        $this->toast('error', 'Hanya Admin dan Pengurus yang dapat mengelola kategori.');

        return false;
    }

    #[Computed]
    public function categories(): array
    {
        return app(CategoryService::class)->list();
    }

    #[Computed]
    public function imports(): array
    {
        return MockData::importHistory();
    }

    #[Computed]
    public function summary(): array
    {
        $cats = collect($this->categories);
        $imports = collect($this->imports);
        $recent = $imports->filter(fn ($i) => $i['created_at'] >= now()->subDays(30)->toDateTimeString());

        return [
            'categories' => $cats->count(),
            'empty' => $cats->where('products', 0)->count(),
            'products' => $cats->sum('products'),
            'stock_value' => $cats->sum('stock_value'),
            'imports_30d' => $recent->count(),
            'imported_rows_30d' => $recent->sum(fn ($i) => $i['created'] + $i['updated']),
            'failed_rows_30d' => $recent->sum('failed'),
            'pending' => $imports->firstWhere('status', 'pratinjau'),
        ];
    }

    /** Tautan ke halaman lanjutan bila sudah tersedia (null = segera hadir). */
    public static function link(string $route, array $params = []): ?string
    {
        return Route::has($route) ? route($route, $params) : null;
    }

    public function render()
    {
        return view('livewire.barang.catalog');
    }
}
