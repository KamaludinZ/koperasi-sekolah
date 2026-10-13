<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan barang dari pesanan pembelian. Dalam satu transaksi berkunci: catat penerimaan
 * (TB-YYYYMM-NNNN), tambah stok tiap barang lewat StockService (tercatat di riwayat stok), perbarui
 * harga modal barang ke harga beli terbaru, tambah jumlah diterima pada item pesanan, ubah status
 * pesanan (sebagian/diterima), dan buat tagihan supplier (jatuh tempo = tanggal terima + tempo).
 */
class GoodsReceiptService
{
    public function __construct(private StockService $stock) {}

    public static function rules(PurchaseOrder $order): array
    {
        return [
            'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.$order->ordered_at->toDateString()],
            'delivery_note' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'received_at.before_or_equal' => 'Tanggal terima tidak boleh setelah hari ini.',
            'received_at.after_or_equal' => 'Tanggal terima tidak boleh sebelum tanggal pesan.',
            'items.required' => 'Isi jumlah barang yang datang.',
        ];
    }

    /**
     * @param  array<int|string, int|string|null>  $quantities  purchase_order_item_id => jumlah datang
     * @param  array{received_at: string, delivery_note?: ?string, note?: ?string}  $meta
     *
     * @throws ValidationException
     */
    public function receive(PurchaseOrder $order, array $quantities, array $meta, ?User $by = null): GoodsReceipt
    {
        $by ??= auth()->user();

        return DB::transaction(function () use ($order, $quantities, $meta, $by) {
            $order = PurchaseOrder::with('supplier')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, PurchaseOrder::WAITING, true)) {
                throw ValidationException::withMessages(['status' => 'Pesanan ini tidak sedang menunggu barang (status: '.PurchaseOrder::STATUSES[$order->status].').']);
            }
            $items = PurchaseOrderItem::where('purchase_order_id', $order->id)->lockForUpdate()->get()->keyBy('id');

            $lines = [];
            $errors = [];
            foreach ($quantities as $itemId => $qty) {
                $qty = (int) $qty;
                $item = $items[(int) $itemId] ?? null;
                if (! $item) {
                    $errors["items.{$itemId}"] = 'Barang tidak ada di pesanan ini.';
                } elseif ($qty > $item->remaining()) {
                    $errors["items.{$itemId}"] = "Melebihi sisa pesanan ({$item->remaining()}).";
                } elseif ($qty > 0) {
                    $lines[] = [$item, $qty];
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Isi jumlah barang yang datang (minimal satu barang).']);
            }

            $receipt = GoodsReceipt::create([
                'number' => GoodsReceipt::nextNumber(), 'purchase_order_id' => $order->id, 'supplier_id' => $order->supplier_id,
                'received_at' => $meta['received_at'], 'delivery_note' => filled($meta['delivery_note'] ?? null) ? trim($meta['delivery_note']) : null,
                'note' => filled($meta['note'] ?? null) ? trim($meta['note']) : null, 'received_by' => $by?->id,
            ]);

            AuditLog::withReason("Penerimaan {$receipt->number} ({$order->number})", function () use ($lines, $receipt, $by) {
                foreach ($lines as [$item, $qty]) {
                    $movement = null;
                    $product = $item->product_id ? Product::find($item->product_id) : null;
                    if ($product) {
                        $movement = $this->stock->increase($product, $qty, "Penerimaan {$receipt->number}", $by?->id);
                        if ((int) $product->cost_price !== $item->cost) {
                            $product->refresh()->update(['cost_price' => $item->cost]);
                        }
                    }
                    $receipt->items()->create(['purchase_order_item_id' => $item->id, 'product_id' => $item->product_id,
                        'quantity' => $qty, 'cost' => $item->cost, 'stock_movement_id' => $movement?->id]);
                    $item->increment('received_quantity', $qty);
                }
            });

            $receipt->update([
                'total_quantity' => array_sum(array_column($lines, 1)),
                'total_value' => array_sum(array_map(fn ($l) => $l[0]->cost * $l[1], $lines)),
            ]);

            $complete = ! PurchaseOrderItem::where('purchase_order_id', $order->id)->whereColumn('received_quantity', '<', 'quantity')->exists();
            $order->update(['status' => $complete ? 'diterima' : 'sebagian', 'received_at' => $complete ? now() : null]);

            SupplierBill::create([
                'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'goods_receipt_id' => $receipt->id,
                'amount' => $receipt->total_value,
                'due_at' => \Illuminate\Support\Carbon::parse($meta['received_at'])->addDays($order->terms_days)->toDateString(),
            ]);

            AuditLog::record('purchase.received', 'pengadaan',
                "Barang {$order->number} dari {$order->supplier->name} diterima ({$receipt->number}): {$receipt->total_quantity} unit".($complete ? ', pesanan lengkap' : ', sebagian'),
                ['type' => 'goods_receipt', 'id' => $receipt->id, 'label' => $receipt->number],
                ['order' => $order->number, 'delivery_note' => $receipt->delivery_note, 'complete' => $complete], $receipt->total_value);

            return $receipt->load('items');
        });
    }

    /** @return list<array<string, mixed>> penerimaan terbaru */
    public function recent(int $limit = 8): array
    {
        return GoodsReceipt::with(['order', 'supplier', 'receiver'])->latest('received_at')->latest('id')->limit($limit)->get()
            ->map(fn (GoodsReceipt $r) => [
                'id' => $r->id, 'number' => $r->number, 'order_id' => $r->purchase_order_id, 'order_number' => $r->order?->number,
                'supplier' => $r->supplier?->name, 'received_at' => $r->received_at->toDateString(), 'delivery_note' => $r->delivery_note,
                'note' => $r->note, 'qty' => $r->total_quantity, 'value' => $r->total_value, 'user' => $r->receiver?->name ?? 'Sistem',
            ])->all();
    }
}
