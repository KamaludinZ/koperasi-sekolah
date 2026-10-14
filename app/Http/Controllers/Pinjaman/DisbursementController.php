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
 *
 * POST /pinjaman/pencairan/{disbursement}/bukti (multipart): proof (keterangan) dan/atau bukti (berkas baru menggantikan
 * berkas lama). Minimal salah satu diisi; keterangan kosong menghapus keterangan.
 *
 * GET /pinjaman/pencairan/data → pinjaman siap cair + pencairan terbaru (sama dengan halaman Pencairan).
 * GET /pinjaman/{loan}/pencairan → rincian pencairan (bukti, sidik jari, petugas) + jadwal angsuran dengan porsi
 *     pokok/bunga; untuk pinjaman yang belum cair berisi pratinjau jadwal bila dicairkan hari ini.
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

    public function updateProof(Request $request, \App\Models\LoanDisbursement $disbursement, LoanDisbursementService $disbursements): JsonResponse
    {
        $data = $request->validate([
            'proof' => ['nullable', 'string', 'max:200', 'required_without:bukti'],
            'bukti' => UploadPolicy::proofRules(),
        ], ['proof.max' => 'Keterangan bukti maksimal 200 karakter.', 'proof.required_without' => 'Isi keterangan atau pilih berkas bukti.']
            + UploadPolicy::proofMessages('bukti'));

        $file = $request->hasFile('bukti') ? LoanDisbursementService::storeProof($request->file('bukti')) : null;
        $proof = $request->exists('proof') ? ($data['proof'] ?? null) : $disbursement->proof;
        try {
            $disbursement = $disbursements->attachProof($disbursement, $proof, $file);
        } catch (\Throwable $e) {
            if ($file) {
                Storage::disk('local')->delete($file['proof_file']);
            }
            throw $e;
        }

        return response()->json([
            'data' => ['id' => $disbursement->id, 'proof' => $disbursement->proof, 'has_file' => filled($disbursement->proof_file),
                'file_type' => $disbursement->proof_mime === 'application/pdf' ? 'pdf' : ($disbursement->proof_file ? 'image' : null),
                'download_url' => $disbursement->proof_file ? route('pinjaman.pencairan.bukti', $disbursement) : null],
            'message' => 'Bukti pencairan diperbarui.',
        ]);
    }

    public function index(): JsonResponse
    {
        $page = new \App\Livewire\Pinjaman\Disbursements;

        return response()->json(['data' => ['ready' => $page->ready(), 'recent' => $page->recent()]]);
    }

    public function show(Loan $loan): JsonResponse
    {
        $loan->loadMissing(['member:id,member_code,name,class,type,deleted_at', 'disbursements.user:id,name', 'installments']);
        $principal = (int) round((float) $loan->principal);
        $interest = (int) round((float) $loan->interest);
        $d = $loan->disbursements->first();

        if (! $d) {
            $preview = in_array($loan->status, ['diajukan', 'disetujui'], true)
                ? \App\Services\LoanScheduleGenerator::generate($principal, $interest, $loan->tenor_months, today()) : [];

            return response()->json(['data' => ['loan' => LoanService::toArray($loan), 'disbursement' => null, 'schedule' => [], 'preview' => $preview]]);
        }

        // Porsi pokok/bunga mengikuti generator (jadwal tersimpan dibentuk dengan aturan yang sama).
        $parts = collect(\App\Services\LoanScheduleGenerator::generate($principal, $interest, $loan->tenor_months, $d->disbursed_on))->keyBy('sequence');
        $schedule = $loan->installments->map(fn ($i) => [
            'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(), 'amount' => (int) round((float) $i->amount),
            'principal_part' => $parts[$i->sequence]['principal_part'] ?? null, 'interest_part' => $parts[$i->sequence]['interest_part'] ?? null,
            'paid_amount' => (int) round((float) $i->paid_amount), 'status' => $i->status,
        ])->values()->all();

        return response()->json(['data' => [
            'loan' => LoanService::toArray($loan),
            'disbursement' => [
                'id' => $d->id, 'disbursed_on' => $d->disbursed_on->toDateString(), 'amount' => (int) round((float) $d->amount),
                'proof' => $d->proof, 'has_file' => filled($d->proof_file), 'proof_mime' => $d->proof_mime, 'proof_size' => $d->proof_size,
                'proof_sha256' => $d->proof_sha256, 'user' => $d->user?->name, 'recorded_at' => $d->created_at?->toDateTimeString(),
                'download_url' => $d->proof_file ? route('pinjaman.pencairan.bukti', $d) : null,
            ],
            'schedule' => $schedule,
            'totals' => ['amount' => array_sum(array_column($schedule, 'amount')), 'principal' => $principal, 'interest' => $interest,
                'paid' => array_sum(array_column($schedule, 'paid_amount'))],
            'preview' => [],
        ]]);
    }
}
