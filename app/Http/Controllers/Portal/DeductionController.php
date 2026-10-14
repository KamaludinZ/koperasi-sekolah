<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\BalanceDeductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * GET /portal/potong-saldo?periode=30-hari|90-hari|tahun-ini|semua&limit=
 * Riwayat potong saldo anggota yang masuk (tabungan & simpanan) + total dipotong/dikembalikan.
 */
class DeductionController extends Controller
{
    public const PERIODS = ['30-hari', '90-hari', 'tahun-ini', 'semua'];

    public function __invoke(Request $request, BalanceDeductionService $deductions): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', Rule::in(self::PERIODS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $from = match ($data['periode'] ?? 'semua') {
            '30-hari' => now()->subDays(29)->startOfDay(),
            '90-hari' => now()->subDays(89)->startOfDay(),
            'tahun-ini' => now()->startOfYear(),
            default => null,
        };
        $rows = $deductions->history(Auth::guard('member')->user(), $from, (int) ($data['limit'] ?? 50));

        return response()->json([
            'data' => $rows->map(fn ($r) => ['at' => $r['at']->toIso8601String(), 'source' => $r['source'], 'note' => $r['label'], 'amount' => $r['amount'],
                'balance_after' => $r['balance'], 'order' => $r['order'], 'refunded' => $r['refunded']])->values(),
            'meta' => ['deducted' => (int) $rows->sum('amount'), 'refunded' => (int) $rows->where('refunded', true)->sum('amount'), 'count' => $rows->count()],
        ]);
    }
}
