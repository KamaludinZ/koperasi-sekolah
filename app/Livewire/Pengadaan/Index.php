<?php

namespace App\Livewire\Pengadaan;

use App\Models\Product;
use App\Support\MockData;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pengadaan (Admin & Pengurus): ringkasan pembelian barang dari supplier — pesanan berjalan &
 * barang yang menunggu diterima, hutang jatuh tempo terdekat, barang stok menipis yang perlu
 * dipesan (dari data barang sungguhan, dengan saran supplier sesuai kategori), dan supplier aktif.
 * Supplier, pesanan, dan hutang tahap ini memakai MockData; backend menyusul dengan kontrak yang sama.
 */
#[Layout('components.layouts.app')]
#[Title('Pengadaan')]
class Index extends Component
{
    /** Status pesanan → [label, kelas lencana]. */
    public const ORDER_STATUSES = [
        'draf' => ['Draf', 'bg-stone-200 text-stone-700'],
        'dipesan' => ['Dipesan', 'bg-sky-100 text-sky-800'],
        'sebagian' => ['Diterima sebagian', 'bg-amber-100 text-amber-900'],
        'diterima' => ['Diterima', 'bg-emerald-100 text-emerald-800'],
        'dibatalkan' => ['Dibatalkan', 'bg-red-100 text-red-800'],
    ];

    /** Hutang yang jatuh tempo dalam sekian hari ditandai. */
    public const DUE_SOON_DAYS = 7;

    #[Computed]
    public function suppliers(): array
    {
        return app(\App\Services\SupplierService::class)->list();
    }

    #[Computed]
    public function orders(): array
    {
        return app(\App\Services\PurchaseOrderService::class)->list();
    }

    #[Computed]
    public function payables(): array
    {
        return collect(MockData::payables())->where('status', '!=', 'lunas')->sortBy('due_at')->values()->all();
    }

    /** Pesanan yang masih berjalan (draf, dipesan, diterima sebagian). */
    #[Computed]
    public function openOrders(): array
    {
        return collect($this->orders)->whereIn('status', ['draf', 'dipesan', 'sebagian'])->sortBy('expected_at')->values()->all();
    }

    /** Barang aktif dengan stok ≤ batas minimum, plus saran supplier menurut kategori. */
    #[Computed]
    public function restock(): array
    {
        $byCategory = collect($this->suppliers)->where('is_active', true)
            ->flatMap(fn ($s) => collect($s['categories'])->mapWithKeys(fn ($c) => [$c => $s['name']]));

        return Product::query()->where('is_active', true)->whereColumn('stock', '<=', 'min_stock')->with('category')
            ->orderByRaw('stock - min_stock')->limit(8)->get()
            ->map(fn (Product $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'stock' => $p->stock, 'min_stock' => $p->min_stock,
                'unit' => $p->unit, 'suggested' => max($p->min_stock * 2 - $p->stock, 1),
                'supplier' => $byCategory[$p->category?->name] ?? null,
            ])->all();
    }

    #[Computed]
    public function summary(): array
    {
        $open = collect($this->openOrders);
        $payables = collect($this->payables);
        $soon = now()->addDays(self::DUE_SOON_DAYS)->toDateString();

        return [
            'suppliers' => collect($this->suppliers)->where('is_active', true)->count(),
            'open_orders' => $open->count(),
            'open_value' => $open->sum('total'),
            'awaiting_qty' => $open->where('status', '!=', 'draf')->sum(fn ($o) => $o['qty'] - $o['received_qty']),
            'payable' => $payables->sum(fn ($p) => $p['amount'] - $p['paid']),
            'overdue' => $payables->filter(fn ($p) => $p['due_at'] < now()->toDateString())->count(),
            'due_soon' => $payables->filter(fn ($p) => $p['due_at'] >= now()->toDateString() && $p['due_at'] <= $soon)->count(),
            'restock' => count($this->restock),
        ];
    }

    public static function link(string $route, array $params = []): ?string
    {
        return Route::has($route) ? route($route, $params) : null;
    }

    public function render()
    {
        return view('livewire.pengadaan.index');
    }
}
