<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu cakupan promo: sebuah kategori atau sebuah barang (tepat salah satu). */
class PromoTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['promo_id', 'category_id', 'product_id'];

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
