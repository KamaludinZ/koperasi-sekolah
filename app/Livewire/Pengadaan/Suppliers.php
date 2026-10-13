<?php

namespace App\Livewire\Pengadaan;

use App\Models\Supplier;
use App\Services\SupplierService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Supplier (Admin & Pengurus): daftar pemasok dengan kontak, kategori barang yang
 * dipasok, syarat pembayaran (tunai/tempo), pembelian 90 hari, dan hutang berjalan; cari, saring
 * status & kategori, urutkan; panel rincian berisi kontak (telepon/WhatsApp), pesanan, dan hutang.
 * Data dari SupplierService (kontrak sama dengan GET /pengadaan/supplier/data).
 */
#[Layout('components.layouts.app')]
#[Title('Supplier')]
class Suppliers extends Component
{
    public const SORTS = ['nama' => 'Nama', 'pembelian' => 'Pembelian terbesar', 'hutang' => 'Hutang terbesar', 'terakhir' => 'Pesanan terakhir'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'aktif')]
    public string $status = 'aktif';

    #[Url(as: 'kategori', except: '')]
    public string $category = '';

    #[Url(as: 'urut', except: 'nama')]
    public string $sort = 'nama';

    public ?int $selectedId = null;

    #[Computed]
    public function all(): array
    {
        return app(SupplierService::class)->list();
    }

    #[Computed]
    public function categories(): array
    {
        return collect($this->all)->flatMap(fn ($s) => $s['categories'])->unique()->sort()->values()->all();
    }

    #[Computed]
    public function suppliers(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect($this->all)
            ->when($this->status === 'aktif', fn ($c) => $c->where('is_active', true))
            ->when($this->status === 'nonaktif', fn ($c) => $c->where('is_active', false))
            ->when($this->category !== '', fn ($c) => $c->filter(fn ($s) => in_array($this->category, $s['categories'], true)))
            ->when($term !== '', fn ($c) => $c->filter(fn ($s) => str_contains(mb_strtolower($s['name'].' '.$s['contact'].' '.$s['phone']), $term)))
            ->sortBy(match ($this->sort) {
                'pembelian' => [['purchases_90d', 'desc']],
                'hutang' => [['open_payable', 'desc']],
                'terakhir' => [['last_order_at', 'desc']],
                default => [['name', 'asc']],
            })->values()->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->all);

        return [
            'active' => $all->where('is_active', true)->count(),
            'inactive' => $all->where('is_active', false)->count(),
            'purchases_90d' => $all->sum('purchases_90d'),
            'open_payable' => $all->sum('open_payable'),
            'credit' => $all->where('is_active', true)->where('terms_days', '>', 0)->count(),
        ];
    }

    #[Computed]
    public function selected(): ?array
    {
        $supplier = $this->selectedId ? Supplier::find($this->selectedId) : null;

        return $supplier ? app(SupplierService::class)->detail($supplier) : null;
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['aktif', 'nonaktif', 'semua'], true) ? $status : 'aktif';
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closeDetail(): void
    {
        $this->reset('selectedId', 'deleteReason', 'confirmingDelete');
        $this->resetValidation();
    }

    public bool $confirmingDelete = false;

    public string $deleteReason = '';

    /** Hapus lunak supplier terpilih (ditolak bila masih ada pesanan berjalan atau hutang). */
    public function delete(): void
    {
        $supplier = $this->selectedId ? Supplier::find($this->selectedId) : null;
        if (! $supplier || ! \Illuminate\Support\Facades\Gate::allows('kelola-pengadaan')) {
            return;
        }
        $this->validate(['deleteReason' => ['nullable', 'string', 'max:200']]);
        try {
            app(SupplierService::class)->delete($supplier, trim($this->deleteReason) ?: null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('deleteReason', collect($e->errors())->flatten()->first());

            return;
        }
        $this->closeDetail();
        unset($this->all, $this->suppliers, $this->summary);
        $this->dispatch('toast', type: 'success', message: "Supplier {$supplier->name} dihapus.");
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'category', 'sort');
    }

    /** Nomor telepon → tautan WhatsApp (awalan 62), null bila bukan nomor ponsel. */
    public static function whatsapp(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return str_starts_with($digits, '628') && strlen($digits) >= 10 ? 'https://wa.me/'.$digits : null;
    }

    public function render()
    {
        return view('livewire.pengadaan.suppliers');
    }
}
