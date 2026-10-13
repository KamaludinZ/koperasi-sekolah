<?php

namespace App\Livewire\Barang;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Category;
use App\Models\Product;
use App\Support\MockData;
use App\Support\ProductImportFormat;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Barang → Impor → Pratinjau (Admin & Pengurus): setiap baris berkas diperiksa dengan aturan
 * ProductImportFormat terhadap kategori & barang yang ada. Ditandai baru / perbarui (dengan
 * perbandingan nilai lama → baru) / dilewati / bermasalah; baris bermasalah bisa diperbaiki
 * langsung atau dilewati, lalu disimpan. Tahap ini membaca baris & menyimpan hasil lewat MockData.
 */
#[Layout('components.layouts.app')]
#[Title('Pratinjau Impor')]
class ImportPreview extends Component
{
    use NotifiesSave;

    public const FILTERS = ['semua' => 'Semua', 'masalah' => 'Bermasalah', 'baru' => 'Baru', 'perbarui' => 'Diperbarui', 'lewati' => 'Dilewati'];

    /** Kolom barang yang dibandingkan saat memperbarui. */
    private const COMPARE = ['nama' => 'name', 'harga_jual' => 'price', 'harga_modal' => 'cost_price', 'batas_minimum' => 'min_stock', 'satuan' => 'unit'];

    public int $importId;

    public string $filter = 'semua';

    public ?int $editing = null;

    /** @var array<string, string> isian baris yang sedang diperbaiki */
    public array $draft = [];

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
    public function finished(): bool
    {
        return ($this->import['status'] ?? null) !== 'pratinjau';
    }

    /** @return list<array{line: int, values: array, action: string, reason: ?string, errors: array, changes: array, skipped: bool}> */
    #[Computed]
    public function rows(): array
    {
        $raw = MockData::importRows($this->importId);
        $categories = Category::ordered()->pluck('name')->all();
        $cleaned = array_map(fn ($r) => [...$r, 'values' => ProductImportFormat::clean($r['values'])], $raw);

        $seen = [];
        foreach ($cleaned as $r) {
            if (! $r['skipped'] && $r['values']['kode'] !== '') {
                $seen[$r['values']['kode']] ??= $r['line'];
            }
        }
        $existing = Product::whereIn('code', array_keys($seen))->with('category')->get()->keyBy('code');
        $mode = $this->import['mode'] ?? 'semua';
        $updateStock = (bool) ($this->import['update_stock'] ?? false);

        return array_map(function ($r) use ($categories, $seen, $existing, $mode, $updateStock) {
            $v = $r['values'];
            $product = $existing[$v['kode']] ?? null;
            $errors = $r['skipped'] ? [] : ProductImportFormat::validateRow($v, $categories, $seen, $r['line']);
            $action = $product ? 'perbarui' : 'baru';
            $reason = null;
            if ($r['skipped']) {
                [$action, $reason] = ['lewati', 'Dilewati petugas'];
            } elseif ($mode === 'tambah' && $product) {
                [$action, $reason] = ['lewati', 'Kode sudah ada (mode hanya tambah)'];
            } elseif ($mode === 'perbarui' && ! $product) {
                [$action, $reason] = ['lewati', 'Kode baru (mode hanya perbarui)'];
            }
            if ($action === 'lewati') {
                $errors = [];
            }

            $changes = [];
            if ($product && $action === 'perbarui' && $errors === []) {
                foreach (self::COMPARE as $col => $field) {
                    if ($v[$col] !== '' && (string) $v[$col] !== (string) (is_numeric($product->{$field}) ? (int) $product->{$field} : $product->{$field})) {
                        $changes[$col] = [$product->{$field}, $v[$col]];
                    }
                }
                if (mb_strtolower($v['kategori']) !== mb_strtolower((string) $product->category?->name)) {
                    $changes['kategori'] = [$product->category?->name, $v['kategori']];
                }
                if (ProductImportFormat::isActive($v['aktif']) !== (bool) $product->is_active) {
                    $changes['aktif'] = [$product->is_active ? 'ya' : 'tidak', ProductImportFormat::isActive($v['aktif']) ? 'ya' : 'tidak'];
                }
                if ($updateStock && $v['stok'] !== '' && (int) $v['stok'] !== (int) $product->stock) {
                    $changes['stok'] = [$product->stock, $v['stok']];
                }
                if ($changes === []) {
                    [$action, $reason] = ['lewati', 'Tidak ada perubahan'];
                }
            }

            return ['line' => $r['line'], 'values' => $v, 'action' => $errors ? 'masalah' : $action, 'reason' => $reason,
                'errors' => $errors, 'changes' => $changes, 'skipped' => $r['skipped']];
        }, $cleaned);
    }

    #[Computed]
    public function counts(): array
    {
        $rows = collect($this->rows);

        return [
            'semua' => $rows->count(),
            'masalah' => $rows->where('action', 'masalah')->count(),
            'baru' => $rows->where('action', 'baru')->count(),
            'perbarui' => $rows->where('action', 'perbarui')->count(),
            'lewati' => $rows->where('action', 'lewati')->count(),
        ];
    }

    #[Computed]
    public function visible(): array
    {
        return $this->filter === 'semua' ? $this->rows : array_values(array_filter($this->rows, fn ($r) => $r['action'] === $this->filter));
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'semua';
    }

    public function edit(int $line): void
    {
        $row = collect($this->rows)->firstWhere('line', $line);
        if (! $row || $this->finished) {
            return;
        }
        $this->editing = $line;
        $this->draft = collect(MockData::importRows($this->importId))->firstWhere('line', $line)['values'];
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
        $values = array_intersect_key(array_map(fn ($v) => (string) $v, $this->draft), array_flip(ProductImportFormat::headers()));
        MockData::updateImportRow($this->importId, $this->editing, values: $values + array_fill_keys(ProductImportFormat::headers(), ''));
        $line = $this->editing;
        $this->cancelEdit();
        unset($this->rows, $this->counts, $this->visible);

        $row = collect($this->rows)->firstWhere('line', $line);
        $this->toast($row['errors'] ? 'error' : 'success', $row['errors'] ? "Baris {$line} masih bermasalah." : "Baris {$line} sudah benar.");
    }

    public function toggleSkip(int $line): void
    {
        $row = collect(MockData::importRows($this->importId))->firstWhere('line', $line);
        if (! $row || $this->finished) {
            return;
        }
        MockData::updateImportRow($this->importId, $line, skipped: ! $row['skipped']);
        unset($this->rows, $this->counts, $this->visible);
    }

    public function skipProblems(): void
    {
        foreach ($this->rows as $r) {
            if ($r['action'] === 'masalah') {
                MockData::updateImportRow($this->importId, $r['line'], skipped: true);
            }
        }
        unset($this->rows, $this->counts, $this->visible);
        $this->toast('success', 'Baris bermasalah dilewati.');
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
        if ($c['baru'] + $c['perbarui'] === 0) {
            $this->toast('error', 'Tidak ada baris yang perlu disimpan.');

            return null;
        }

        MockData::finishImport($this->importId, [
            'total_rows' => $c['semua'], 'created' => $c['baru'], 'updated' => $c['perbarui'], 'skipped' => $c['lewati'], 'failed' => 0,
        ]);
        session()->flash('toast', ['type' => 'success', 'message' => "{$c['baru']} barang baru ditambahkan, {$c['perbarui']} diperbarui."]);

        return $this->redirectRoute(Route::has('barang.impor.riwayat') ? 'barang.impor.riwayat' : 'barang.kategori', navigate: true);
    }

    public function render()
    {
        return view('livewire.barang.import-preview');
    }
}
