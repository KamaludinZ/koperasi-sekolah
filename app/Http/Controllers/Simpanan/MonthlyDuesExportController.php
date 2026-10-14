<?php

namespace App\Http\Controllers\Simpanan;

use App\Http\Controllers\Controller;
use App\Models\SavingType;
use App\Services\SavingDueService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /simpanan/setoran-bulanan/unduh?bulan=YYYY-MM&jenis=&format=csv|pdf
 * Lembar setoran bulanan (potong gaji) satu periode: anggota guru, nominal, status, waktu posting + total.
 */
class MonthlyDuesExportController extends Controller
{
    public const STATUS = ['disetor' => 'Sudah disetor', 'belum' => 'Dicatat, belum diposting', 'kosong' => 'Belum dicatat'];

    public const CELL = ['disetor' => 'Sudah', 'belum' => 'Dicatat', 'kosong' => 'Belum'];

    /**
     * GET /simpanan/setoran-bulanan/rekap/unduh?jenis=&rentang=3|6|12&format=csv|pdf
     * Rekap status setoran guru × bulan, jumlah bulan belum disetor, dan total per bulan.
     */
    public function recap(Request $request, SavingDueService $dues): Response
    {
        $data = $request->validate([
            'jenis' => ['required', 'integer', Rule::exists('saving_types', 'id')->where('period', 'bulanan')],
            'rentang' => ['nullable', Rule::in([3, 6, 12])],
            'format' => ['nullable', Rule::in(['csv', 'pdf'])],
        ], ['jenis.exists' => 'Pilih jenis simpanan bulanan.']);
        $type = SavingType::findOrFail($data['jenis']);
        $m = $dues->matrix($type, (int) ($data['rentang'] ?? 6));
        $name = 'rekap-setoran-'.str($type->code)->lower().'-'.now()->format('Ym');

        if (($data['format'] ?? 'csv') === 'pdf') {
            $pdf = Pdf::loadView('simpanan.rekap-pdf', ['m' => $m, 'type' => $type, 'cell' => self::CELL, 'profile' => \App\Support\MockData::profile()])
                ->setPaper('a4', count($m['periods']) > 6 ? 'landscape' : 'portrait')->output();

            return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
        }

        return response()->streamDownload(function () use ($m, $type) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Rekap setoran bulanan {$type->name}"], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['Kode', 'Nama', ...array_column($m['periods'], 'full'), 'Bulan belum disetor'], ';');
            foreach ($m['rows'] as $r) {
                fputcsv($out, [$r['code'], $r['name'], ...array_map(fn ($c) => $c ? self::CELL[$c] : '-', array_values($r['cells'])), $r['missing']], ';');
            }
            fputcsv($out, [], ';');
            fputcsv($out, ['', 'Sudah disetor', ...array_map(fn ($p) => $m['totals'][$p['key']]['paid'].'/'.$m['totals'][$p['key']]['eligible'], $m['periods'])], ';');
            fputcsv($out, ['', 'Total (Rp)', ...array_map(fn ($p) => $m['totals'][$p['key']]['amount'], $m['periods'])], ';');
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function __invoke(Request $request, SavingDueService $dues): Response
    {
        $data = $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'jenis' => ['required', 'integer', Rule::exists('saving_types', 'id')->where('period', 'bulanan')],
            'format' => ['nullable', Rule::in(['csv', 'pdf'])],
        ], ['bulan.regex' => 'Periode tidak valid.', 'jenis.exists' => 'Pilih jenis simpanan bulanan.']);

        $type = SavingType::findOrFail($data['jenis']);
        $rows = $dues->sheet($data['bulan'], $type);
        $month = Carbon::parse($data['bulan'].'-01')->translatedFormat('F Y');
        $posted = array_filter($rows, fn ($r) => $r['status'] === 'disetor');
        $summary = ['members' => count($rows), 'posted' => count($posted), 'posted_amount' => array_sum(array_column($posted, 'amount')),
            'missing' => count($rows) - count($posted)];
        $name = 'setoran-'.str($type->code)->lower().'-'.$data['bulan'];

        if (($data['format'] ?? 'csv') === 'pdf') {
            $pdf = Pdf::loadView('simpanan.setoran-pdf', ['rows' => $rows, 'type' => $type, 'month' => $month, 'summary' => $summary,
                'profile' => \App\Support\MockData::profile(), 'status' => self::STATUS])->setPaper('a4')->output();

            return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
        }

        return response()->streamDownload(function () use ($rows, $type, $month, $summary) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, ["Setoran bulanan {$type->name}", $month], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['Kode', 'Nama', 'Nominal', 'Status', 'Diposting'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['code'], $r['name'], $r['status'] === 'kosong' ? '' : $r['amount'], self::STATUS[$r['status']], $r['posted_at'] ?? ''], ';');
            }
            fputcsv($out, [], ';');
            fputcsv($out, ['Sudah disetor', $summary['posted'].' / '.$summary['members'], $summary['posted_amount']], ';');
            fputcsv($out, ['Belum disetor', $summary['missing']], ';');
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
