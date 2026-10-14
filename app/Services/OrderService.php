<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingAccount;
use App\Models\MemberSavingEntry;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\SavingsTransaction;
use App\Models\SavingType;
use App\Support\PriceCalculator;
use App\Support\Rupiah;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pesanan checkout mandiri anggota. Semua dihitung ulang di server: harga & stok dari data barang,
 * stok dicadangkan saat pesanan dibuat, dan bila bayar potong saldo, saldo tabungan/simpanan langsung
 * dipotong (dengan kunci baris). Tunai/QRIS dibayar saat pengambilan/dikonfirmasi petugas.
 */
class OrderService
{
    public const MAX_LINES = 30;

    public const MAX_QTY = 100;

    public function __construct(private StockService $stock, private SettingsService $settings) {}

    /**
     * Rincian harga pesanan dengan aturan pajak & pembulatan kasir (Pengaturan → Pajak). Pembulatan
     * "hanya tunai" berlaku untuk bayar tunai; metode lain dianggap non-tunai.
     *
     * @return array{subtotal: int, tax: int, tax_included: int, total: int, rounding: int, payable: int, tax_label: ?string}
     */
    public function quote(int $subtotal, string $paymentMethod): array
    {
        $tax = $this->settings->tax();

        return PriceCalculator::compute($subtotal, $tax, $paymentMethod === 'tunai' ? 'tunai' : 'nontunai')
            + ['tax_label' => ($tax['enabled'] ?? false) ? ($tax['label'] ?? 'Pajak') : null];
    }

    /**
     * @param  array<int, int>  $cart  [productId => qty]
     * @param  array{fulfillment: string, delivery_to?: ?string, payment_method: string, saving_type_id?: ?int, note?: ?string}  $choice
     *
     * @throws ValidationException
     */
    public function place(Member $member, array $cart, array $choice, ?string $clientRef = null): Order
    {
        if ($clientRef !== null && ($existing = Order::where('client_ref', $clientRef)->first())) {
            if ($existing->member_id !== $member->id) {
                throw ValidationException::withMessages(['client_ref' => 'Kunci pesanan tidak valid.']);
            }

            return $existing;
        }
        $choice = $this->validateChoice($member, $choice);
        $cart = array_filter(array_map('intval', $cart), fn ($q) => $q > 0);
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => 'Keranjang masih kosong.']);
        }
        if (count($cart) > self::MAX_LINES || max($cart) > self::MAX_QTY) {
            throw ValidationException::withMessages(['cart' => 'Pesanan terlalu besar; maksimal '.self::MAX_LINES.' jenis barang dan '.self::MAX_QTY.' per barang.']);
        }

        return DB::transaction(function () use ($member, $cart, $choice, $clientRef) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            if (! $locked->hasPortalAccess()) {
                throw ValidationException::withMessages(['member' => 'Akun anggota tidak aktif.']);
            }

            $products = Product::whereKey(array_keys($cart))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach ($cart as $id => $qty) {
                $p = $products->get($id);
                if (! $p || ! $p->is_active) {
                    throw ValidationException::withMessages(['cart' => 'Ada barang yang sudah tidak dijual. Perbarui keranjang Anda.']);
                }
                if ($p->stock < $qty) {
                    throw ValidationException::withMessages(['cart' => "Stok {$p->name} tinggal {$p->stock} {$p->unit}."]);
                }
                $price = (int) round((float) $p->price);
                $lines[] = ['product' => $p, 'qty' => $qty, 'price' => $price, 'subtotal' => $price * $qty];
            }
            $subtotal = array_sum(array_column($lines, 'subtotal'));
            $price = $this->quote($subtotal, $choice['payment_method']);
            $total = $price['payable'];

            $order = $this->createWithCode([
                'member_id' => $locked->id, 'status' => 'menunggu',
                'fulfillment' => $choice['fulfillment'], 'delivery_to' => $choice['delivery_to'],
                'payment_method' => $choice['payment_method'], 'saving_type_id' => $choice['saving_type_id'], 'payment_status' => 'belum',
                'subtotal' => $subtotal, 'discount' => 0, 'tax' => $price['tax'], 'tax_included' => $price['tax_included'], 'rounding' => $price['rounding'],
                'total' => $total, 'item_count' => array_sum(array_column($lines, 'qty')),
                'note' => $choice['note'], 'client_ref' => $clientRef,
            ]);
            foreach ($lines as $l) {
                $order->items()->create(['product_id' => $l['product']->id, 'product_name' => $l['product']->name,
                    'quantity' => $l['qty'], 'price' => $l['price'], 'subtotal' => $l['subtotal']]);
                $this->stock->decrease($l['product'], $l['qty'], "Pesanan {$order->code}");
            }

            if (in_array($order->payment_method, ['tabungan', 'simpanan'], true)) {
                $this->chargeBalance($locked, $order);
                $order->forceFill(['payment_status' => 'lunas', 'paid_at' => now()])->save();
            }
            $this->log($order, null, 'menunggu', 'Pesanan dibuat dari portal anggota', null, $locked->name);

            AuditLog::record('order.placed', 'pesanan',
                "Pesanan {$order->code} dari {$locked->name}: {$order->item_count} barang, ".Rupiah::format($total).' · '.Order::PAYMENTS[$order->payment_method]
                    .' · '.Order::FULFILLMENTS[$order->fulfillment],
                ['type' => 'order', 'id' => $order->id, 'label' => $order->code],
                ['member_id' => $locked->id, 'payment_status' => $order->payment_status], $total,
                actor: PortalAuthService::actor($locked));

            return $order->load('items');
        });
    }

    /**
     * Batalkan pesanan yang belum selesai: stok dikembalikan dan saldo yang sudah dipotong dikembalikan
     * (mutasi setor tabungan / simpanan dengan asal `order`). Anggota hanya boleh membatalkan saat
     * masih `menunggu`; petugas ($userId terisi) boleh sampai `siap`.
     *
     * @throws ValidationException
     */
    public function cancel(Order $order, string $reason, ?int $userId = null, ?string $actorName = null): Order
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan pembatalan (3–200 karakter).']);
        }

        return DB::transaction(function () use ($order, $reason, $userId, $actorName) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $allowed = $userId ? ['menunggu', 'diproses', 'siap'] : ['menunggu'];
            if (! in_array($order->status, $allowed, true)) {
                throw ValidationException::withMessages(['reason' => $userId
                    ? 'Pesanan '.mb_strtolower(Order::STATUSES[$order->status]).' tidak bisa dibatalkan.'
                    : 'Pesanan sudah diproses petugas; hubungi koperasi untuk membatalkan.']);
            }
            $member = Member::withTrashed()->whereKey($order->member_id)->lockForUpdate()->firstOrFail();

            foreach ($order->items as $item) {
                if ($product = Product::withTrashed()->find($item->product_id)) {
                    $this->stock->increase($product, $item->quantity, "Batal pesanan {$order->code}", $userId);
                }
            }
            if ($order->payment_status === 'lunas' && in_array($order->payment_method, ['tabungan', 'simpanan'], true)) {
                $this->refundBalance($member, $order, $userId);
                $order->payment_status = 'dikembalikan';
            }
            $from = $order->status;
            $order->forceFill(['status' => 'dibatalkan', 'cancel_reason' => $reason])->save();
            $actor = $actorName ?? $member->name;
            $this->log($order, $from, 'dibatalkan', $reason, $userId, $actor);

            AuditLog::record('order.cancelled', 'pesanan', "Pesanan {$order->code} dibatalkan oleh {$actor}: {$reason}",
                ['type' => 'order', 'id' => $order->id, 'label' => $order->code], ['from' => $from, 'payment_status' => $order->payment_status],
                (int) round((float) $order->total), actor: $userId ? null : PortalAuthService::actor($member), reason: $reason);

            return $order->refresh();
        });
    }

    private function refundBalance(Member $locked, Order $order, ?int $userId): void
    {
        app(BalanceDeductionService::class)->refund($locked, $order->payment_method === 'tabungan' ? null : $order->saving_type_id,
            (int) round((float) $order->total), "Pengembalian pesanan {$order->code}", ['order', $order->id], $userId);
    }

    /**
     * Bukti potong saldo pesanan dari buku mutasi (tabungan / simpanan): pemotongan & pengembalian bila dibatalkan.
     *
     * @return array{source: string, amount: int, before: int, after: int, at: \Illuminate\Support\Carbon, refund: ?array{amount: int, after: int, at: \Illuminate\Support\Carbon}}|null
     */
    public static function deductionFor(Order $order): ?array
    {
        if ($order->payment_method === 'tabungan') {
            $rows = SavingsTransaction::where('source_type', 'order')->where('source_id', $order->id)->orderBy('id')->get();
            $out = $rows->firstWhere('type', SavingsTransaction::TYPE_WITHDRAWAL);
            $back = $rows->firstWhere('type', SavingsTransaction::TYPE_DEPOSIT);
            $source = 'Tabungan';
        } elseif ($order->payment_method === 'simpanan') {
            $rows = MemberSavingEntry::where('source_type', 'order')->where('source_id', $order->id)->orderBy('id')->get();
            $out = $rows->firstWhere('type', 'potong');
            $back = $rows->firstWhere('type', 'setor');
            $source = $order->savingType?->name ?? 'Simpanan';
        } else {
            return null;
        }
        if (! $out) {
            return null;
        }

        return ['source' => $source, 'amount' => (int) round((float) $out->amount), 'before' => (int) round((float) $out->balance_before),
            'after' => (int) round((float) $out->balance_after), 'at' => $out->created_at,
            'refund' => $back ? ['amount' => (int) round((float) $back->amount), 'after' => (int) round((float) $back->balance_after), 'at' => $back->created_at] : null];
    }

    /** Ringkasan pesanan untuk tampilan portal/petugas. */
    public static function toArray(Order $o): array
    {
        return [
            'id' => $o->id, 'code' => $o->code, 'status' => $o->status, 'status_label' => Order::STATUSES[$o->status] ?? $o->status,
            'fulfillment' => $o->fulfillment, 'fulfillment_label' => Order::FULFILLMENTS[$o->fulfillment] ?? $o->fulfillment, 'delivery_to' => $o->delivery_to,
            'payment_method' => $o->payment_method, 'payment_label' => Order::PAYMENTS[$o->payment_method] ?? $o->payment_method,
            'payment_status' => $o->payment_status, 'payment_status_label' => Order::PAYMENT_STATUSES[$o->payment_status] ?? $o->payment_status,
            'saving_type' => $o->savingType?->name, 'total' => (int) round((float) $o->total), 'item_count' => $o->item_count,
            'note' => $o->note, 'cancel_reason' => $o->cancel_reason, 'created_at' => $o->created_at,
        ];
    }

    /**
     * Lunasi pesanan yang belum dibayar (tunai/QRIS) dengan potong saldo tabungan ($savingTypeId null) atau
     * simpanan. Tanpa persetujuan pengurus; saldo kurang → ditolak. Total dihitung ulang sebagai non-tunai.
     *
     * @throws ValidationException
     */
    public function payWithBalance(Order $order, Member $member, ?int $savingTypeId): Order
    {
        return DB::transaction(function () use ($order, $member, $savingTypeId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->member_id !== $member->id) {
                throw ValidationException::withMessages(['order' => 'Pesanan tidak ditemukan.']);
            }
            if ($order->payment_status !== 'belum' || in_array($order->status, ['selesai', 'dibatalkan'], true)) {
                throw ValidationException::withMessages(['order' => 'Pesanan ini sudah dibayar atau tidak bisa dibayar lagi.']);
            }
            // Pembulatan "hanya tunai" tidak berlaku untuk potong saldo.
            $price = $this->quote((int) round((float) $order->subtotal) - (int) round((float) $order->discount), 'tabungan');
            $order->forceFill(['payment_method' => $savingTypeId ? 'simpanan' : 'tabungan', 'saving_type_id' => $savingTypeId,
                'tax' => $price['tax'], 'tax_included' => $price['tax_included'], 'rounding' => $price['rounding'], 'total' => $price['payable']])->save();
            $this->chargeBalance($member, $order);
            $order->forceFill(['payment_status' => 'lunas', 'paid_at' => now()])->save();
            $this->log($order, $order->status, $order->status, 'Dibayar dengan potong saldo '.($savingTypeId ? SavingType::find($savingTypeId)?->name : 'tabungan'), null, $member->name);

            return $order->refresh();
        });
    }

    /**
     * Petugas mencatat pembayaran pesanan tunai/QRIS (saat diambil/diantar). Tunai masuk laci shift yang
     * sedang buka. Pesanan potong saldo sudah lunas sejak dibuat.
     *
     * @throws ValidationException
     */
    public function markPaid(Order $order, string $method, \App\Models\User $by): Order
    {
        if (! in_array($method, ['tunai', 'qris'], true)) {
            throw ValidationException::withMessages(['metode' => 'Pilih tunai atau QRIS.']);
        }

        return DB::transaction(function () use ($order, $method, $by) {
            $order = Order::with('member')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status !== 'belum' || $order->status === 'dibatalkan') {
                throw ValidationException::withMessages(['order' => 'Pesanan ini sudah dibayar atau dibatalkan.']);
            }
            if (! in_array($order->payment_method, ['tunai', 'qris'], true)) {
                throw ValidationException::withMessages(['order' => 'Pesanan ini dibayar dengan potong saldo.']);
            }
            // Pembulatan "hanya tunai" ikut cara bayar yang sebenarnya.
            $price = $this->quote((int) round((float) $order->subtotal) - (int) round((float) $order->discount), $method);
            $order->forceFill(['payment_method' => $method, 'tax' => $price['tax'], 'tax_included' => $price['tax_included'],
                'rounding' => $price['rounding'], 'total' => $price['payable'], 'payment_status' => 'lunas', 'paid_at' => now()])->save();
            $total = (int) round((float) $order->total);

            if ($method === 'tunai' && ($shift = \App\Models\CashShift::open()->lockForUpdate()->first())) {
                $shift->movements()->create(['type' => \App\Models\CashMovement::TYPE_IN, 'category' => 'lainnya', 'amount' => $total,
                    'reason' => "Pesanan portal {$order->code} · {$order->member->name}", 'user_id' => $by->id]);
            }
            $this->log($order, $order->status, $order->status, 'Dibayar '.($method === 'tunai' ? 'tunai' : 'QRIS/transfer').' · '.Rupiah::format($total), $by->id, $by->name);
            AuditLog::record('order.paid', 'pesanan', "Pesanan {$order->code} dibayar {$method}: ".Rupiah::format($total),
                ['type' => 'order', 'id' => $order->id, 'label' => $order->code], ['method' => $method], $total);

            return $order->refresh();
        });
    }

    /** Saldo yang bisa dipakai bayar: tabungan + jenis simpanan aktif yang boleh ditarik. */
    public function balanceOptions(Member $member): array
    {
        $accounts = MemberSavingAccount::where('member_id', $member->id)->pluck('balance', 'saving_type_id');
        $types = SavingType::active()->where('withdrawable', true)->ordered()->get()
            ->map(fn (SavingType $t) => ['id' => $t->id, 'name' => $t->name, 'balance' => (int) round((float) ($accounts[$t->id] ?? 0))])
            ->filter(fn ($t) => $t['balance'] > 0)->values()->all();

        return ['tabungan' => (int) round((float) $member->balance), 'simpanan' => $types];
    }

    private function validateChoice(Member $member, array $c): array
    {
        $errors = [];
        $fulfillment = $c['fulfillment'] ?? '';
        $payment = $c['payment_method'] ?? '';
        $deliveryTo = trim((string) ($c['delivery_to'] ?? ''));
        $note = trim((string) ($c['note'] ?? ''));
        if (! array_key_exists($fulfillment, Order::FULFILLMENTS)) {
            $errors['fulfillment'] = 'Pilih cara pengambilan.';
        } elseif ($fulfillment === 'antar' && (mb_strlen($deliveryTo) < 2 || mb_strlen($deliveryTo) > 100)) {
            $errors['delivery_to'] = 'Tulis tujuan antar (mis. Kelas 7A atau Ruang Guru).';
        }
        if (! array_key_exists($payment, Order::PAYMENTS)) {
            $errors['payment_method'] = 'Pilih cara bayar.';
        }
        $typeId = null;
        if ($payment === 'simpanan') {
            $typeId = (int) ($c['saving_type_id'] ?? 0);
            $type = SavingType::find($typeId);
            if (! $type || ! $type->is_active || ! $type->withdrawable) {
                $errors['saving_type_id'] = 'Pilih simpanan yang boleh dipakai untuk bayar.';
            }
        }
        if (mb_strlen($note) > 200) {
            $errors['note'] = 'Catatan maksimal 200 karakter.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['fulfillment' => $fulfillment, 'delivery_to' => $fulfillment === 'antar' ? $deliveryTo : null,
            'payment_method' => $payment, 'saving_type_id' => $typeId, 'note' => $note !== '' ? $note : null];
    }

    /** Potong saldo tabungan/simpanan untuk pesanan lewat BalanceDeductionService (atomik, tanpa persetujuan). */
    private function chargeBalance(Member $locked, Order $order): void
    {
        app(BalanceDeductionService::class)->deduct($locked, $order->payment_method === 'tabungan' ? null : $order->saving_type_id,
            (int) round((float) $order->total), "Pesanan {$order->code}", ['order', $order->id], null, PortalAuthService::actor($locked));
    }

    public function log(Order $order, ?string $from, string $to, ?string $note, ?int $userId, string $actor): void
    {
        OrderStatusLog::create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note,
            'user_id' => $userId, 'actor_name' => mb_substr($actor, 0, 100), 'created_at' => now()]);
    }

    /** Nomor pesanan berurutan per hari: ORD-YYYYMMDD-0001 (coba nomor berikutnya bila bentrok). */
    private function createWithCode(array $attributes): Order
    {
        $prefix = 'ORD-'.now()->format('Ymd').'-';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = Order::where('code', 'like', $prefix.'%')->max('code');
            $next = ($last ? (int) substr($last, strlen($prefix)) : 0) + 1 + $attempt;
            try {
                return DB::transaction(fn () => Order::create([...$attributes, 'code' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT)]));
            } catch (UniqueConstraintViolationException) {
                // nomor sudah dipakai pesanan lain — ulangi
            }
        }

        throw new \RuntimeException('Gagal membuat nomor pesanan unik.');
    }
}
