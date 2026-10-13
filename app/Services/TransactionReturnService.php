<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\MemberCredit;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur barang dari transaksi kasir.
 * - requestReturn(): pengajuan RTR- (status menunggu). Syarat: transaksi belum dibatalkan, paling lambat
 *   LIMIT_DAYS hari, tidak ada pengajuan lain yang menunggu, jumlah ≤ dibeli − sudah diretur, cara
 *   pengembalian sesuai metode bayar. Nilai per unit = harga × (total ÷ subtotal), yaitu setelah diskon,
 *   pajak & pembulatan, dan tidak melebihi sisa nilai transaksi yang belum diretur.
 * - approve(): (Admin/Pengurus, bukan pengaju) kembalikan stok bila barang layak jual, kembalikan dana
 *   (tunai keluar dari laci shift terbuka; tabungan disetor kembali; bon mengurangi bon; QRIS dicatat),
 *   tambah transactions.returned_amount.
 * - reject(): tolak dengan alasan.
 * Semua langkah tercatat di audit (return.*).
 */
class TransactionReturnService
{
    public const LIMIT_DAYS = 3;

    public function __construct(private StockService $stock, private SavingsPaymentService $savings) {}

    /** Cara pengembalian dana yang boleh dipilih untuk transaksi ini. */
    public static function refundOptions(Transaction $t): array
    {
        $keys = match ($t->payment_method) {
            'tabungan' => ['tabungan', 'tunai'],
            'bon' => ['bon'],
            'qris' => ['qris', 'tunai'],
            default => ['tunai'],
        };
        if ($t->member_id && ! in_array('tabungan', $keys, true) && $t->payment_method !== 'bon') {
            $keys[] = 'tabungan';
        }

        return array_intersect_key(TransactionReturn::REFUND_METHODS, array_flip($keys));
    }

    /** Batas waktu pengajuan sudah lewat? */
    public static function expired(Transaction $t): bool
    {
        return $t->created_at->lt(now()->subDays(self::LIMIT_DAYS)->startOfDay());
    }

    /** Rasio nilai bersih per rupiah harga (total ÷ subtotal). */
    public static function ratio(Transaction $t): float
    {
        $subtotal = (float) $t->subtotal;

        return $subtotal > 0 ? max(0, (float) $t->total / $subtotal) : 1.0;
    }

    /**
     * @param  array<int, int>  $quantities  transaction_item_id → jumlah diretur
     *
     * @throws ValidationException
     */
    public function requestReturn(Transaction $transaction, array $quantities, string $reasonKey, bool $restock, string $refundMethod, ?string $note, ?User $by): TransactionReturn
    {
        if (! array_key_exists($reasonKey, TransactionReturn::RETURN_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Pilih alasan retur.']);
        }
        if ($reasonKey === 'lainnya' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Jelaskan alasan retur.']);
        }

        return DB::transaction(function () use ($transaction, $quantities, $reasonKey, $restock, $refundMethod, $note, $by) {
            $t = Transaction::with('items')->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($t);
            if (! array_key_exists($refundMethod, self::refundOptions($t))) {
                throw ValidationException::withMessages(['refund_method' => 'Cara pengembalian ini tidak tersedia untuk transaksi ini.']);
            }

            $returned = $t->returnedQuantities();
            $ratio = self::ratio($t);
            $lines = [];
            foreach ($t->items as $item) {
                $qty = (int) ($quantities[$item->id] ?? 0);
                if ($qty < 0) {
                    throw ValidationException::withMessages(["qty.{$item->id}" => 'Tidak boleh minus.']);
                }
                $left = $item->quantity - ($returned[$item->id] ?? 0);
                if ($qty > $left) {
                    throw ValidationException::withMessages(["qty.{$item->id}" => "Maksimal {$left}."]);
                }
                if ($qty > 0) {
                    $unit = (int) round((float) $item->price * $ratio);
                    $lines[] = ['item' => $item, 'qty' => $qty, 'unit' => $unit, 'amount' => $unit * $qty];
                }
            }
            if (array_diff_key($quantities, $t->items->keyBy('id')->all())) {
                throw ValidationException::withMessages(['qty' => 'Ada barang yang bukan bagian dari transaksi ini.']);
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['qty' => 'Isi jumlah barang yang diretur.']);
            }

            $refundable = max(0, (int) $t->total - $t->returned_amount - $this->pendingAmount($t));
            $amount = min(array_sum(array_column($lines, 'amount')), $refundable);

            $r = $this->createWithNumber('retur', [
                'transaction_id' => $t->id,
                'reason_key' => $reasonKey,
                'reason' => TransactionReturn::RETURN_REASONS[$reasonKey],
                'note' => filled($note) ? mb_substr(trim($note), 0, 200) : null,
                'restock' => $restock,
                'refund_method' => $refundMethod,
                'amount' => $amount,
                'quantity' => array_sum(array_column($lines, 'qty')),
                'requested_by' => $by?->id,
                'requested_at' => now(),
            ]);
            foreach ($lines as $l) {
                $r->items()->create([
                    'transaction_item_id' => $l['item']->id, 'product_id' => $l['item']->product_id, 'product_name' => $l['item']->product_name,
                    'quantity' => $l['qty'], 'unit_price' => $l['unit'], 'amount' => $l['amount'],
                ]);
            }

            AuditLog::record('return.requested', 'transaksi',
                "Pengajuan retur {$r->number} untuk {$t->invoice_number}: {$r->quantity} barang, Rp".number_format($amount, 0, ',', '.')." ({$r->reason})",
                ['type' => 'transaction', 'id' => $t->id, 'label' => $t->invoice_number],
                ['return_id' => $r->id, 'items' => array_map(fn ($l) => ['name' => $l['item']->product_name, 'qty' => $l['qty']], $lines),
                    'restock' => $restock, 'refund_method' => $refundMethod, 'note' => $r->note],
                $amount);

            return $r->load('items');
        });
    }

    /** @throws ValidationException */
    public function approve(TransactionReturn $return, User $by, ?string $note = null): TransactionReturn
    {
        return DB::transaction(function () use ($return, $by, $note) {
            $r = $this->lockPending($return, $by);
            $t = Transaction::whereKey($r->transaction_id)->lockForUpdate()->firstOrFail();

            if ($r->restock) {
                foreach ($r->items as $line) {
                    $product = $line->product_id ? Product::withTrashed()->find($line->product_id) : null;
                    if ($product) {
                        $this->stock->increase($product, $line->quantity, "Retur {$r->number} ({$t->invoice_number})", $by->id);
                    }
                }
            }
            $this->refund($r, $t, $by);

            $t->forceFill(['returned_amount' => min((int) $t->total, $t->returned_amount + $r->amount)])->save();
            $r->forceFill(['status' => 'disetujui', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => filled($note) ? mb_substr(trim($note), 0, 200) : null])->save();

            AuditLog::record('return.approved', 'transaksi',
                "Retur {$r->number} disetujui: Rp".number_format($r->amount, 0, ',', '.').' dikembalikan ('.mb_strtolower($r->refundLabel()).')'
                    .($r->restock ? ", {$r->quantity} barang kembali ke stok" : ', barang tidak kembali ke stok'),
                ['type' => 'transaction', 'id' => $t->id, 'label' => $t->invoice_number],
                ['return_id' => $r->id, 'refund_method' => $r->refund_method, 'restock' => $r->restock, 'note' => $r->decision_note],
                $r->amount);

            return $r->fresh(['items', 'transaction']);
        });
    }

    /** @throws ValidationException */
    public function reject(TransactionReturn $return, User $by, string $note): TransactionReturn
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['decision_note' => 'Tulis alasan penolakan (min. 5 karakter).']);
        }

        return DB::transaction(function () use ($return, $by, $note) {
            $r = $this->lockPending($return, $by);
            $r->forceFill(['status' => 'ditolak', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 200)])->save();

            AuditLog::record('return.rejected', 'transaksi', ($r->isVoid() ? 'Pembatalan' : 'Retur')." {$r->number} ditolak: {$note}",
                ['type' => 'transaction', 'id' => $r->transaction_id, 'label' => $r->transaction->invoice_number],
                ['return_id' => $r->id, 'note' => $note], $r->amount);

            return $r->fresh(['items', 'transaction']);
        });
    }

    /** @return array<string, mixed> bentuk data pengajuan untuk API & halaman */
    public static function toArray(TransactionReturn $r): array
    {
        $r->loadMissing(['items', 'transaction.user:id,name', 'requester:id,name', 'decider:id,name']);
        $t = $r->transaction;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind,
            'kind_label' => $r->kindLabel(),
            'status' => $r->status,
            'status_label' => $r->statusLabel(),
            'transaction' => ['id' => $t->id, 'invoice' => $t->invoice_number, 'total' => (int) $t->total, 'method' => $t->payment_method,
                'created_at' => $t->created_at->toDateTimeString(), 'cashier' => $t->user?->name ?? 'Kasir'],
            'items' => $r->items->map(fn ($i) => ['transaction_item_id' => $i->transaction_item_id, 'product_id' => $i->product_id, 'name' => $i->product_name,
                'qty' => $i->quantity, 'unit_price' => $i->unit_price, 'amount' => $i->amount])->all(),
            'qty' => $r->quantity,
            'amount' => $r->amount,
            'refund_method' => $r->refund_method,
            'refund_label' => $r->refundLabel(),
            'restock' => $r->restock,
            'reason_key' => $r->reason_key,
            'reason' => $r->reason,
            'note' => $r->note,
            'requested_by' => $r->requester?->name,
            'requested_by_id' => $r->requested_by,
            'requested_at' => $r->requested_at->toDateTimeString(),
            'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toDateTimeString(),
            'decision_note' => $r->decision_note,
        ];
    }

    /** Transaksi masih bisa diajukan retur/pembatalan. */
    private function assertOpen(Transaction $t): void
    {
        if ($t->isVoided()) {
            throw ValidationException::withMessages(['transaction' => 'Transaksi ini sudah dibatalkan.']);
        }
        if (self::expired($t)) {
            throw ValidationException::withMessages(['transaction' => 'Transaksi ini sudah lewat batas '.self::LIMIT_DAYS.' hari.']);
        }
        if ($open = $t->pendingReturn()->first()) {
            throw ValidationException::withMessages(['transaction' => "Transaksi ini masih punya pengajuan {$open->number} yang menunggu."]);
        }
    }

    private function pendingAmount(Transaction $t): int
    {
        return (int) $t->returns()->pending()->sum('amount');
    }

    /** Kunci pengajuan menunggu & pastikan pemutus bukan pengaju. */
    private function lockPending(TransactionReturn $return, User $by): TransactionReturn
    {
        $r = TransactionReturn::with(['items', 'transaction'])->whereKey($return->id)->lockForUpdate()->firstOrFail();
        if (! $r->isPending()) {
            throw ValidationException::withMessages(['status' => "Pengajuan {$r->number} sudah diputus."]);
        }
        if ($r->requested_by !== null && $r->requested_by === $by->id) {
            throw ValidationException::withMessages(['decision_note' => 'Pengajuan yang Anda buat sendiri harus diputus Admin/Pengurus lain.']);
        }

        return $r;
    }

    /** Kembalikan dana sesuai cara pengembalian. */
    private function refund(TransactionReturn $r, Transaction $t, User $by): void
    {
        $amount = $r->amount;
        if ($amount <= 0) {
            return;
        }
        $label = ($r->isVoid() ? 'Pembatalan' : 'Retur')." {$r->number} ({$t->invoice_number})";

        if ($r->refund_method === 'tabungan') {
            $this->savings->refund($t->member_id, $amount, 'return', $r->id, $label, $by->id);

            return;
        }
        if ($r->refund_method === 'bon') {
            $credit = MemberCredit::where('transaction_id', $t->id)->lockForUpdate()->first();
            $cut = $credit ? min($amount, $credit->remaining()) : 0;
            if ($cut > 0) {
                $newAmount = $credit->amount - $cut;
                $credit->forceFill($newAmount <= $credit->paid && $credit->paid === 0
                    ? ['status' => 'dibatalkan'] // bon belum dibayar & seluruhnya dikembalikan
                    : ['amount' => $newAmount])->save();
                $credit->syncPaid();
            }
            $amount -= $cut; // sisanya (bon sudah dibayar) dikembalikan tunai
            if ($amount <= 0) {
                return;
            }
        }
        if ($r->refund_method === 'qris') {
            return; // dikembalikan lewat transfer di luar aplikasi; tercatat di audit
        }

        // Tunai: uang keluar dari laci shift yang sedang buka.
        if ($shift = CashShift::open()->lockForUpdate()->first()) {
            if ($amount > $shift->expectedCash()) {
                throw ValidationException::withMessages(['amount' => 'Uang di laci tidak cukup untuk pengembalian (seharusnya Rp'.number_format($shift->expectedCash(), 0, ',', '.').').']);
            }
            $shift->movements()->create(['type' => CashMovement::TYPE_OUT, 'category' => 'lainnya', 'amount' => $amount, 'reason' => "Pengembalian {$label}", 'user_id' => $by->id]);
        }
    }

    /** Nomor RTR-/BTL-YYYYMMDD-NNNN berurutan per hari dan jenis. */
    private function createWithNumber(string $kind, array $attributes): TransactionReturn
    {
        $prefix = TransactionReturn::PREFIXES[$kind].'-'.now()->format('Ymd').'-';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = TransactionReturn::where('number', 'like', $prefix.'%')->max('number');
            $next = ($last ? (int) substr($last, strlen($prefix)) : 0) + 1 + $attempt;
            try {
                return DB::transaction(fn () => TransactionReturn::create([...$attributes, 'kind' => $kind, 'number' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT)]));
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'number')) {
                    throw ValidationException::withMessages(['transaction' => 'Transaksi ini masih punya pengajuan yang menunggu.']);
                }
            }
        }

        throw new \RuntimeException('Gagal membuat nomor pengajuan unik.');
    }
}
