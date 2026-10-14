<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\LoanDisbursement;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /pinjaman/pencairan/{disbursement}/bukti — unduh berkas bukti serah terima (Admin & Pengurus).
 * `?lihat=1` menampilkan gambar (JPG/PNG/WEBP) langsung untuk pratinjau; PDF selalu diunduh.
 */
class DisbursementProofController extends Controller
{
    public const INLINE = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __invoke(\Illuminate\Http\Request $request, LoanDisbursement $disbursement): StreamedResponse
    {
        $path = $disbursement->proof_file;
        abort_unless($path && str_starts_with($path, \App\Services\LoanDisbursementService::PROOF_DIR.'/') && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path), 404, 'Berkas bukti tidak ditemukan.');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"];
        if ($request->boolean('lihat') && isset(self::INLINE[$ext])) {
            return Storage::disk('local')->response($path, null, ['Content-Type' => self::INLINE[$ext]] + $headers, 'inline');
        }

        return Storage::disk('local')->download($path, 'bukti-pencairan-'.$disbursement->loan->code.'.'.$ext,
            $headers);
    }
}
