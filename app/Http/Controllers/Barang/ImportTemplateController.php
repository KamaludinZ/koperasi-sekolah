<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Support\ProductImportFormat;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /barang/impor/templat/{format} (csv|xlsx) → unduh templat impor barang:
 * judul kolom + dua baris contoh (lihat ProductImportFormat).
 */
class ImportTemplateController extends Controller
{
    public function __invoke(string $format): Response
    {
        [$body, $type] = $format === 'xlsx'
            ? [ProductImportFormat::xlsxTemplate(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            : [ProductImportFormat::csvTemplate(), 'text/csv; charset=UTF-8'];

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="templat-impor-barang.'.$format.'"',
        ]);
    }
}
