<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\MockData;

/**
 * Menyusun data struk dari transaksi tersimpan. Bentuk array ini dipakai
 * partial `livewire.kasir.partials.receipt`, dialog sukses kasir, dan endpoint detail.
 */
class ReceiptPresenter
{
    public function present(Transaction $transaction): array
    {
        $transaction->loadMissing(['items', 'user:id,name']);

        return [
            'id' => $transaction->id,
            'invoice_number' => $transaction->invoice_number,
            'created_at' => $transaction->created_at->toDateTimeString(),
            'cashier' => $transaction->user?->name ?? 'Kasir',
            'payment_method' => $transaction->payment_method,
            'payment_method_label' => CheckoutService::PAYMENT_METHODS[$transaction->payment_method] ?? $transaction->payment_method,
            'items' => $transaction->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'price' => (int) $item->price,
                'quantity' => $item->quantity,
                'subtotal' => (int) $item->subtotal,
            ])->all(),
            'subtotal' => (int) $transaction->subtotal,
            'tax' => (int) $transaction->tax,
            'discount' => (int) $transaction->discount,
            'total' => (int) $transaction->total,
            'paid' => (int) $transaction->paid,
            'change' => (int) $transaction->change,
        ];
    }

    /** Identitas koperasi untuk kepala & kaki struk (nanti dari tabel settings). */
    public function profile(): array
    {
        return MockData::profile();
    }
}
