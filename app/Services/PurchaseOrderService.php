<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pesanan pembelian ke supplier: buat (draf atau langsung dipesan) dengan salinan barang & harga beli,
 * tandai dipesan, dan batalkan beralasan (hanya selama belum ada barang yang diterima). Nomor
 * PO-YYYYMM-NNNN (unik di database). Setiap langkah tercatat di audit (Pengadaan).
 */
class PurchaseOrderService
{
    public const MAX_LINES = 50;

    public static function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)->withoutTrashed()],
            'expected_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.now()->addDays(91)->toDateString()],
            'note' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['draf', 'dipesan'])],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->withoutTrashed()],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.cost' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'supplier_id.required' => 'Pilih supplier.',
            'supplier_id.exists' => 'Pilih supplier yang aktif.',
            'expected_at.after_or_equal' => 'Perkiraan tiba tidak boleh sebelum hari ini.',
            'expected_at.before' => 'Perkiraan tiba maksimal 90 hari lagi.',
            'items.required' => 'Tambahkan minimal satu barang.',
            'items.min' => 'Tambahkan minimal satu barang.',
            'items.*.product_id.distinct' => 'Barang yang sama dimasukkan dua kali.',
            'items.*.qty.*' => 'Jumlah minimal 1.',
            'items.*.cost.*' => 'Harga beli harus angka 0 atau lebih.',
        ];
    }

    /**
     * @param  array{supplier_id: int, expected_at: string, note?: ?string, status?: ?string, items: list<array{product_id: int, qty: int, cost: int}>}  $data
     */
    public function create(array $data, ?User $by = null): PurchaseOrder
    {
        $by ??= auth()->user();

        return DB::transaction(function () use ($data, $by) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $status = ($data['status'] ?? 'draf') === 'dipesan' ? 'dipesan' : 'draf';
            $order = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(), 'supplier_id' => $supplier->id, 'status' => $status,
                'ordered_at' => today(), 'expected_at' => $data['expected_at'], 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'terms_days' => $supplier->terms_days, 'created_by' => $by?->id, 'sent_at' => $status === 'dipesan' ? now() : null,
            ]);
            $products = Product::whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
            foreach ($data['items'] as $line) {
                $p = $products[$line['product_id']];
                $order->items()->create(['product_id' => $p->id, 'product_code' => $p->code, 'product_name' => $p->name, 'unit' => $p->unit,
                    'quantity' => (int) $line['qty'], 'cost' => (int) $line['cost']]);
            }
            $order->recalculate();

            AuditLog::record($status === 'dipesan' ? 'purchase.ordered' : 'purchase.drafted', 'pengadaan',
                "Pesanan {$order->number} ke {$supplier->name} ".($status === 'dipesan' ? 'dipesan' : 'disimpan sebagai draf').': '.count($data['items']).' barang',
                self::subject($order), ['items' => count($data['items']), 'expected_at' => $data['expected_at']], $order->total);

            return $order->load('items', 'supplier');
        });
    }

    /** Draf → dipesan (dikirim ke supplier). */
    public function send(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== 'draf') {
            throw ValidationException::withMessages(['status' => 'Hanya pesanan draf yang bisa ditandai dipesan.']);
        }
        $order->update(['status' => 'dipesan', 'sent_at' => now(), 'ordered_at' => today()]);
        AuditLog::record('purchase.ordered', 'pengadaan', "Pesanan {$order->number} ke {$order->supplier->name} dipesan", self::subject($order), [], $order->total);

        return $order;
    }

    /** Batalkan pesanan yang belum menerima barang apa pun. */
    public function cancel(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['draf', 'dipesan'], true) || $order->items()->where('received_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['status' => 'Pesanan yang sudah menerima barang, selesai, atau dibatalkan tidak bisa dibatalkan.']);
            }
            $order->update(['status' => 'dibatalkan', 'cancelled_at' => now(), 'cancel_reason' => mb_substr(trim($reason), 0, 200)]);
            AuditLog::record('purchase.cancelled', 'pengadaan', "Pesanan {$order->number} ke {$order->supplier->name} dibatalkan",
                self::subject($order), [], $order->total, reason: $order->cancel_reason);

            return $order;
        });
    }

    /**
     * @param  array{status?: string, supplier?: int|string, search?: string}  $f
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $term = mb_strtolower(trim($f['search'] ?? ''));

        return PurchaseOrder::query()->with(['supplier', 'creator'])->withSum('items as qty', 'quantity')->withSum('items as received_qty', 'received_quantity')
            ->withCount('items')
            ->when(in_array($f['status'] ?? '', array_keys(PurchaseOrder::STATUSES), true), fn ($q) => $q->where('status', $f['status']))
            ->when(($f['status'] ?? '') === 'berjalan', fn ($q) => $q->whereIn('status', ['draf', 'dipesan', 'sebagian']))
            ->when(($f['status'] ?? '') === 'terlambat', fn ($q) => $q->waiting()->where('expected_at', '<', today()))
            ->when(($f['supplier'] ?? '') !== '', fn ($q) => $q->where('supplier_id', (int) $f['supplier']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(number) LIKE ?', ["%{$term}%"])
                ->orWhereHas('supplier', fn ($s) => $s->withTrashed()->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"]))))
            ->latest('ordered_at')->latest('id')->get()
            ->map(fn (PurchaseOrder $o) => self::toArray($o))->all();
    }

    /** @return array<string, mixed> */
    public static function toArray(PurchaseOrder $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'supplier_id' => $o->supplier_id,
            'supplier' => $o->supplier?->name,
            'status' => $o->status,
            'ordered_at' => $o->ordered_at->toDateString(),
            'expected_at' => $o->expected_at?->toDateString(),
            'items' => (int) ($o->items_count ?? $o->items()->count()),
            'qty' => (int) ($o->qty ?? $o->items()->sum('quantity')),
            'received_qty' => (int) ($o->received_qty ?? $o->items()->sum('received_quantity')),
            'total' => $o->total,
            'terms_days' => $o->terms_days,
            'note' => $o->note,
            'created_by' => $o->creator?->name ?? 'Sistem',
            'cancel_reason' => $o->cancel_reason,
            'late' => $o->isLate(),
        ];
    }

    /** @return list<array{id: int, product_id: ?int, code: string, name: string, unit: string, qty: int, received_qty: int, cost: int}> */
    public static function lines(PurchaseOrder $o): array
    {
        return $o->items->map(fn ($i) => ['id' => $i->id, 'product_id' => $i->product_id, 'code' => $i->product_code, 'name' => $i->product_name,
            'unit' => $i->unit, 'qty' => $i->quantity, 'received_qty' => $i->received_quantity, 'cost' => $i->cost])->all();
    }

    public static function subject(PurchaseOrder $o): array
    {
        return ['type' => 'purchase_order', 'id' => $o->id, 'label' => $o->number];
    }
}
