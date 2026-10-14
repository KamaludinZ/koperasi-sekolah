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

    public function __construct(private StockService $stock) {}

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
            $total = array_sum(array_column($lines, 'subtotal'));

            $order = $this->createWithCode([
                'member_id' => $locked->id, 'status' => 'menunggu',
                'fulfillment' => $choice['fulfillment'], 'delivery_to' => $choice['delivery_to'],
                'payment_method' => $choice['payment_method'], 'saving_type_id' => $choice['saving_type_id'], 'payment_status' => 'belum',
                'subtotal' => $total, 'discount' => 0, 'total' => $total, 'item_count' => array_sum(array_column($lines, 'qty')),
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
        $amount = (int) round((float) $order->total);
        if ($order->payment_method === 'tabungan') {
            $before = (int) round((float) $locked->balance);
            $locked->savings()->create(['type' => SavingsTransaction::TYPE_DEPOSIT, 'amount' => $amount, 'balance_before' => $before,
                'balance_after' => $before + $amount, 'note' => "Pengembalian pesanan {$order->code}", 'source_type' => 'order', 'source_id' => $order->id, 'user_id' => $userId]);
            $locked->forceFill(['balance' => $before + $amount])->save();

            return;
        }
        $account = MemberSavingAccount::firstOrCreate(['member_id' => $locked->id, 'saving_type_id' => $order->saving_type_id], ['balance' => 0]);
        $account = MemberSavingAccount::whereKey($account->id)->lockForUpdate()->first();
        $before = (int) round((float) $account->balance);
        MemberSavingEntry::create(['member_id' => $locked->id, 'saving_type_id' => $order->saving_type_id, 'type' => 'setor', 'amount' => $amount,
            'balance_before' => $before, 'balance_after' => $before + $amount, 'note' => "Pengembalian pesanan {$order->code}",
            'source_type' => 'order', 'source_id' => $order->id, 'user_id' => $userId]);
        $account->forceFill(['balance' => $before + $amount])->save();
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

    /** Potong saldo tabungan/simpanan untuk pesanan (anggota sudah dikunci). */
    private function chargeBalance(Member $locked, Order $order): void
    {
        $amount = (int) round((float) $order->total);
        if ($order->payment_method === 'tabungan') {
            $before = (int) round((float) $locked->balance);
            if ($amount > $before) {
                throw ValidationException::withMessages(['payment_method' => 'Saldo tabungan kurang '.Rupiah::format($amount - $before).' (saldo '.Rupiah::format($before).').']);
            }
            $entry = $locked->savings()->create(['type' => SavingsTransaction::TYPE_WITHDRAWAL, 'amount' => $amount, 'balance_before' => $before,
                'balance_after' => $before - $amount, 'note' => "Pesanan {$order->code}", 'source_type' => 'order', 'source_id' => $order->id]);
            $locked->forceFill(['balance' => $before - $amount])->save();
            AuditLog::record('savings.order', 'tabungan', "Bayar pesanan {$order->code} dengan tabungan {$locked->name}: ".Rupiah::format($amount),
                ['type' => 'member', 'id' => $locked->id, 'label' => "{$locked->member_code} · {$locked->name}"],
                ['savings_id' => $entry->id, 'order_id' => $order->id, 'balance_before' => $before, 'balance_after' => $before - $amount], $amount,
                actor: PortalAuthService::actor($locked));

            return;
        }

        $account = MemberSavingAccount::where('member_id', $locked->id)->where('saving_type_id', $order->saving_type_id)->lockForUpdate()->first();
        $before = (int) round((float) ($account?->balance ?? 0));
        $type = SavingType::find($order->saving_type_id);
        if (! $account || $amount > $before) {
            throw ValidationException::withMessages(['payment_method' => "Saldo {$type->name} kurang ".Rupiah::format($amount - $before).' (saldo '.Rupiah::format($before).').']);
        }
        $entry = MemberSavingEntry::create(['member_id' => $locked->id, 'saving_type_id' => $type->id, 'type' => 'potong', 'amount' => $amount,
            'balance_before' => $before, 'balance_after' => $before - $amount, 'note' => "Pesanan {$order->code}", 'source_type' => 'order', 'source_id' => $order->id]);
        $account->forceFill(['balance' => $before - $amount])->save();
        AuditLog::record('simpanan.potong', 'simpanan', "Bayar pesanan {$order->code} dengan {$type->name} {$locked->name}: ".Rupiah::format($amount),
            ['type' => 'member', 'id' => $locked->id, 'label' => "{$locked->member_code} · {$locked->name}"],
            ['saving_type_id' => $type->id, 'entry_ids' => [$entry->id], 'order_id' => $order->id, 'balance_before' => $before, 'balance_after' => $before - $amount], $amount,
            actor: PortalAuthService::actor($locked));
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
