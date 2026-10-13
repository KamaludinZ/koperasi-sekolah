<?php

namespace App\Livewire\Barang;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\TransactionHistory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Barang → Riwayat stok: semua pergerakan stok (penjualan, penerimaan pengadaan, retur, penyesuaian/opname,
 * impor) terbaru dulu, dengan filter barang, jenis, dan periode; ringkasan unit masuk/keluar; stok sebelum →
 * sesudah, keterangan, dan petugas. Data dari tabel stock_movements.
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Stok')]
class StockHistory extends Component
{
    use WithPagination;

    public const PER_PAGE = 30;

    public const PRESETS = TransactionHistory::PRESETS;

    #[Url(as: 'barang', except: null)]
    public ?int $productId = null;

    #[Url(as: 'jenis', except: '')]
    public string $type = '';

    #[Url(as: 'periode', except: '7-hari')]
    public string $preset = '7-hari';

    #[Url(as: 'q', except: '')]
    public string $productSearch = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['productId', 'type', 'preset'], true)) {
            $this->resetPage();
        }
    }

    public function setPreset(string $preset): void
    {
        $this->preset = array_key_exists($preset, self::PRESETS) ? $preset : '7-hari';
        $this->resetPage();
    }

    public function chooseProduct(?int $id): void
    {
        $this->productId = $id && Product::withTrashed()->whereKey($id)->exists() ? $id : null;
        $this->productSearch = '';
        $this->resetPage();
    }

    #[Computed]
    public function product(): ?Product
    {
        return $this->productId ? Product::withTrashed()->find($this->productId) : null;
    }

    /** @return list<array{id: int, code: string, name: string}> */
    #[Computed]
    public function productResults(): array
    {
        $q = mb_strtolower(trim($this->productSearch));
        if (mb_strlen($q) < 2) {
            return [];
        }

        return Product::query()->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"]))
            ->orderBy('name')->limit(8)->get(['id', 'code', 'name'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])->all();
    }

    private function query()
    {
        [$start, $end] = TransactionHistory::range($this->preset);

        return StockMovement::query()
            ->when($this->productId, fn ($q) => $q->where('product_id', $this->productId))
            ->when(array_key_exists($this->type, StockMovement::TYPES), fn ($q) => $q->where('type', $this->type))
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end));
    }

    #[Computed]
    public function movements(): LengthAwarePaginator
    {
        return $this->query()->with(['product:id,code,name,unit,deleted_at', 'user:id,name'])->latestFirst()->paginate(self::PER_PAGE);
    }

    /** Unit masuk & keluar dalam filter (penyesuaian dihitung menurut arah selisihnya). */
    #[Computed]
    public function summary(): array
    {
        $rows = $this->query()->get(['type', 'quantity', 'stock_before', 'stock_after']);
        $in = $out = 0;
        foreach ($rows as $m) {
            $d = $m->signed_quantity;
            $d >= 0 ? $in += $d : $out += -$d;
        }

        return ['count' => $rows->count(), 'in' => $in, 'out' => $out];
    }

    public function render()
    {
        return view('livewire.barang.stock-history');
    }
}
