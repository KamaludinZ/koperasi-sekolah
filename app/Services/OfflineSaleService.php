<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Notification;
use App\Models\OfflineSale;
use App\Models\PosOfflineCatalog;
use App\Models\Product;
use App\Models\User;
use App\Support\PriceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Menerima penjualan dari kasir offline (sinkron belakangan), idempoten per client_ref:
 * - ditolak (tanpa transaksi) bila salinan katalog bukan milik petugas/perangkat ini atau sudah kedaluwarsa saat
 *   menjual, harga baris berbeda dari salinan katalog, atau total/bayar tidak cocok dengan hitungan ulang server
 *   (pajak & pembulatan dari salinan katalog);
 * - selain itu transaksi dibuat (sumber offline, tanggal & nomor invoice hari penjualan, shift yang terbuka pada jam
 *   itu) dan stok dikurangi sebanyak yang ada — kekurangan, tanpa shift, barang yang sudah tiada, atau kiriman
 *   terlambat ditandai "perlu dicek" untuk ditinjau admin. Setiap kiriman tersimpan di offline_sales.
 */
class OfflineSaleService
{
    public const LATE_DAYS = 3;

    public function __construct(private CheckoutService $checkout, private StockService $stock) {}

    /**
     * Validasi bentuk satu penjualan (tanpa menyimpan). @throws ValidationException
     *
     * @return array{client_ref: string, catalog_id: string, device_id: string, local_number: string, sold_at: Carbon, payment_method: string, paid: int, total: int, items: list<array{product_id: int, name: string, quantity: int, price: int}>}
     */
    public function normalize(array $sale): array
    {
        $v = validator($sale, [
            'client_ref' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'catalog_id' => ['required', 'string', 'size:26'],
            'device_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'local_number' => ['required', 'string', 'max:40'],
            'sold_at' => ['required', 'date'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(OfflineCatalogService::PAYMENT_METHODS))],
            'paid' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'total' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
        ])->validate();
        $soldAt = Carbon::parse($v['sold_at'])->setTimezone(config('app.timezone'));
        if ($soldAt->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['sold_at' => 'Waktu penjualan di masa depan — periksa jam perangkat.']);
        }

        return ['sold_at' => $soldAt] + $v;
    }

    public function ingest(User $user, array $sale): OfflineSale
    {
        $s = $this->normalize($sale);
        if ($existing = OfflineSale::where('client_ref', $s['client_ref'])->first()) {
            if ($existing->user_id !== $user->id) {
                throw ValidationException::withMessages(['client_ref' => 'Kunci penjualan sudah dipakai petugas lain.']);
            }

            return $existing; // kiriman ulang → hasil yang sama, tidak dobel
        }
        $base = [
            'client_ref' => $s['client_ref'], 'catalog_id' => $s['catalog_id'], 'user_id' => $user->id, 'device_id' => $s['device_id'],
            'local_number' => $s['local_number'], 'sold_at' => $s['sold_at'], 'payload' => $sale, 'total' => $s['total'],
            'payment_method' => $s['payment_method'], 'received_at' => now(),
        ];
        if ($reason = $this->rejection($user, $s)) {
            $sale = OfflineSale::create($base + ['status' => 'ditolak', 'reason' => $reason]);
            AuditLog::record('sale.offline_rejected', 'transaksi', "Penjualan offline {$s['local_number']} ditolak: {$reason}",
                null, ['client_ref' => $s['client_ref'], 'device_id' => $s['device_id'], 'total' => $s['total']], $s['total'], reason: $reason);
            $this->notifyProblem();

            return $sale;
        }

        $sale = DB::transaction(fn () => $this->record($user, $s, $base));
        if ($sale->status === 'perlu_dicek') {
            $this->notifyProblem();
        }

        return $sale;
    }

    /** Tandai kiriman bermasalah (perlu dicek / ditolak) sudah ditinjau dengan catatan; dicatat di audit. */
    public function review(OfflineSale $sale, User $by, string $note): void
    {
        $note = mb_substr(trim($note), 0, 300);
        $sale->update(['reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
        AuditLog::record('sale.offline_reviewed', 'transaksi', "Penjualan offline {$sale->local_number} ditinjau (".OfflineSale::STATUSES[$sale->status].')',
            $sale->transaction_id ? ['type' => 'transaction', 'id' => $sale->transaction_id, 'label' => $sale->transaction?->invoice_number] : null,
            ['offline_sale_id' => $sale->id, 'status' => $sale->status, 'flags' => array_keys($sale->flags ?? [])], action: 'diubah', reason: $note);
    }

    /** Beri tahu Admin & Pengurus bila ada kiriman offline yang perlu ditinjau (satu pemberitahuan per hari, jumlahnya diperbarui). */
    private function notifyProblem(): void
    {
        $open = OfflineSale::whereIn('status', ['perlu_dicek', 'ditolak'])->whereNull('reviewed_at')->count();
        $url = Route::has('transaksi.offline') ? route('transaksi.offline') : null;
        foreach (['admin', 'pengurus'] as $target) {
            Notification::updateOrCreate(['dedupe_key' => "offline-perlu-tinjau-{$target}-".now()->toDateString()], [
                'type' => 'sistem', 'target' => $target, 'url' => $url, 'title' => 'Penjualan offline perlu ditinjau',
                'message' => "{$open} kiriman dari kasir offline perlu dicek (stok kurang, tanpa shift, atau ditolak).",
            ]);
        }
    }

    /** Alasan penolakan (null bila sah). */
    private function rejection(User $user, array $s): ?string
    {
        $catalog = PosOfflineCatalog::find($s['catalog_id']);
        if (! $catalog || $catalog->user_id !== $user->id || $catalog->device_id !== $s['device_id']) {
            return 'Salinan katalog tidak dikenal untuk petugas/perangkat ini.';
        }
        if ($s['sold_at']->lt($catalog->issued_at->copy()->subMinutes(5)) || $s['sold_at']->gt($catalog->expires_at)) {
            return 'Waktu penjualan di luar masa berlaku salinan katalog.';
        }
        $subtotal = 0;
        foreach ($s['items'] as $line) {
            $expected = $catalog->prices[$line['product_id']] ?? $catalog->prices[(string) $line['product_id']] ?? null;
            if ($expected === null) {
                return "Barang {$line['name']} tidak ada di salinan katalog.";
            }
            if ((int) $expected !== $line['price']) {
                return "Harga {$line['name']} tidak sesuai salinan katalog.";
            }
            $subtotal += $line['price'] * $line['quantity'];
        }
        $price = PriceCalculator::compute($subtotal, $catalog->tax, $s['payment_method']);
        if ($price['payable'] !== $s['total']) {
            return 'Total tidak cocok dengan hitungan ulang server.';
        }
        if ($s['payment_method'] === 'tunai' ? $s['paid'] < $s['total'] : $s['paid'] !== $s['total']) {
            return 'Uang bayar tidak sesuai total.';
        }

        return null;
    }

    private function record(User $user, array $s, array $base): OfflineSale
    {
        $catalog = PosOfflineCatalog::findOrFail($s['catalog_id']);
        $flags = [];
        $products = Product::whereKey(array_column($s['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        foreach ($s['items'] as $l) {
            $p = $products->get($l['product_id']);
            if (! $p || ! $p->is_active) {
                $flags['barang_tiada'] = true;
            }
            $key = $l['product_id'];
            $lines[$key] ??= ['product' => $p, 'name' => $p?->name ?? $l['name'], 'price' => $l['price'], 'quantity' => 0];
            $lines[$key]['quantity'] += $l['quantity'];
        }
        $subtotal = array_sum(array_map(fn ($l) => $l['price'] * $l['quantity'], $lines));
        $price = PriceCalculator::compute($subtotal, $catalog->tax, $s['payment_method']);
        $shift = CashShift::where('opened_at', '<=', $s['sold_at'])
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $s['sold_at']))->latest('opened_at')->first();
        if (! $shift) {
            $flags['tanpa_shift'] = true;
        }
        if ($s['sold_at']->lt(now()->subDays(self::LATE_DAYS))) {
            $flags['terlambat'] = true;
        }

        $tx = $this->checkout->createWithInvoiceNumber([
            'client_ref' => $s['client_ref'], 'user_id' => $user->id, 'cash_shift_id' => $shift?->id, 'member_id' => null,
            'subtotal' => $subtotal, 'tax' => $price['tax'], 'tax_label' => ($catalog->tax['enabled'] ?? false) ? ($catalog->tax['label'] ?? 'Pajak') : null,
            'tax_rate' => ($catalog->tax['enabled'] ?? false) ? (float) ($catalog->tax['rate'] ?? 0) : 0, 'tax_included' => $price['tax_included'],
            'discount' => 0, 'rounding' => $price['rounding'], 'total' => $price['payable'], 'paid' => $s['paid'], 'change' => $s['paid'] - $price['payable'],
            'payment_method' => $s['payment_method'], 'item_count' => array_sum(array_column($lines, 'quantity')),
            'source' => 'offline', 'synced_at' => now(),
        ], $s['sold_at']);
        $tx->forceFill(['created_at' => $s['sold_at']])->save();

        $shortages = [];
        foreach ($lines as $l) {
            $tx->items()->create([
                'product_id' => $l['product']?->id, 'product_name' => $l['name'], 'price' => $l['price'],
                'cost_price' => $l['product']?->cost_price ?? 0, 'quantity' => $l['quantity'], 'subtotal' => $l['price'] * $l['quantity'],
            ]);
            if ($l['product']) {
                $r = $this->stock->decreaseAvailable($l['product'], $l['quantity'], "Penjualan offline {$tx->invoice_number} ({$s['local_number']})", $user->id);
                if ($r['shortage'] > 0) {
                    $shortages[] = ['product_id' => $l['product']->id, 'name' => $l['name'], 'shortage' => $r['shortage']];
                }
            }
        }
        if ($shortages) {
            $flags['stok_kurang'] = $shortages;
        }

        $sale = OfflineSale::create($base + [
            'status' => $flags ? 'perlu_dicek' : 'diterima', 'flags' => $flags ?: null, 'transaction_id' => $tx->id,
            'reason' => $flags ? implode('; ', array_map(fn ($f) => OfflineSale::FLAGS[$f], array_keys($flags))) : null,
        ]);
        AuditLog::record('sale.created', 'transaksi',
            "Penjualan offline {$tx->invoice_number} ({$s['local_number']}): ".count($lines).' jenis barang, '.OfflineCatalogService::PAYMENT_METHODS[$s['payment_method']],
            ['type' => 'transaction', 'id' => $tx->id, 'label' => $tx->invoice_number],
            ['offline' => true, 'local_number' => $s['local_number'], 'device_id' => $s['device_id'], 'sold_at' => $s['sold_at']->toIso8601String(),
                'items' => array_map(fn ($l) => ['name' => $l['name'], 'qty' => $l['quantity']], array_values($lines)), 'flags' => array_keys($flags) ?: null],
            $tx->total);

        return $sale;
    }
}
