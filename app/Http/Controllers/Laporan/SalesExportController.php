<?php

namespace App\Http\Controllers\Laporan;

use App\Http\Controllers\Controller;
use App\Services\SalesExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /laporan/unduh?dari=&sampai=&format=csv|pdf&isi[]=summary&isi[]=daily…
 * Unduh rekap penjualan rentang tanggal (maks. 1 tahun). `isi` kosong = ringkasan,
 * rekap per hari, dan barang terjual.
 */
class SalesExportController extends Controller
{
    public function __invoke(Request $request, SalesExport $export): StreamedResponse
    {
        $data = $request->validate([
            'dari' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari', 'before_or_equal:today'],
            'format' => ['nullable', Rule::in(array_keys(SalesExport::FORMATS))],
            'isi' => ['nullable', 'array', 'min:1'],
            'isi.*' => [Rule::in(array_keys(SalesExport::SECTIONS))],
        ], [
            'sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
            'format.in' => 'Format tidak dikenal (csv atau pdf).',
        ]);

        return $export->download(
            Carbon::createFromFormat('!Y-m-d', $data['dari']),
            Carbon::createFromFormat('!Y-m-d', $data['sampai']),
            array_values(array_unique($data['isi'] ?? SalesExport::DEFAULT_SECTIONS)),
            $data['format'] ?? 'csv',
        );
    }
}
