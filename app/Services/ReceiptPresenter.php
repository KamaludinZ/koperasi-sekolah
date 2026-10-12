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
        $transaction->loadMissing(['items', 'user:id,name', 'member:id,name,type,class']);

        return [
            'id' => $transaction->id,
            'invoice_number' => $transaction->invoice_number,
            'created_at' => $transaction->created_at->toDateTimeString(),
            'cashier' => $transaction->user?->name ?? 'Kasir',
            'member' => $transaction->member?->display_name,
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
            'tax_label' => $transaction->tax_label,
            'tax_rate' => (float) $transaction->tax_rate,
            'tax_included' => (int) $transaction->tax_included,
            'rounding' => (int) $transaction->rounding,
            'discount' => (int) $transaction->discount,
            'total' => (int) $transaction->total,
            'paid' => (int) $transaction->paid,
            'change' => (int) $transaction->change,
        ];
    }

    /**
     * Identitas koperasi + pengaturan struk untuk kepala & kaki struk:
     * name/school/address/phone/receipt_footer (kompatibel lama) dan `receipt`
     * (header, subheader, show_*, note, footer, paper). Nanti dari tabel settings.
     */
    public function profile(?array $receipt = null): array
    {
        $profile = MockData::profile();
        $r = $receipt ?? MockData::receipt();
        $r['header'] = filled($r['header'] ?? null) ? $r['header'] : $profile['name'];
        $r['subheader'] = filled($r['subheader'] ?? null) ? $r['subheader'] : $profile['school'];

        return [...$profile, 'receipt_footer' => $r['footer'], 'receipt' => $r];
    }

    /** Lebar kertas struk dalam mm (58 atau 80). */
    public static function paperWidth(array $profile): int
    {
        return ($profile['receipt']['paper'] ?? '58mm') === '80mm' ? 80 : 58;
    }
}
