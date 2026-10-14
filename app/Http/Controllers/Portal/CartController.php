<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Support\PortalCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keranjang portal anggota (JSON, disimpan di sesi; harga & stok dibaca ulang dari data barang):
 * - GET    /portal/keranjang                         → isi keranjang + total
 * - PUT    /portal/keranjang {product_id, quantity}  → atur jumlah (0 = hapus; dibatasi stok)
 * - DELETE /portal/keranjang                         → kosongkan
 */
class CartController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => PortalCart::summary()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:'.\App\Services\OrderService::MAX_QTY],
        ]);
        $qty = PortalCart::set((int) $data['product_id'], (int) $data['quantity']);

        return response()->json(['data' => PortalCart::summary(), 'meta' => ['quantity' => $qty, 'limited' => $qty < (int) $data['quantity']]]);
    }

    public function clear(): JsonResponse
    {
        PortalCart::clear();

        return response()->json(['data' => PortalCart::summary()]);
    }
}
