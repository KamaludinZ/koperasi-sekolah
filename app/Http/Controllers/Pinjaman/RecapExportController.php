<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Services\AuditLog;
use App\Services\LoanRecapService;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /pinjaman/rekap/ekspor {jenis: bulan|tahun|rentang, bulan|tahun|dari&sampai} → 202: ekspor CSV rekap pinjaman
 *      masuk antrean latar (maks. 366 hari; maks. 3 ekspor belum selesai per petugas).
 * GET  /pinjaman/rekap/ekspor          → ekspor rekap pinjaman milik petugas (terbaru dulu) + penanda unduhan.
 * GET  /pinjaman/rekap/ekspor/{export} → status satu ekspor.
 * GET  /pinjaman/rekap/ekspor/{export}/unduh → unduh CSV (hanya peminta & jenis rekap pinjaman; 409 bila belum siap);
 *      setiap unduhan menambah penanda (waktu terakhir & jumlah) dan tercatat di jejak audit.
 */
class RecapExportController extends Controller
{
    public function store(Request $request, ReportExportService $exports): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['nullable', Rule::in(['bulan', 'tahun', 'rentang'])],
            'bulan' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'tahun' => ['nullable', 'regex:/^\d{4}$/'],
            'dari' => ['required_if:jenis,rentang', 'nullable', 'date_format:Y-m-d'],
            'sampai' => ['required_if:jenis,rentang', 'nullable', 'date_format:Y-m-d'],
        ], ['bulan.regex' => 'Bulan harus berformat YYYY-MM.', 'tahun.regex' => 'Tahun tidak valid.']);

        $period = ($data['jenis'] ?? 'bulan') === 'rentang'
            ? LoanRecapService::range($data['dari'], $data['sampai'])
            : LoanRecapService::period($data['jenis'] ?? 'bulan', ($data['jenis'] ?? 'bulan') === 'tahun' ? ($data['tahun'] ?? today()->format('Y')) : ($data['bulan'] ?? today()->format('Y-m')));
        if (! $period) {
            return response()->json(['message' => 'Rentang tidak sah (maks. 366 hari, tidak melewati hari ini).', 'errors' => ['dari' => ['Rentang tidak sah.']]], 422);
        }
        $export = $exports->requestLoanRecap($request->user(), $period[0], $period[1]);

        return response()->json(['data' => self::toArray($export)], 202);
    }

    public function index(Request $request, ReportExportService $exports): JsonResponse
    {
        return response()->json(['data' => $exports->recent($request->user(), 'pinjaman_rekap', 10)->map(fn ($e) => self::toArray($e))->values()]);
    }

    public function show(Request $request, ReportExport $export): JsonResponse
    {
        return response()->json(['data' => self::toArray(self::owned($request, $export))]);
    }

    public function download(Request $request, ReportExport $export): StreamedResponse|JsonResponse
    {
        $export = self::owned($request, $export);
        if ($export->status !== 'done' || ! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            return response()->json(['message' => $export->status === 'failed' ? 'Ekspor gagal dibuat.' : 'Berkas belum siap atau sudah dibersihkan.'], 409);
        }
        $export->markDownloaded();
        AuditLog::record('pinjaman.rekap_diunduh', 'pinjaman', 'Unduh rekap pinjaman '.$export->file_name, null,
            ['export_id' => $export->id, 'from' => $export->params['from'] ?? null, 'to' => $export->params['to'] ?? null, 'download_count' => $export->download_count]);

        return Storage::disk('local')->download($export->file_path, $export->file_name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Ekspor hanya bisa dilihat/diunduh peminta, dan hanya jenis rekap pinjaman (lainnya 404). */
    private static function owned(Request $request, ReportExport $export): ReportExport
    {
        abort_unless($export->user_id === $request->user()->id && $export->type === 'pinjaman_rekap', 404);

        return $export;
    }

    public static function toArray(ReportExport $e): array
    {
        return [
            'id' => $e->id, 'from' => $e->params['from'] ?? null, 'to' => $e->params['to'] ?? null,
            'status' => $e->status, 'status_label' => ReportExport::STATUSES[$e->status] ?? $e->status,
            'rows' => $e->rows, 'file_name' => $e->file_name, 'error' => $e->error,
            'created_at' => $e->created_at?->toDateTimeString(), 'finished_at' => $e->finished_at?->toDateTimeString(),
            'downloaded_at' => $e->downloaded_at?->toDateTimeString(), 'download_count' => (int) $e->download_count,
            'download_url' => $e->status === 'done' ? route('pinjaman.rekap.ekspor.unduh', $e) : null,
        ];
    }
}
