<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Pesanan pembelian contoh (berbagai status) dengan barang dari kategori yang dipasok supplier.
 * Barang yang sudah diterima dicatat sebagai penerimaan (tanpa mengubah stok contoh) beserta
 * tagihannya; sebagian tagihan sudah dibayar.
 */
class PurchaseOrderSeeder extends Seeder
{
    /** [supplier, status, hari lalu dipesan, perkiraan tiba (+hari dari sekarang), jumlah barang, unit per barang, porsi diterima] */
    private const ORDERS = [
        ['Toko Grosir Jaya', 'dibatalkan', 40, -33, 2, 25, 0.0],
        ['CV Sinar Ilmu', 'diterima', 15, -12, 5, 60, 1.0],
        ['UD Berkah Pangan', 'diterima', 8, -6, 4, 24, 1.0],
        ['Konveksi Amanah', 'draf', 0, 5, 3, 20, 0.0],
        ['CV Sinar Ilmu', 'sebagian', 3, -1, 5, 52, 0.7],
        ['UD Berkah Pangan', 'dipesan', 0, 2, 6, 20, 0.0],
    ];

    public function run(): void
    {
        if (PurchaseOrder::exists() || ! Supplier::exists()) {
            return;
        }
        $admin = User::where('email', 'admin@koperasi.test')->value('id');

        foreach (self::ORDERS as [$supplierName, $status, $ago, $eta, $count, $perItem, $share]) {
            $supplier = Supplier::with('categories')->where('name', $supplierName)->first();
            $products = Product::whereIn('category_id', $supplier->categories->pluck('id'))->orderBy('code')->limit($count)->get();
            $orderedAt = now()->subDays($ago);
            $order = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(), 'supplier_id' => $supplier->id, 'status' => $status,
                'ordered_at' => $orderedAt->toDateString(), 'expected_at' => now()->addDays($eta)->toDateString(),
                'terms_days' => $supplier->terms_days, 'created_by' => $admin,
                'sent_at' => $status === 'draf' ? null : $orderedAt,
                'received_at' => $status === 'diterima' ? now()->addDays($eta) : null,
                'cancelled_at' => $status === 'dibatalkan' ? $orderedAt->copy()->addDay() : null,
                'cancel_reason' => $status === 'dibatalkan' ? 'Harga naik, pindah supplier.' : null,
            ]);
            $remaining = (int) round($count * $perItem * $share);
            foreach ($products as $p) {
                $got = min($perItem, $remaining);
                $remaining -= $got;
                $order->items()->create([
                    'product_id' => $p->id, 'product_code' => $p->code, 'product_name' => $p->name, 'unit' => $p->unit,
                    'quantity' => $perItem, 'received_quantity' => $got, 'cost' => (int) $p->cost_price,
                ]);
            }
            $order->recalculate();

            $received = $order->items()->where('received_quantity', '>', 0)->get();
            if ($received->isNotEmpty()) {
                $receipt = \App\Models\GoodsReceipt::create([
                    'number' => \App\Models\GoodsReceipt::nextNumber(), 'purchase_order_id' => $order->id, 'supplier_id' => $supplier->id,
                    'received_at' => now()->addDays(min($eta, 0))->toDateString(), 'delivery_note' => 'SJ-'.str_pad((string) (800 + $order->id), 4, '0', STR_PAD_LEFT),
                    'note' => $status === 'sebagian' ? 'Sebagian barang menyusul.' : null,
                    'total_quantity' => $received->sum('received_quantity'), 'total_value' => $received->sum(fn ($i) => $i->received_quantity * $i->cost),
                    'received_by' => $admin,
                ]);
                foreach ($received as $i) {
                    $receipt->items()->create(['purchase_order_item_id' => $i->id, 'product_id' => $i->product_id, 'quantity' => $i->received_quantity, 'cost' => $i->cost]);
                }
                $bill = \App\Models\SupplierBill::create([
                    'supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'goods_receipt_id' => $receipt->id,
                    'amount' => $receipt->total_value, 'due_at' => $receipt->received_at->copy()->addDays($supplier->terms_days)->toDateString(),
                ]);
                // Pesanan lama dari Sinar Ilmu sudah lunas; pesanan sebagian dicicil sekali.
                $pay = match (true) {
                    $status === 'diterima' && $supplier->name === 'CV Sinar Ilmu' => $bill->amount,
                    $status === 'sebagian' => 260_000,
                    default => 0,
                };
                if ($pay > 0) {
                    $bill->payments()->create(['amount' => min($pay, $bill->amount), 'paid_at' => now()->subDays($pay === $bill->amount ? 5 : 1)->toDateString(),
                        'method' => $pay === $bill->amount ? 'tunai' : 'transfer', 'reference' => $pay === $bill->amount ? null : 'TRF-55120',
                        'note' => $pay === $bill->amount ? null : 'Cicilan pertama', 'paid_by' => $admin]);
                    $bill->syncPaid();
                }
            }
        }
    }
}
