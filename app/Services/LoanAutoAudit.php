<?php

namespace App\Services;

use App\Models\Loan;
use Illuminate\Support\Facades\DB;

/**
 * Pencatat otomatis perubahan data pinjaman (`pinjaman.data_berubah`), pencairan (`pinjaman.pencairan_otomatis`) dan
 * pembayaran angsuran (`pinjaman.angsuran_otomatis`, termasuk yang DIBUAT langsung) ke jejak audit, sebagai jaring pengaman untuk
 * perubahan yang terjadi DI LUAR tindakan resmi (mis. kode lain, perintah konsol, perbaikan manual lewat aplikasi).
 * Perubahan dalam transaksi yang juga mencatat jejak resmi untuk pinjaman yang sama (pengajuan, keputusan, pencairan,
 * angsuran, koreksi, hitung ulang, menunggak…) tidak dicatat dua kali. Beberapa perubahan pinjaman dalam satu transaksi
 * digabung (nilai sebelum = paling awal, sesudah = paling akhir). Transaksi yang dibatalkan tidak meninggalkan catatan.
 */
class LoanAutoAudit
{
    /** Kolom pinjaman yang dipantau. */
    public const FIELDS = ['code', 'member_id', 'principal', 'interest', 'tenor_months', 'payment_method', 'status', 'purpose', 'decision_note',
        'cancel_reason', 'outstanding', 'applied_at', 'disbursed_at', 'paid_off_at', 'overdue_since'];

    /** @var list<array{loan_id: int, old: array, new: array, code: string, label: string, level: int}> perubahan berurutan */
    private static array $pending = [];

    /** @var array<int, int> pinjaman yang sudah punya jejak resmi di transaksi berjalan → level transaksinya */
    private static array $explicit = [];

    public static function normalize(string $field, mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return in_array($field, ['paid_off_at', 'voided_at'], true) ? $v->format('Y-m-d H:i:s') : $v->format('Y-m-d');
        }
        if (in_array($field, ['principal', 'interest', 'outstanding', 'amount'], true)) {
            return (int) round((float) $v);
        }
        if (in_array($field, ['applied_at', 'disbursed_at', 'overdue_since', 'disbursed_on', 'paid_at'], true)) {
            return substr((string) $v, 0, 10);
        }
        if (in_array($field, ['paid_off_at', 'voided_at'], true)) {
            return substr((string) $v, 0, 19);
        }

        return in_array($field, ['member_id', 'tenor_months', 'replaces_id'], true) ? (int) $v : $v;
    }

    /** Kolom pencairan & pembayaran angsuran yang dipantau. */
    public const DISBURSEMENT_FIELDS = ['disbursed_on', 'amount', 'proof', 'proof_file', 'proof_sha256'];

    public const PAYMENT_FIELDS = ['paid_at', 'amount', 'payment_method', 'reference', 'voided_at', 'void_reason', 'replaces_id'];

    /** Peristiwa otomatis per jenis data. */
    public const EVENTS = ['loan' => 'pinjaman.data_berubah', 'disbursement' => 'pinjaman.pencairan_otomatis', 'payment' => 'pinjaman.angsuran_otomatis'];

    /** Dipanggil dari Loan::updated. */
    public static function capture(Loan $loan): void
    {
        [$old, $new] = self::diff($loan, self::FIELDS);
        self::queue($loan, 'loan', 'loan:'.$loan->id, 'diubah', $old, $new);
    }

    /** Dipanggil dari LoanDisbursement::created/updated dan LoanPayment::created/updated. */
    public static function captureRecord(\Illuminate\Database\Eloquent\Model $model, string $kind): void
    {
        $fields = $kind === 'disbursement' ? self::DISBURSEMENT_FIELDS : self::PAYMENT_FIELDS;
        if ($model->wasRecentlyCreated && ! $model->wasChanged()) {
            $new = [];
            foreach ($fields as $f) {
                $new[$f] = self::normalize($f, $model->getAttributes()[$f] ?? null);
            }
            [$old, $new, $action] = [[], array_filter($new, fn ($v) => $v !== null), 'dibuat'];
        } else {
            [$old, $new] = self::diff($model, $fields);
            $action = 'diubah';
        }
        $loan = $model->loan()->first();
        if ($loan) {
            self::queue($loan, $kind, $kind.':'.$model->getKey(), $action, $old, $new, ['id' => $model->getKey()]);
        }
    }

    /** @return array{0: array, 1: array} nilai sebelum & sesudah kolom yang berubah */
    private static function diff(\Illuminate\Database\Eloquent\Model $model, array $fields): array
    {
        $old = [];
        $new = [];
        foreach (array_keys($model->getChanges()) as $f) {
            if (! in_array($f, $fields, true)) {
                continue;
            }
            $from = self::normalize($f, $model->getRawOriginal($f));
            $to = self::normalize($f, $model->getAttributes()[$f] ?? null);
            if ($from !== $to) {
                [$old[$f], $new[$f]] = [$from, $to];
            }
        }

        return [$old, $new];
    }

    private static function queue(Loan $loan, string $kind, string $key, string $action, array $old, array $new, array $extra = []): void
    {
        if (AuditLog::isQuiet() || ($old === [] && $new === [])) {
            return;
        }
        $level = DB::transactionLevel();
        self::$pending[] = ['loan_id' => $loan->id, 'kind' => $kind, 'key' => $key, 'action' => $action, 'old' => $old, 'new' => $new, 'extra' => $extra,
            'code' => $loan->code, 'label' => $loan->code.' · '.($loan->member()->value('name') ?? '—'), 'level' => $level];

        $level > 0 ? DB::afterCommit(fn () => self::flush()) : self::flush();
    }

    /** Dipanggil saat jejak resmi pinjaman dibuat (ActivityLog kategori pinjaman, subjek pinjaman). */
    public static function markExplicit(int $loanId): void
    {
        $level = DB::transactionLevel();
        if ($level > 0) {
            self::$explicit[$loanId] = max(self::$explicit[$loanId] ?? 0, $level);
            DB::afterCommit(fn () => self::flush());
        }
    }

    /** Dijalankan DB::afterCommit setelah transaksi terluar tersimpan (beberapa kali pun aman: yang sudah dicatat dikosongkan). */
    public static function flush(): void
    {
        [$pending, $explicit] = [self::$pending, self::$explicit];
        self::reset();
        // gabungkan per objek: nilai sebelum = paling awal, sesudah = paling akhir; dibuat+diubah = dibuat
        $merged = [];
        foreach ($pending as $p) {
            $e = $merged[$p['key']] ?? ['old' => [], 'new' => [], 'action' => $p['action']] + $p;
            $e['old'] += $p['old'];
            $e['new'] = array_merge($e['new'], $p['new']);
            $merged[$p['key']] = $e;
        }
        $what = ['loan' => 'Data pinjaman', 'disbursement' => 'Pencairan', 'payment' => 'Pembayaran angsuran'];
        foreach ($merged as $e) {
            if ($e['action'] === 'diubah') {
                $e['old'] = array_filter($e['old'], fn ($v, $f) => $v !== ($e['new'][$f] ?? null), ARRAY_FILTER_USE_BOTH);
                $e['new'] = array_intersect_key($e['new'], $e['old']);
            }
            if (isset($explicit[$e['loan_id']]) || $e['new'] === []) {
                continue; // sudah tercatat oleh tindakan resmi / tidak ada perubahan bersih
            }
            $desc = $what[$e['kind']].' '.$e['code'].($e['action'] === 'dibuat' ? ' dicatat di luar alur resmi' : ' berubah').': '.implode(', ', array_keys($e['new']));
            AuditLog::record(self::EVENTS[$e['kind']], 'pinjaman', $desc,
                ['type' => 'loan', 'id' => $e['loan_id'], 'label' => $e['label']], ['fields' => array_keys($e['new']), 'otomatis' => true] + $e['extra'],
                $e['kind'] !== 'loan' && isset($e['new']['amount']) ? (int) $e['new']['amount'] : null,
                action: $e['action'], old: $e['old'] ?: null, new: $e['new']);
        }
    }

    /** Transaksi (atau savepoint) dibatalkan: buang perubahan & penanda yang tercatat di level yang dibatalkan. */
    public static function rolledBack(int $remainingLevel): void
    {
        self::$pending = array_filter(self::$pending, fn ($e) => $e['level'] <= $remainingLevel);
        self::$explicit = array_filter(self::$explicit, fn ($l) => $l <= $remainingLevel);
    }

    public static function reset(): void
    {
        self::$pending = [];
        self::$explicit = [];
    }
}
