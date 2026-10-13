<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use Illuminate\Support\Carbon;

/**
 * Isi CSV rekap kas harian (pemisah `;` + BOM agar langsung terbuka di Excel):
 * identitas & periode, ringkasan periode, rekap per hari, rincian per shift, dan
 * setoran/penarikan kas laci. Dipakai job ExportCashRecap.
 */
class CashRecapExport
{
    public function __construct(private CashReportService $report, private SettingsService $settings) {}

    /**
     * @return array{content: string, rows: int}
     */
    public function csv(Carbon $from, Carbon $to): array
    {
        $days = $this->report->range($from, $to);
        $open = collect($days)->where('closed_day', false);
        $shifts = $this->report->shiftRows(
            CashShift::whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->with('user:id,name', 'closer:id,name')->orderBy('opened_at')->get()
        );
        $moves = CashMovement::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('user:id,name', 'shift:id,name')->orderBy('created_at')->get();
        $profile = $this->settings->profile();
        $date = fn (?string $dt, string $f = 'd/m/Y H:i') => $dt ? Carbon::parse($dt)->format($f) : '';

        $lines = [
            ['Rekap Kas Harian', $profile['name']],
            ['Periode', $from->format('d/m/Y').' – '.min($to, today())->format('d/m/Y')],
            ['Dibuat', now()->format('d/m/Y H:i')],
            [],
            ['RINGKASAN'],
            ['Hari buka', $open->where('shifts', '>', 0)->count()],
            ['Total penjualan', $open->sum('revenue')],
            ['Penjualan tunai', $open->sum('cash_sales')],
            ['Penjualan non-tunai', $open->sum('non_cash')],
            ['Kas masuk', $open->sum('cash_in')],
            ['Kas keluar', $open->sum('cash_out')],
            ['Selisih kas bersih', $open->sum('difference')],
            [],
            ['REKAP PER HARI'],
            ['Tanggal', 'Shift', 'Transaksi', 'Modal awal', 'Penjualan tunai', 'Non-tunai', 'Kas masuk', 'Kas keluar', 'Kas seharusnya', 'Uang dihitung', 'Selisih', 'Kasir'],
        ];
        foreach ($days as $d) {
            $lines[] = $d['closed_day']
                ? [Carbon::parse($d['date'])->format('d/m/Y'), 'Tutup']
                : [Carbon::parse($d['date'])->format('d/m/Y'), $d['shifts'], $d['transactions'], $d['opening_cash'], $d['cash_sales'], $d['non_cash'],
                    $d['cash_in'], $d['cash_out'], $d['expected'], $d['counted'], $d['difference'], implode(', ', $d['cashiers'])];
        }

        $lines = [...$lines, [], ['RINCIAN SHIFT'],
            ['Dibuka', 'Ditutup', 'Shift', 'Kasir', 'Status', 'Transaksi', 'Modal awal', 'Penjualan tunai', 'Non-tunai', 'Kas masuk', 'Kas keluar', 'Kas seharusnya', 'Uang dihitung', 'Selisih', 'Catatan']];
        foreach ($shifts as $s) {
            $lines[] = [$date($s['opened_at']), $date($s['closed_at']), $s['name'], $s['cashier'], $s['status'] === 'open' ? 'Berjalan' : 'Ditutup',
                $s['transactions'], $s['opening_cash'], $s['cash_sales'], $s['qris_sales'], $s['cash_in'], $s['cash_out'], $s['expected'],
                $s['counted'] ?? '', $s['difference'] ?? '', $s['note'] ?? ''];
        }

        $lines = [...$lines, [], ['SETORAN & PENARIKAN KAS'], ['Waktu', 'Shift', 'Jenis', 'Kategori', 'Jumlah', 'Alasan', 'Petugas']];
        foreach ($moves as $m) {
            $lines[] = [$m->created_at->format('d/m/Y H:i'), $m->shift?->name, CashMovement::TYPES[$m->type], $m->category_label,
                $m->signed_amount, $m->reason, $m->user?->name];
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
        foreach ($lines as $row) {
            fputcsv($out, $row, ';');
        }
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return ['content' => $content, 'rows' => count($days) + count($shifts) + $moves->count()];
    }
}
