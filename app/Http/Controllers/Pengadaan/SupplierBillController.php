<?php

namespace App\Http\Controllers\Pengadaan;

use App\Http\Controllers\Controller;
use App\Models\SupplierBill;
use App\Services\SupplierBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hutang supplier (JSON; menu Pengadaan). Tagihan dibuat otomatis saat barang diterima.
 * - GET /pengadaan/hutang/data?status=belum-lunas|lewat|minggu-ini|lunas|semua&supplier=&dari=&sampai=
 *       → tagihan (jatuh tempo terdekat dulu) + ringkasan (sisa, lewat tempo, ≤ 7 hari, dibayar bulan ini)
 * - GET /pengadaan/hutang/{bill}/data → tagihan + pembayaran
 */
class SupplierBillController extends Controller
{
    public function index(Request $request, SupplierBillService $bills): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['belum-lunas', 'lewat', 'minggu-ini', 'lunas', 'semua'])],
            'supplier' => ['nullable', 'integer'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        $status = $f['status'] ?? 'belum-lunas';
        $today = today()->toDateString();
        $soon = today()->addDays(SupplierBillService::DUE_SOON_DAYS)->toDateString();

        $rows = collect($bills->list())
            ->filter(fn ($b) => match ($status) {
                'belum-lunas' => $b['status'] !== 'lunas',
                'lewat' => $b['status'] !== 'lunas' && $b['due_at'] < $today,
                'minggu-ini' => $b['status'] !== 'lunas' && $b['due_at'] >= $today && $b['due_at'] <= $soon,
                'lunas' => $b['status'] === 'lunas',
                default => true,
            })
            ->when(isset($f['supplier']), fn ($c) => $c->where('supplier_id', (int) $f['supplier']))
            ->when(isset($f['dari']), fn ($c) => $c->where('due_at', '>=', $f['dari']))
            ->when(isset($f['sampai']), fn ($c) => $c->where('due_at', '<=', $f['sampai']))
            ->values();

        return response()->json(['data' => $rows, 'summary' => $bills->summary()]);
    }

    public function show(SupplierBill $bill, SupplierBillService $bills): JsonResponse
    {
        return response()->json(['data' => SupplierBillService::toArray($bill->load(['supplier', 'order'])) + ['payments' => $bills->payments($bill)]]);
    }
}
