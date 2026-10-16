<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Services\OfflineCatalogService;
use App\Services\OfflineSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kasir offline (wajib menu kasir):
 * - GET  /kasir/offline                 → halaman kasir offline mandiri (disimpan service worker, jalan tanpa internet);
 * - GET  /kasir/offline/sesi            → token CSRF baru (halaman dari cache bisa membawa token lama);
 * - GET  /kasir/offline/katalog?perangkat=  → salinan katalog untuk dipakai tanpa internet (tercatat, berlaku 7 hari);
 * - POST /kasir/offline/sinkron {penjualan: [...]} → kirim antrean penjualan offline (≤ 50 per kiriman); hasil per
 *   penjualan: diterima | perlu_dicek (transaksi dibuat, ada tanda untuk ditinjau) | ditolak | tidak_sah (bentuk data
 *   salah, tidak disimpan — perbaiki di perangkat). Kirim ulang aman (idempoten per client_ref).
 */
class OfflineSyncController extends Controller
{
    public function page(Request $request)
    {
        $user = $request->user('web');

        return response()->view('kasir.offline', ['userId' => $user->id, 'userName' => $user->name])
            ->header('Cache-Control', 'private, no-cache');
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json(['csrf' => csrf_token(), 'user_id' => $request->user('web')->id])->header('Cache-Control', 'no-store');
    }

    public function catalog(Request $request, OfflineCatalogService $catalogs): JsonResponse
    {
        $data = $request->validate(['perangkat' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/']]);

        return response()->json(['data' => $catalogs->issue($request->user('web'), $data['perangkat'])])->header('Cache-Control', 'private, no-store');
    }

    public function sync(Request $request, OfflineSaleService $sales): JsonResponse
    {
        $data = $request->validate(['penjualan' => ['required', 'array', 'min:1', 'max:50'], 'penjualan.*' => ['array']]);
        $results = [];
        foreach ($data['penjualan'] as $i => $sale) {
            try {
                $s = $sales->ingest($request->user('web'), $sale);
                $results[] = [
                    'client_ref' => $s->client_ref, 'status' => $s->status, 'reason' => $s->reason,
                    'invoice_number' => $s->transaction?->invoice_number, 'transaction_id' => $s->transaction_id,
                ];
            } catch (ValidationException $e) {
                $results[] = ['client_ref' => is_string($sale['client_ref'] ?? null) ? mb_substr($sale['client_ref'], 0, 64) : null,
                    'status' => 'tidak_sah', 'reason' => collect($e->errors())->flatten()->first(), 'index' => $i];
            }
        }

        return response()->json(['data' => $results, 'meta' => collect($results)->countBy('status')->all()])->header('Cache-Control', 'no-store');
    }
}
