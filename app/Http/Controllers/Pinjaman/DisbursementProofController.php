<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\LoanDisbursement;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /pinjaman/pencairan/{disbursement}/bukti — unduh berkas bukti serah terima (Admin & Pengurus). */
class DisbursementProofController extends Controller
{
    public function __invoke(LoanDisbursement $disbursement): StreamedResponse
    {
        $path = $disbursement->proof_file;
        abort_unless($path && str_starts_with($path, \App\Services\LoanDisbursementService::PROOF_DIR.'/') && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path), 404, 'Berkas bukti tidak ditemukan.');
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($path, 'bukti-pencairan-'.$disbursement->loan->code.'.'.$ext,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
