<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Transaction;

/**
 * Menyusun data struk dari transaksi tersimpan. Bentuk array ini dipakai
 * partial `livewire.kasir.partials.receipt`, dialog sukses kasir, dan endpoint detail.
 */
class ReceiptPresenter
{
    public function __construct(private SettingsService $settings) {}

    public function present(Transaction $transaction): array
    {
        $transaction->loadMissing(['items', 'user:id,name', 'member:id,member_code,name,type,class']);

        return [
            'id' => $transaction->id,
            'invoice_number' => $transaction->invoice_number,
            'created_at' => $transaction->created_at->toDateTimeString(),
            'cashier' => $transaction->user?->name ?? 'Kasir',
            'member' => $transaction->member?->display_name,
            'member_code' => $transaction->member?->member_code,
            'payment_method' => $transaction->payment_method,
            'payment_method_label' => CheckoutService::PAYMENT_LABELS[$transaction->payment_method] ?? $transaction->payment_method,
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
            'discount_reason' => $transaction->discount > 0 ? self::discountReason($transaction) : null,
            // Rincian potongan (promo / kupon / manual) untuk struk & detail transaksi.
            'discounts' => $transaction->discount > 0
                ? $transaction->discounts()->orderBy('id')->get()->map(fn ($d) => [
                    'source' => $d->source, 'label' => $d->label, 'amount' => (int) round((float) $d->amount), 'reason' => $d->reason,
                ])->all()
                : [],
            'total' => (int) $transaction->total,
            'paid' => (int) $transaction->paid,
            'change' => (int) $transaction->change,
        ] + $this->savingsInfo($transaction);
    }

    /** Saldo awal & akhir untuk transaksi bermetode tabungan. */
    private function savingsInfo(Transaction $transaction): array
    {
        $entry = $transaction->payment_method === 'tabungan' ? app(SavingsPaymentService::class)->forTransaction($transaction->id) : null;

        return $entry ? ['balance_before' => (int) round((float) $entry->balance_before), 'balance_after' => (int) round((float) $entry->balance_after)] : [];
    }

    /**
     * Alasan diskon manual dari rincian diskon (transaction_discounts); transaksi lama dari
     * jejak audit penjualan (`sale.created`). Null bila tidak dicatat.
     */
    public static function discountReason(Transaction $transaction): ?string
    {
        $manual = $transaction->discounts()->where('source', 'manual')->value('reason');
        if (filled($manual)) {
            return $manual;
        }

        $properties = ActivityLog::where('subject_type', 'transaction')
            ->where('subject_id', $transaction->id)
            ->where('event', 'sale.created')
            ->latest('id')
            ->first()?->properties;

        return filled($properties['discount_reason'] ?? null) ? $properties['discount_reason'] : null;
    }

    /**
     * Identitas koperasi + pengaturan struk (tabel settings lewat SettingsService) untuk
     * kepala & kaki struk: name/school/address/phone/logo/receipt_footer dan `receipt`
     * (header, subheader, show_*, note, footer, paper). $receipt = pengaturan struk
     * yang belum disimpan (pratinjau form). Logo hanya diisi bila show_logo aktif.
     */
    public function profile(?array $receipt = null): array
    {
        $profile = $this->settings->profile();
        $r = [...SettingsService::DEFAULTS['receipt'], ...($receipt ?? $this->settings->receipt())];
        $r['header'] = filled($r['header'] ?? null) ? $r['header'] : $profile['name'];
        $r['subheader'] = filled($r['subheader'] ?? null) ? $r['subheader'] : $profile['school'];
        $r['show_logo'] = (bool) $r['show_logo'] && filled($profile['logo']);

        return [...$profile, 'receipt_footer' => $r['footer'], 'receipt' => $r];
    }

    /** profile() + logo sebagai data URI untuk PDF. */
    public function pdfProfile(): array
    {
        $profile = $this->profile();
        $profile['logo_data'] = $profile['receipt']['show_logo']
            ? LogoService::dataUri($this->settings->section('profile')['logo'])
            : null;

        return $profile;
    }

    /** Lebar kertas struk dalam mm (58 atau 80). */
    public static function paperWidth(array $profile): int
    {
        return ($profile['receipt']['paper'] ?? '58mm') === '80mm' ? 80 : 58;
    }
}
