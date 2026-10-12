<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan rekap penjualan untuk dilaporkan ke sekolah: ringkasan, rekap per hari,
 * rincian transaksi, dan barang terjual dalam rentang tanggal, sebagai CSV
 * (pemisah `;` + BOM agar langsung terbuka di Excel) atau PDF A4.
 * Dipakai endpoint GET /laporan/unduh dan panel unduh di halaman Laporan.
 */
class SalesExport
{
    public const SECTIONS = [
        'summary' => 'Ringkasan penjualan',
        'daily' => 'Rekap per hari',
        'transactions' => 'Rincian transaksi',
        'products' => 'Barang terjual',
    ];

    public const DEFAULT_SECTIONS = ['summary', 'daily', 'products'];

    public const FORMATS = ['csv' => 'Excel (CSV)', 'pdf' => 'PDF'];

    public const MAX_DAYS = 366;

    /** Batas baris rincian transaksi di PDF (CSV tidak dibatasi). */
    public const PDF_MAX_TRANSACTIONS = 1500;

    public function __construct(
        private SalesReport $report,
        private TransactionHistory $history,
        private ReceiptPresenter $receipt,
    ) {}

    /**
     * @param  list<string>  $sections
     *
     * @throws ValidationException bila rentang terlalu panjang atau rincian terlalu banyak untuk PDF
     */
    public function download(Carbon $start, Carbon $end, array $sections, string $format = 'csv'): StreamedResponse
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();
        $this->guard($start, $end, $sections, $format);

        $filename = 'rekap-penjualan-'.$start->format('Ymd').'-'.$end->format('Ymd').'.'.$format;
        AuditLog::record('report.exported', 'laporan', 'Mengunduh rekap penjualan '.strtoupper($format).' periode '.$start->translatedFormat('d M Y').' – '.$end->translatedFormat('d M Y'),
            null, ['format' => $format, 'sections' => $sections, 'from' => $start->toDateString(), 'to' => $end->toDateString()]);

        if ($format === 'pdf') {
            $pdf = $this->pdf($start, $end, $sections);

            return response()->streamDownload(fn () => print ($pdf), $filename, ['Content-Type' => 'application/pdf']);
        }

        $rows = $this->rows($start, $end, $sections);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Data rekap terstruktur (dipakai CSV & PDF).
     *
     * @param  list<string>  $sections
     */
    public function data(Carbon $start, Carbon $end, array $sections): array
    {
        $has = fn (string $s) => in_array($s, $sections, true);

        return [
            'profile' => $this->receipt->profile(),
            'period' => $start->translatedFormat('d F Y').' s.d. '.$end->translatedFormat('d F Y'),
            'generated_at' => now()->translatedFormat('d F Y H:i'),
            'summary' => $has('summary') ? $this->report->summary($start, $end) : null,
            'daily' => $has('daily') ? $this->report->daily($start, $end) : null,
            'transactions' => $has('transactions') ? $this->transactions($start, $end) : null,
            'products' => $has('products') ? $this->report->topProducts($start, $end, null) : null,
        ];
    }

    /** Baris CSV sesuai bagian yang dipilih. */
    public function rows(Carbon $start, Carbon $end, array $sections): array
    {
        $d = $this->data($start, $end, $sections);
        $rows = [
            ['Rekap Penjualan '.$d['profile']['name']],
            ['Periode', $d['period']],
            ['Dibuat', $d['generated_at']],
            [],
        ];

        if ($s = $d['summary']) {
            $rows[] = ['RINGKASAN'];
            $rows[] = ['Total penjualan', $s['revenue']];
            $rows[] = ['Laba kotor', $s['gross_profit']];
            $rows[] = ['Margin (%)', $s['margin']];
            $rows[] = ['Jumlah transaksi', $s['count']];
            $rows[] = ['Barang terjual', $s['items']];
            $rows[] = ['Rata-rata per transaksi', $s['average']];
            $rows[] = [];
        }

        if ($d['daily'] !== null) {
            $rows[] = ['REKAP PER HARI'];
            $rows[] = ['Tanggal', 'Transaksi', 'Barang terjual', 'Penjualan'];
            foreach ($d['daily'] as $day) {
                $rows[] = [$day['date'], $day['count'], $day['items'], $day['revenue']];
            }
            $rows[] = [];
        }

        if ($d['transactions'] !== null) {
            $rows[] = ['RINCIAN TRANSAKSI'];
            $rows[] = ['Waktu', 'No. invoice', 'Kasir', 'Anggota', 'Metode', 'Barang', 'Total'];
            foreach ($d['transactions'] as $r) {
                $rows[] = [$r['created_at'], $r['invoice_number'], $r['cashier'], $r['member'] ?? '', $r['payment_method_label'], $r['item_count'], $r['total']];
            }
            $rows[] = [];
        }

        if ($d['products'] !== null) {
            $rows[] = ['BARANG TERJUAL'];
            $rows[] = ['Barang', 'Jumlah', 'Penjualan', 'Laba kotor'];
            foreach ($d['products'] as $p) {
                $rows[] = [$p['name'], $p['quantity'], $p['revenue'], $p['profit']];
            }
        }

        return $rows;
    }

    public function pdf(Carbon $start, Carbon $end, array $sections): string
    {
        return Pdf::loadView('laporan.rekap-pdf', $this->data($start, $end, $sections))
            ->setPaper('a4')
            ->output();
    }

    private function transactions(Carbon $start, Carbon $end): array
    {
        return $this->history->query(['from' => $start, 'to' => $end])
            ->reorder()->orderBy('created_at')->orderBy('id')
            ->lazy(500)
            ->map(fn ($t) => TransactionHistory::toRow($t))
            ->all();
    }

    private function guard(Carbon $start, Carbon $end, array $sections, string $format): void
    {
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['sampai' => 'Rentang maksimal 1 tahun.']);
        }
        if ($format === 'pdf' && in_array('transactions', $sections, true)
            && $this->history->query(['from' => $start, 'to' => $end])->count() > self::PDF_MAX_TRANSACTIONS) {
            throw ValidationException::withMessages([
                'isi' => 'Rincian transaksi terlalu banyak untuk PDF. Pakai format Excel (CSV) atau persempit rentang.',
            ]);
        }
    }
}
