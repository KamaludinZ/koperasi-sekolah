<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Isi CSV ekspor Jejak audit pinjaman (pemisah `;` + BOM): tampilan "jejak" (peristiwa audit pinjaman dengan nilai
 * sebelum/sesudah & alasan) atau "transaksi" (riwayat pencairan & angsuran termasuk yang dikoreksi). Teks yang diawali
 * = + - @ dinetralkan (LoanRecapExport::cell). Maks. MAX_ROWS baris.
 */
class LoanAuditExport
{
    public const MAX_ROWS = 20000;

    /** @return iterable<list<mixed>> baris CSV (judul kolom dulu) untuk tampilan jejak */
    public function eventRows(Builder $query): iterable
    {
        yield ['Waktu', 'Peristiwa', 'Kode peristiwa', 'Pinjaman', 'Uraian', 'Nominal', 'Petugas', 'Peran', 'IP', 'Alasan', 'Sebelum', 'Sesudah', 'ID jejak', 'HMAC rantai'];
        foreach ($query->limit(self::MAX_ROWS)->get()->chunk(500) as $chunk) {
            $links = \Illuminate\Support\Facades\DB::table('loan_audit_chain')->where('source_type', 'activity')
                ->whereIn('source_id', $chunk->pluck('id'))->pluck('hmac', 'source_id');
            foreach ($chunk as $l) {
                /** @var ActivityLog $l */
                yield [$l->created_at?->toDateTimeString(), LoanAuditQuery::label($l->event), $l->event, $l->subject_label, $l->description, $l->amount,
                    $l->actor_name ?? 'Sistem', $l->actor_role, $l->ip_address, $l->reason,
                    $l->old_values ? json_encode($l->old_values, JSON_UNESCAPED_UNICODE) : null,
                    $l->new_values ? json_encode($l->new_values, JSON_UNESCAPED_UNICODE) : null, $l->id, $links[$l->id] ?? null];
            }
        }
    }

    /** @return iterable<list<mixed>> baris CSV untuk tampilan transaksi */
    public function ledgerRows(Collection $rows): iterable
    {
        yield ['Tanggal', 'Dicatat', 'Jenis', 'Kode pinjaman', 'Anggota', 'Nominal', 'Metode', 'Rujukan', 'Angsuran ke-', 'Petugas', 'Status', 'Dikoreksi oleh', 'Waktu koreksi', 'Alasan koreksi', 'Menggantikan #'];
        foreach ($rows->take(self::MAX_ROWS) as $x) {
            yield [$x['date'], $x['recorded_at'], $x['label'], $x['loan']['code'] ?? null, $x['loan']['member'] ?? null, $x['amount'], $x['method'], $x['reference'],
                implode(', ', $x['sequences']), $x['user'], $x['valid'] ? 'Sah' : 'Dibatalkan', $x['void']['by'] ?? null, $x['void']['at'] ?? null,
                $x['void']['reason'] ?? null, $x['replaces_id']];
        }
    }

    /**
     * Susun CSV lengkap di memori lalu tandatangani: HMAC-SHA256 (kunci aplikasi) atas seluruh isi berkas. Tanda
     * dikirim sebagai header X-Audit-Signature agar penerima bisa memastikan berkas tidak diubah setelah diunduh
     * (lihat signatureMatches()).
     *
     * @return array{content: string, rows: int, signature: string}
     */
    public static function build(iterable $rows): array
    {
        $h = fopen('php://temp', 'w+');
        $n = self::write($h, $rows);
        rewind($h);
        $content = stream_get_contents($h);
        fclose($h);

        return ['content' => $content, 'rows' => $n, 'signature' => self::sign($content)];
    }

    public static function sign(string $content): string
    {
        return hash_hmac('sha256', $content, (string) config('app.key'));
    }

    public static function signatureMatches(string $content, string $signature): bool
    {
        return hash_equals(self::sign($content), $signature);
    }

    /** Tulis baris ke aliran keluaran sebagai CSV aman (BOM, `;`, netralisasi rumus). */
    public static function write($out, iterable $rows): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        $n = -1;
        foreach ($rows as $row) {
            fputcsv($out, array_map([LoanRecapExport::class, 'cell'], $row), ';');
            $n++;
        }

        return max(0, $n);
    }
}
