<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan CSV riwayat retur & pembatalan yang sudah diputus (pemisah `;` + BOM agar langsung terbuka di
 * Excel): satu baris per pengajuan beserta barang, nilai, cara pengembalian, stok, alasan, pengaju, dan
 * pemutus. Dipakai GET /transaksi/retur/riwayat/unduh dan tombol unduh di halaman Riwayat retur.
 */
class ReturnHistoryExport
{
    public const HEADER = ['Tanggal keputusan', 'Nomor', 'Jenis', 'Status', 'No. transaksi', 'Tanggal transaksi', 'Barang', 'Jumlah barang', 'Nilai (Rp)',
        'Pengembalian dana', 'Kembali ke stok', 'Alasan', 'Catatan', 'Diajukan oleh', 'Diputus oleh', 'Catatan keputusan'];

    public function __construct(private TransactionReturnService $returns) {}

    /** @return list<list<string|int>> */
    public function rows(array $filters): array
    {
        $out = [self::HEADER];
        foreach ($this->returns->history($filters)['rows'] as $r) {
            $out[] = [
                Carbon::parse($r['decided_at'])->format('d/m/Y H:i'),
                $r['number'],
                $r['kind_label'],
                $r['status_label'],
                $r['transaction']['invoice'],
                Carbon::parse($r['transaction']['created_at'])->format('d/m/Y H:i'),
                collect($r['items'])->map(fn ($i) => $i['qty'].'× '.$i['name'])->join(', '),
                $r['qty'],
                $r['amount'],
                $r['refund_label'],
                $r['restock'] ? 'Ya' : 'Tidak',
                $r['reason'],
                (string) $r['note'],
                (string) ($r['requested_by'] ?? ''),
                (string) ($r['decided_by'] ?? 'Otomatis'),
                (string) $r['decision_note'],
            ];
        }

        return $out;
    }

    public function download(array $filters, string $label): StreamedResponse
    {
        $rows = $this->rows($filters);
        AuditLog::record('report.exported', 'laporan', "Mengunduh riwayat retur & pembatalan (CSV) {$label}", null,
            ['format' => 'csv', 'kind' => $filters['kind'] ?? null, 'outcome' => $filters['outcome'] ?? null, 'rows' => count($rows) - 1]);

        $from = ($filters['from'] ?? null) ? Carbon::parse($filters['from'])->format('Ymd') : 'awal';
        $to = ($filters['to'] ?? null) ? Carbon::parse($filters['to'])->format('Ymd') : now()->format('Ymd');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, "riwayat-retur-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
