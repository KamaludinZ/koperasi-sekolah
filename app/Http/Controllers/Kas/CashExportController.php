<?php

namespace App\Http\Controllers\Kas;

use App\Http\Controllers\Controller;
use App\Livewire\Kas\Recap;
use App\Models\ReportExport;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /kas/rekap/ekspor              {periode | dari & sampai} → 202: ekspor CSV rekap kas masuk antrean latar.
 * GET  /kas/rekap/ekspor/{export}     Status ekspor (pending | processing | done | failed) + tautan unduh bila siap.
 * GET  /kas/rekap/ekspor/{export}/unduh  Unduh berkas CSV (hanya peminta; 409 bila belum siap).
 * Rentang maks. 366 hari; tiap petugas maks. 3 ekspor yang belum selesai.
 */
class CashExportController extends Controller
{
    public function store(Request $request, ReportExportService $exports): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', Rule::in(array_keys(Recap::PERIODS))],
            'dari' => ['nullable', 'required_with:sampai', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sampai' => ['nullable', 'required_with:dari', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ], ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);

        [$from, $to] = isset($data['dari'])
            ? [Carbon::parse($data['dari']), Carbon::parse($data['sampai'])]
            : Recap::rangeFor($data['periode'] ?? '7-hari');
        $export = $exports->requestCashRecap($request->user(), $from, $to);

        return response()->json(['data' => self::toArray($export)], 202);
    }

    public function show(Request $request, ReportExport $export): JsonResponse
    {
        abort_unless($export->user_id === $request->user()->id, 404);

        return response()->json(['data' => self::toArray($export)]);
    }

    public function download(Request $request, ReportExport $export): StreamedResponse|JsonResponse
    {
        abort_unless($export->user_id === $request->user()->id, 404);
        if ($export->status !== 'done' || ! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            return response()->json(['message' => $export->status === 'failed' ? 'Ekspor gagal dibuat.' : 'Berkas belum siap atau sudah dibersihkan.'], 409);
        }

        return Storage::disk('local')->download($export->file_path, $export->file_name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function toArray(ReportExport $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'format' => $e->format,
            'from' => $e->params['from'] ?? null,
            'to' => $e->params['to'] ?? null,
            'status' => $e->status,
            'status_label' => ReportExport::STATUSES[$e->status] ?? $e->status,
            'rows' => $e->rows,
            'file_name' => $e->file_name,
            'error' => $e->error,
            'created_at' => $e->created_at?->toDateTimeString(),
            'finished_at' => $e->finished_at?->toDateTimeString(),
            'download_url' => $e->status === 'done' ? route('kas.export.download', $e) : null,
        ];
    }
}
