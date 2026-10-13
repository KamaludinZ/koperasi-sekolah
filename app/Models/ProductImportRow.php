<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris berkas impor barang: nilai per kolom, tanda dilewati, dan hasil setelah disimpan. */
class ProductImportRow extends Model
{
    protected $fillable = ['product_import_id', 'line', 'values', 'skipped', 'result', 'message', 'product_id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'skipped' => 'boolean', 'line' => 'integer'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ProductImport::class, 'product_import_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
