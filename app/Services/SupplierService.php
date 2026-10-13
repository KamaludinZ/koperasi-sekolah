<?php

namespace App\Services;

use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kelola supplier: daftar dengan pembelian 90 hari (nilai penerimaan), hutang berjalan, dan pesanan
 * terakhir; tambah/ubah (nama unik di antara yang aktif, kategori yang dipasok); hapus lunak beralasan
 * (ditolak bila masih ada pesanan berjalan atau hutang). Perubahan tercatat di audit (MasterDataObserver,
 * perubahan kategori dicatat terpisah).
 */
class SupplierService
{
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', function ($attr, $value, $fail) use ($ignoreId) {
                $taken = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)))])
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
                if ($taken) {
                    $fail('Nama supplier sudah terdaftar.');
                }
            }],
            'contact' => ['nullable', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:200'],
            'terms_days' => ['required', 'integer', 'min:0', 'max:'.Supplier::MAX_TERMS_DAYS],
            'note' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
            'categories' => ['array'],
            'categories.*' => ['string', Rule::exists('categories', 'name')->withoutTrashed()],
        ];
    }

    public static function messages(): array
    {
        return [
            'name.required' => 'Isi nama supplier.',
            'name.min' => 'Nama minimal 3 huruf.',
            'phone.required' => 'Isi nomor telepon supplier.',
            'phone.regex' => 'Nomor telepon tidak valid.',
            'terms_days.max' => 'Tempo maksimal '.Supplier::MAX_TERMS_DAYS.' hari.',
            'categories.*.exists' => 'Kategori tidak dikenal.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    /**
     * @param  array{status?: string, category?: string, search?: string, sort?: string}  $f
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $term = mb_strtolower(trim($f['search'] ?? ''));
        $suppliers = Supplier::query()->with('categories')
            ->when(($f['status'] ?? 'semua') === 'aktif', fn ($q) => $q->where('is_active', true))
            ->when(($f['status'] ?? 'semua') === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->when(($f['category'] ?? '') !== '', fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('name', $f['category'])))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(COALESCE(contact, \'\')) LIKE ?', ["%{$term}%"])->orWhere('phone', 'like', "%{$term}%")))
            ->orderBy('name')->get();

        $ids = $suppliers->modelKeys();
        $purchases = GoodsReceipt::whereIn('supplier_id', $ids)->where('received_at', '>=', now()->subDays(90)->toDateString())
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total_value) AS v')->pluck('v', 'supplier_id');
        $payable = SupplierBill::whereIn('supplier_id', $ids)->open()->groupBy('supplier_id')
            ->selectRaw('supplier_id, SUM(amount - paid) AS v')->pluck('v', 'supplier_id');
        $lastOrder = PurchaseOrder::whereIn('supplier_id', $ids)->where('status', '!=', 'draf')
            ->groupBy('supplier_id')->selectRaw('supplier_id, MAX(ordered_at) AS d')->pluck('d', 'supplier_id');

        $rows = $suppliers->map(fn (Supplier $s) => self::toArray($s) + [
            'purchases_90d' => (int) ($purchases[$s->id] ?? 0),
            'open_payable' => (int) ($payable[$s->id] ?? 0),
            'last_order_at' => isset($lastOrder[$s->id]) ? substr((string) $lastOrder[$s->id], 0, 10) : null,
        ]);

        return $rows->sortBy(match ($f['sort'] ?? 'nama') {
            'pembelian' => [['purchases_90d', 'desc']],
            'hutang' => [['open_payable', 'desc']],
            'terakhir' => [['last_order_at', 'desc']],
            default => [['name', 'asc']],
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public static function toArray(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'contact' => $s->contact,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'terms_days' => $s->terms_days,
            'terms_label' => $s->termsLabel(),
            'categories' => $s->categories->pluck('name')->all(),
            'note' => $s->note,
            'is_active' => $s->is_active,
        ];
    }

    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $supplier = Supplier::create(self::clean($data));
            $this->syncCategories($supplier, $data['categories'] ?? []);

            return $supplier->load('categories');
        });
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->update(self::clean($data));
            if (array_key_exists('categories', $data)) {
                $this->syncCategories($supplier, $data['categories']);
            }

            return $supplier->refresh()->load('categories');
        });
    }

    /** @throws ValidationException bila masih ada pesanan berjalan atau hutang */
    public function delete(Supplier $supplier, ?string $reason = null): void
    {
        $orders = PurchaseOrder::where('supplier_id', $supplier->id)->whereIn('status', ['draf', 'dipesan', 'sebagian'])->count();
        $debt = (int) SupplierBill::where('supplier_id', $supplier->id)->open()->sum(DB::raw('amount - paid'));
        if ($orders || $debt) {
            throw ValidationException::withMessages(['supplier' => trim(($orders ? "Masih ada {$orders} pesanan berjalan. " : '')
                .($debt ? 'Masih ada hutang Rp'.number_format($debt, 0, ',', '.').'. ' : '').'Selesaikan dulu atau nonaktifkan supplier.')]);
        }
        $supplier->trash($reason);
    }

    private static function clean(array $data): array
    {
        return collect($data)->only(['name', 'contact', 'phone', 'email', 'address', 'terms_days', 'note', 'is_active'])
            ->map(fn ($v, $k) => match ($k) {
                'name' => preg_replace('/\s+/', ' ', trim($v)),
                'terms_days' => (int) $v,
                'is_active' => (bool) $v,
                default => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v),
            })->all();
    }

    /** @param  list<string>  $names */
    private function syncCategories(Supplier $supplier, array $names): void
    {
        $before = $supplier->categories()->pluck('name')->sort()->values()->all();
        $supplier->categories()->sync(Category::whereIn('name', $names)->pluck('id'));
        $after = $supplier->categories()->pluck('name')->sort()->values()->all();
        if ($before !== $after && $supplier->wasRecentlyCreated === false) {
            AuditLog::record('supplier.updated', 'pengadaan', "Kategori supplier {$supplier->name} diubah",
                ['type' => 'supplier', 'id' => $supplier->id, 'label' => $supplier->name], [],
                action: 'diubah', old: ['kategori' => implode(', ', $before) ?: '—'], new: ['kategori' => implode(', ', $after) ?: '—']);
        }
    }

    /** Rincian supplier: data + pesanan (terbaru dulu) + tagihan yang belum lunas. */
    public function detail(Supplier $supplier): array
    {
        return self::toArray($supplier->loadMissing('categories')) + [
            'orders' => PurchaseOrder::where('supplier_id', $supplier->id)->latest('ordered_at')->latest('id')->limit(20)->get()
                ->map(fn (PurchaseOrder $o) => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'ordered_at' => $o->ordered_at->toDateString(), 'total' => $o->total])->all(),
            'payables' => SupplierBill::with('order')->where('supplier_id', $supplier->id)->open()->orderBy('due_at')->get()
                ->map(fn (SupplierBill $b) => ['id' => $b->id, 'po_number' => $b->order?->number, 'amount' => $b->amount, 'paid' => $b->paid, 'due_at' => $b->due_at->toDateString()])->all(),
        ];
    }
}
