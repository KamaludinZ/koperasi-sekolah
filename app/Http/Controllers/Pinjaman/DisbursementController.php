<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\LoanDisbursementService;
use App\Services\LoanService;
use App\Services\UploadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * POST /pinjaman/{loan}/pencairan (Admin & Pengurus, multipart/JSON):
 * disbursed_on (Y-m-d, bawaan hari ini), amount (= pokok, bawaan pokok), proof (keterangan, opsional),
 * bukti (berkas JPG/PNG/WEBP/PDF ≤ 4 MB, opsional). Hanya pinjaman berstatus "disetujui"; membentuk jadwal angsuran.
 */
class DisbursementController extends Controller
{
    public function store(Request $request, Loan $loan, LoanDisbursementService $disbursements): JsonResponse
    {
        $request->mergeIfMissing(['disbursed_on' => today()->toDateString(), 'amount' => (int) round((float) $loan->principal)]);
        $data = $request->validate(LoanDisbursementService::rules($loan) + ['bukti' => UploadPolicy::proofRules()],
            LoanDisbursementService::messages($loan) + UploadPolicy::proofMessages('bukti'));

        $file = $request->hasFile('bukti') ? LoanDisbursementService::storeProof($request->file('bukti')) : null;
        try {
            $loan = $disbursements->disburse($loan, $data, $request->user()?->id, $file);
        } catch (\Throwable $e) {
            if ($file) {
                Storage::disk('local')->delete($file['proof_file']);
            }
            throw $e;
        }

        $detail = app(LoanService::class)->detail($loan->fresh());

        return response()->json([
            'data' => $detail,
            'message' => "Dana {$loan->code} dicairkan. {$loan->tenor_months} angsuran terjadwal mulai ".\App\Support\Tanggal::format($detail['installments'][0]['due_date'] ?? null).'.',
        ], 201);
    }
}
