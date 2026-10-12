<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_IN = 'masuk';

    public const TYPE_OUT = 'keluar';

    public const TYPE_ADJUSTMENT = 'penyesuaian';

    protected $fillable = ['product_id', 'type', 'quantity', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
