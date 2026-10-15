<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rantai HMAC log audit pinjaman (tabel loan_audit_chain). Sumber: activity_logs kategori `pinjaman` dan
 * loan_installment_changes. Tiap mata rantai: hmac = HMAC-SHA256(APP_KEY, prev_hmac ␟ source_type ␟ source_id ␟ payload_hash),
 * dengan payload_hash = tanda HMAC isi baris sumber. verify() mendeteksi baris sumber yang diubah/dihapus, mata rantai
 * yang diubah/disisipkan/dihapus (sambungan prev_hmac putus), dan baris sumber yang tidak dirantai.
 */
class LoanAuditChain
{
    public const TABLE = 'loan_audit_chain';

    /** Kolom riwayat perubahan angsuran yang ditandatangani, berurutan. */
    public const CHANGE_FIELDS = ['loan_id', 'loan_installment_id', 'sequence', 'event', 'changes', 'loan_payment_id', 'reason', 'user_id', 'actor_name', 'created_at'];

    /** Tanda HMAC isi satu baris riwayat perubahan angsuran (nilai mentah seperti tersimpan). */
    public static function changeChecksum(array|object $row): string
    {
        $row = (array) $row;
        $payload = implode("\x1F", array_map(function (string $f) use ($row) {
            $v = $row[$f] ?? null;

            return match (true) {
                $v === null => "\x00",
                $v instanceof DateTimeInterface => $v->format('Y-m-d H:i:s'),
                is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
                $f === 'changes' => json_encode(json_decode((string) $v, true), JSON_UNESCAPED_UNICODE), // samakan format JSON (jsonb pgsql)
                $f === 'created_at' => substr((string) $v, 0, 19),
                default => (string) $v,
            };
        }, self::CHANGE_FIELDS));

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public static function link(?string $prev, string $type, int $id, string $payload): string
    {
        return hash_hmac('sha256', ($prev ?? '')."\x1F{$type}\x1F{$id}\x1F{$payload}", (string) config('app.key'));
    }

    /** Tambahkan satu mata rantai (serial: mata rantai terakhir dikunci selama penambahan). */
    public function append(string $type, int $id, ?int $loanId, string $payloadHash): void
    {
        DB::transaction(function () use ($type, $id, $loanId, $payloadHash) {
            if (DB::table(self::TABLE)->where('source_type', $type)->where('source_id', $id)->exists()) {
                return;
            }
            $prev = DB::table(self::TABLE)->orderByDesc('id')->lockForUpdate()->value('hmac');
            DB::table(self::TABLE)->insert([
                'source_type' => $type, 'source_id' => $id, 'loan_id' => $loanId, 'payload_hash' => $payloadHash,
                'prev_hmac' => $prev, 'hmac' => self::link($prev, $type, $id, $payloadHash), 'created_at' => now(),
            ]);
        });
    }

    /** Rantai semua baris sumber yang belum dirantai, urut waktu catat (dipakai migrasi & perbaikan). */
    public function backfill(): int
    {
        $chained = fn (string $type) => DB::table(self::TABLE)->where('source_type', $type)->pluck('source_id')->flip();
        [$a, $c] = [$chained('activity'), $chained('installment_change')];
        $rows = DB::table('activity_logs')->where('category', 'pinjaman')->orderBy('id')->get()
            ->reject(fn ($r) => $a->has($r->id))
            ->map(fn ($r) => ['activity', $r->id, $r->subject_type === 'loan' ? $r->subject_id : null, AuditIntegrity::checksum($r), (string) $r->created_at])
            ->merge(DB::table('loan_installment_changes')->orderBy('id')->get()->reject(fn ($r) => $c->has($r->id))
                ->map(fn ($r) => ['installment_change', $r->id, $r->loan_id, self::changeChecksum($r), (string) $r->created_at]))
            ->sortBy(fn ($r) => substr($r[4], 0, 19).($r[0] === 'activity' ? '1' : '0').sprintf('%012d', $r[1]))->values();
        foreach ($rows as [$type, $id, $loanId, $hash]) {
            $this->append($type, (int) $id, $loanId ? (int) $loanId : null, $hash);
        }

        return $rows->count();
    }

    /**
     * Periksa rantai.
     *
     * @return array{checked: int, valid: bool, broken: list<int>, tampered: list<array{type: string, id: int}>, missing: list<array{type: string, id: int}>, unchained: list<array{type: string, id: int}>, last_hmac: ?string}
     */
    public function verify(?int $loanId = null): array
    {
        $r = ['checked' => 0, 'broken' => [], 'tampered' => [], 'missing' => [], 'unchained' => [], 'last_hmac' => null];
        $prev = null;
        $seen = ['activity' => [], 'installment_change' => []];

        foreach (DB::table(self::TABLE)->orderBy('id')->lazyById(1000) as $link) {
            // sambungan & tanda mata rantai diperiksa untuk SELURUH rantai (penghapusan di tengah memutus sambungan)
            if ($link->prev_hmac !== $prev || ! hash_equals($link->hmac, self::link($link->prev_hmac, $link->source_type, (int) $link->source_id, $link->payload_hash))) {
                $r['broken'][] = (int) $link->id;
            }
            $prev = $link->hmac;
            $seen[$link->source_type][(int) $link->source_id] = true;
            if ($loanId !== null && (int) $link->loan_id !== $loanId) {
                continue;
            }
            $r['checked']++;
            $source = $link->source_type === 'activity'
                ? DB::table('activity_logs')->where('id', $link->source_id)->first()
                : DB::table('loan_installment_changes')->where('id', $link->source_id)->first();
            $ref = ['type' => $link->source_type, 'id' => (int) $link->source_id];
            if (! $source) {
                $r['missing'][] = $ref;
            } elseif (! hash_equals($link->payload_hash, $link->source_type === 'activity' ? AuditIntegrity::checksum($source) : self::changeChecksum($source))) {
                $r['tampered'][] = $ref;
            }
        }
        $r['last_hmac'] = $prev;

        $unchained = fn (string $table, string $type, $q) => $q->when($loanId !== null, fn ($w) => $w->where($type === 'activity' ? 'subject_id' : 'loan_id', $loanId))
            ->orderBy('id')->pluck('id')->reject(fn ($id) => isset($seen[$type][(int) $id]))->map(fn ($id) => ['type' => $type, 'id' => (int) $id]);
        $r['unchained'] = $unchained('activity_logs', 'activity', DB::table('activity_logs')->where('category', 'pinjaman')->when($loanId !== null, fn ($q) => $q->where('subject_type', 'loan')))
            ->merge($unchained('loan_installment_changes', 'installment_change', DB::table('loan_installment_changes')))->values()->all();
        $r['valid'] = $r['broken'] === [] && $r['tampered'] === [] && $r['missing'] === [] && $r['unchained'] === [];

        return $r;
    }
}
