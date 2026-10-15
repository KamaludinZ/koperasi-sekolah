<?php

namespace App\Services;

use App\Models\Loan;
use Illuminate\Support\Facades\DB;

/**
 * Pencatat otomatis perubahan data pinjaman ke jejak audit (`pinjaman.data_berubah`), sebagai jaring pengaman untuk
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
            return $field === 'paid_off_at' ? $v->format('Y-m-d H:i:s') : $v->format('Y-m-d');
        }
        if (in_array($field, ['principal', 'interest', 'outstanding'], true)) {
            return (int) round((float) $v);
        }
        if (in_array($field, ['applied_at', 'disbursed_at', 'overdue_since'], true)) {
            return substr((string) $v, 0, 10);
        }
        if ($field === 'paid_off_at') {
            return substr((string) $v, 0, 19);
        }

        return in_array($field, ['member_id', 'tenor_months'], true) ? (int) $v : $v;
    }

    /** Dipanggil dari Loan::updated. */
    public static function capture(Loan $loan): void
    {
        if (AuditLog::isQuiet()) {
            return;
        }
        $old = [];
        $new = [];
        foreach (array_keys($loan->getChanges()) as $f) {
            if (! in_array($f, self::FIELDS, true)) {
                continue;
            }
            $from = self::normalize($f, $loan->getRawOriginal($f));
            $to = self::normalize($f, $loan->getAttributes()[$f] ?? null);
            if ($from !== $to) {
                [$old[$f], $new[$f]] = [$from, $to];
            }
        }
        if ($old === [] && $new === []) {
            return;
        }
        $label = $loan->code.' · '.($loan->member()->value('name') ?? '—');
        $level = DB::transactionLevel();
        self::$pending[] = ['loan_id' => $loan->id, 'old' => $old, 'new' => $new, 'code' => $loan->code, 'label' => $label, 'level' => $level];

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
        // gabungkan per pinjaman: nilai sebelum = paling awal, sesudah = paling akhir
        $merged = [];
        foreach ($pending as $p) {
            $e = $merged[$p['loan_id']] ?? ['old' => [], 'new' => [], 'code' => $p['code'], 'label' => $p['label']];
            $e['old'] += $p['old'];
            $e['new'] = array_merge($e['new'], $p['new']);
            $merged[$p['loan_id']] = $e;
        }
        foreach ($merged as $id => $e) {
            $e['old'] = array_filter($e['old'], fn ($v, $f) => $v !== ($e['new'][$f] ?? null), ARRAY_FILTER_USE_BOTH);
            $e['new'] = array_intersect_key($e['new'], $e['old']);
            if (isset($explicit[$id]) || $e['new'] === []) {
                continue;
            }
            AuditLog::record('pinjaman.data_berubah', 'pinjaman', "Data pinjaman {$e['code']} berubah: ".implode(', ', array_keys($e['new'])),
                ['type' => 'loan', 'id' => $id, 'label' => $e['label']], ['fields' => array_keys($e['new']), 'otomatis' => true], null,
                action: 'diubah', old: $e['old'], new: $e['new']);
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
