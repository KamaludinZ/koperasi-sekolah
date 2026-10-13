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
 *   limitDays() hari (pengaturan), tidak ada pengajuan lain yang menunggu, jumlah ≤ dibeli − sudah diretur, cara
 *   pengembalian sesuai metode bayar. Nilai per unit = harga × (total ÷ subtotal), yaitu setelah diskon,
 *   pajak & pembulatan, dan tidak melebihi sisa nilai transaksi yang belum diretur.
 * - approve(): (Admin/Pengurus, bukan pengaju) kembalikan stok bila barang layak jual, kembalikan dana
 *   (tunai keluar dari laci shift terbuka; tabungan disetor kembali; bon mengurangi bon; QRIS dicatat),
 *   tambah transactions.returned_amount.
 * - requestVoid(): pengajuan BTL- untuk membatalkan seluruh sisa transaksi (barang kembali ke stok, dana
 *   dikembalikan dengan metode bayar semula). Aturan nilai batas (pengaturan `returns`): pembatalan oleh
 *   kasir transaksi itu sendiri, bernilai ≤ void_auto_max dan diajukan ≤ void_auto_minutes menit setelah
 *   transaksi, langsung disetujui otomatis; selain itu menunggu Admin/Pengurus.
 * - Pembatalan yang disetujui menandai transaksi voided_at/voided_by/void_reason dan melepas kuota kupon.
 * - reject(): tolak dengan alasan.
 * Semua langkah tercatat di audit (return.* / void.*).
 */
class TransactionReturnService
{
    /** Batas hari pengajuan retur/pembatalan (pengaturan `returns.limit_days`). */
    public static function limitDays(): int
    {
        return (int) app(SettingsService::class)->returns()['limit_days'];
    }

    public function __construct(private StockService $stock, private SavingsPaymentService $savings, private CouponService $coupons) {}

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
        return $t->created_at->lt(now()->subDays(self::limitDays())->startOfDay());
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

            self::audit('return.requested', "Pengajuan retur {$r->number} untuk {$t->invoice_number}: {$r->quantity} barang, Rp".number_format($amount, 0, ',', '.')." ({$r->reason})",
                $r, $t, ['items' => array_map(fn ($l) => ['name' => $l['item']->product_name, 'qty' => $l['qty']], $lines)],
                'dibuat', null, self::snapshot($r), $r->note ? "{$r->reason}: {$r->note}" : $r->reason);

            return $r->load('items');
        });
    }

    /** @throws ValidationException */
    public function approve(TransactionReturn $return, User $by, ?string $note = null): TransactionReturn
    {
        return DB::transaction(function () use ($return, $by, $note) {
            return $this->apply($this->lockPending($return, $by), $by->id, $note);
        });
    }

    /**
     * Ajukan pembatalan seluruh sisa transaksi.
     *
     * @throws ValidationException
     */
    public function requestVoid(Transaction $transaction, string $reasonKey, ?string $note, ?User $by): TransactionReturn
    {
        if (! array_key_exists($reasonKey, TransactionReturn::VOID_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Pilih alasan pembatalan.']);
        }
        if ($reasonKey === 'lainnya' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'Jelaskan alasan pembatalan (min. 5 karakter).']);
        }

        return DB::transaction(function () use ($transaction, $reasonKey, $note, $by) {
            $t = Transaction::with('items')->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($t);

            $returned = $t->returnedQuantities();
            $ratio = self::ratio($t);
            $lines = [];
            foreach ($t->items as $item) {
                $left = $item->quantity - ($returned[$item->id] ?? 0);
                if ($left > 0) {
                    $unit = (int) round((float) $item->price * $ratio);
                    $lines[] = ['item' => $item, 'qty' => $left, 'unit' => $unit, 'amount' => $unit * $left];
                }
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['transaction' => 'Semua barang transaksi ini sudah diretur.']);
            }
            $amount = max(0, (int) $t->total - $t->returned_amount);

            $r = $this->createWithNumber('batal', [
                'transaction_id' => $t->id,
                'reason_key' => $reasonKey,
                'reason' => TransactionReturn::VOID_REASONS[$reasonKey],
                'note' => filled($note) ? mb_substr(trim($note), 0, 200) : null,
                'restock' => true,
                'refund_method' => $t->payment_method === 'qris' ? 'qris' : $t->payment_method,
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

            self::audit('void.requested', "Pengajuan pembatalan {$r->number} untuk {$t->invoice_number}: Rp".number_format($amount, 0, ',', '.')." ({$r->reason})",
                $r, $t, ['items' => array_map(fn ($l) => ['name' => $l['item']->product_name, 'qty' => $l['qty']], $lines)],
                'dibuat', null, self::snapshot($r), $r->note ? "{$r->reason}: {$r->note}" : $r->reason);

            if ($auto = self::autoApproval($t, $amount, $by)) {
                return $this->apply($r->load(['items', 'transaction']), null, $auto);
            }

            return $r->load('items');
        });
    }

    /**
     * Aturan nilai batas: alasan persetujuan otomatis, atau null bila perlu persetujuan Admin/Pengurus.
     */
    public static function autoApproval(Transaction $t, int $amount, ?User $by): ?string
    {
        $s = app(SettingsService::class)->returns();
        $max = (int) $s['void_auto_max'];
        $minutes = (int) $s['void_auto_minutes'];
        if ($max <= 0 || ! $by || $t->user_id !== $by->id || $amount > $max || $t->created_at->lt(now()->subMinutes($minutes))) {
            return null;
        }

        return 'Disetujui otomatis: ≤ Rp'.number_format($max, 0, ',', '.')." dan ≤ {$minutes} menit oleh kasir yang sama.";
    }

    /** Terapkan persetujuan: stok, pengembalian dana, penanda transaksi, status, audit. */
    private function apply(TransactionReturn $r, ?int $userId, ?string $note): TransactionReturn
    {
        $t = Transaction::whereKey($r->transaction_id)->lockForUpdate()->firstOrFail();
        $what = $r->isVoid() ? 'Pembatalan' : 'Retur';

        if ($r->restock) {
            foreach ($r->items as $line) {
                $product = $line->product_id ? Product::withTrashed()->find($line->product_id) : null;
                if ($product) {
                    $this->stock->increase($product, $line->quantity, "{$what} {$r->number} ({$t->invoice_number})", $userId);
                }
            }
        }
        $this->refund($r, $t, $userId);

        $update = ['returned_amount' => min((int) $t->total, $t->returned_amount + $r->amount)];
        if ($r->isVoid()) {
            $update += ['voided_at' => now(), 'voided_by' => $userId ?? $r->requested_by, 'void_reason' => mb_substr($r->reason.($r->note ? ': '.$r->note : ''), 0, 200)];
            foreach ($t->discounts()->whereNotNull('coupon_id')->pluck('coupon_id') as $couponId) {
                $this->coupons->release($couponId); // kuota kupon kembali
            }
        }
        $t->forceFill($update)->save();
        $r->forceFill(['status' => 'disetujui', 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => filled($note) ? mb_substr(trim($note), 0, 200) : null])->save();

        self::audit($r->isVoid() ? 'void.approved' : 'return.approved',
            "{$what} {$r->number} disetujui".($userId ? '' : ' otomatis').': Rp'.number_format($r->amount, 0, ',', '.').' dikembalikan ('.mb_strtolower($r->refundLabel()).')'
                .($r->restock ? ", {$r->quantity} barang kembali ke stok" : ', barang tidak kembali ke stok').($r->isVoid() ? "; transaksi {$t->invoice_number} dibatalkan" : ''),
            $r, $t, ['auto' => $userId === null, 'transaction_voided' => $r->isVoid(), 'returned_amount' => $t->returned_amount],
            'diubah', ['status' => 'menunggu'], ['status' => 'disetujui', 'decided_by' => $userId ? $r->decider?->name : 'Otomatis'], $r->decision_note);

        return $r->fresh(['items', 'transaction']);
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

            self::audit($r->isVoid() ? 'void.rejected' : 'return.rejected', ($r->isVoid() ? 'Pembatalan' : 'Retur')." {$r->number} ditolak: {$note}",
                $r, $r->transaction, [], 'diubah', ['status' => 'menunggu'], ['status' => 'ditolak', 'decided_by' => $by->name], $note);

            return $r->fresh(['items', 'transaction']);
        });
    }

    /**
     * Daftar pengajuan terbaru dulu.
     *
     * @param  array{status?: ?string, kind?: ?string, search?: ?string, requested_by?: ?int, decided?: bool, from?: mixed, to?: mixed}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 300): array
    {
        return $this->query($filters)->latest('requested_at')->latest('id')->limit($limit)->get()
            ->map(fn (TransactionReturn $r) => self::toArray($r))->all();
    }

    /**
     * Riwayat pengajuan yang sudah diputus (disetujui/ditolak) menurut tanggal keputusan, terbaru dulu,
     * beserta ringkasan nilai dikembalikan, unit kembali ke stok/rusak, dan rekap alasan (disetujui).
     *
     * @param  array{from?: mixed, to?: mixed, kind?: ?string, outcome?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function history(array $filters = [], int $limit = 2000): array
    {
        $rows = $this->query(['decided' => true, 'status' => ($filters['outcome'] ?? '') ?: null, 'kind' => $filters['kind'] ?? null,
            'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null])
            ->latest('decided_at')->latest('id')->limit($limit)->get()
            ->map(fn (TransactionReturn $r) => self::toArray($r))->all();

        $ok = collect($rows)->where('status', 'disetujui');

        return ['rows' => $rows, 'summary' => [
            'approved' => $ok->count(),
            'rejected' => collect($rows)->where('status', 'ditolak')->count(),
            'refunded' => (int) $ok->sum('amount'),
            'restocked' => (int) $ok->where('restock', true)->sum('qty'),
            'written_off' => (int) $ok->where('restock', false)->sum('qty'),
            'reasons' => $ok->groupBy('reason')->map(fn ($g, $reason) => ['reason' => $reason, 'count' => $g->count(), 'amount' => (int) $g->sum('amount')])
                ->sortByDesc('amount')->values()->all(),
        ]];
    }

    /** Antrean persetujuan: pengajuan menunggu, terlama dulu. */
    public function queue(int $limit = 100): array
    {
        return $this->query(['status' => 'menunggu'])->oldest('requested_at')->oldest('id')->limit($limit)->get()
            ->map(fn (TransactionReturn $r) => self::toArray($r))->all();
    }

    /** @return array<string, int> jumlah per status (semua, menunggu, disetujui, ditolak) */
    public function counts(array $filters = []): array
    {
        $rows = $this->query([...$filters, 'status' => null])->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $counts = ['semua' => (int) $rows->sum()];
        foreach (array_keys(TransactionReturn::STATUSES) as $s) {
            $counts[$s] = (int) ($rows[$s] ?? 0);
        }

        return $counts;
    }

    /** Ringkasan: menunggu, retur & pembatalan disetujui bulan ini, unit kembali ke stok bulan ini. */
    public function stats(): array
    {
        $month = TransactionReturn::where('status', 'disetujui')->where('decided_at', '>=', now()->startOfMonth());

        return [
            'pending' => TransactionReturn::pending()->count(),
            'return_total' => (int) (clone $month)->where('kind', 'retur')->sum('amount'),
            'return_count' => (clone $month)->where('kind', 'retur')->count(),
            'void_total' => (int) (clone $month)->where('kind', 'batal')->sum('amount'),
            'void_count' => (clone $month)->where('kind', 'batal')->count(),
            'restocked' => (int) (clone $month)->where('restock', true)->sum('quantity'),
        ];
    }

    private function query(array $f): \Illuminate\Database\Eloquent\Builder
    {
        $term = mb_strtoupper(trim((string) ($f['search'] ?? '')));

        return TransactionReturn::query()
            ->with(['items', 'transaction.user:id,name', 'requester:id,name', 'decider:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['decided'] ?? false, fn ($q) => $q->decided())
            ->ofKind(($f['kind'] ?? '') ?: null)
            ->when($f['requested_by'] ?? null, fn ($q, $id) => $q->where('requested_by', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('decided_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('decided_at', '<=', $d))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn ($w) => $w->whereRaw("UPPER(number) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereHas('transaction', fn ($t) => $t->whereRaw("UPPER(invoice_number) LIKE ? ESCAPE '\\'", [$like]))
                    ->orWhereHas('items', fn ($i) => $i->whereRaw("UPPER(product_name) LIKE ? ESCAPE '\\'", [$like])));
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

    /** Nilai pengajuan yang dicatat di audit (riwayat objek retur). */
    private static function snapshot(TransactionReturn $r): array
    {
        return ['kind' => $r->kind, 'status' => $r->status, 'amount' => $r->amount, 'quantity' => $r->quantity, 'refund_method' => $r->refund_method,
            'restock' => $r->restock, 'reason' => $r->reason, 'note' => $r->note];
    }

    /** Catat ke audit dengan subjek pengajuan (transaction_return) dan rujukan transaksinya. */
    private static function audit(string $event, string $description, TransactionReturn $r, Transaction $t, array $props, string $action, ?array $old, ?array $new, ?string $reason): void
    {
        AuditLog::record($event, 'transaksi', $description,
            ['type' => 'transaction_return', 'id' => $r->id, 'label' => "{$r->number} · {$t->invoice_number}"],
            ['return_id' => $r->id, 'transaction_id' => $t->id, 'invoice' => $t->invoice_number, 'kind' => $r->kind, ...$props],
            $r->amount, action: $action, old: $old, new: $new, reason: $reason);
    }

    /** Transaksi masih bisa diajukan retur/pembatalan. */
    private function assertOpen(Transaction $t): void
    {
        if ($t->isVoided()) {
            throw ValidationException::withMessages(['transaction' => 'Transaksi ini sudah dibatalkan.']);
        }
        if (self::expired($t)) {
            throw ValidationException::withMessages(['transaction' => 'Transaksi ini sudah lewat batas '.self::limitDays().' hari.']);
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
    private function refund(TransactionReturn $r, Transaction $t, ?int $userId): void
    {
        $amount = $r->amount;
        if ($amount <= 0) {
            return;
        }
        $label = ($r->isVoid() ? 'Pembatalan' : 'Retur')." {$r->number} ({$t->invoice_number})";

        if ($r->refund_method === 'tabungan') {
            $this->savings->refund($t->member_id, $amount, 'return', $r->id, $label, $userId);

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
            $shift->movements()->create(['type' => CashMovement::TYPE_OUT, 'category' => 'lainnya', 'amount' => $amount, 'reason' => "Pengembalian {$label}", 'user_id' => $userId]);
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
