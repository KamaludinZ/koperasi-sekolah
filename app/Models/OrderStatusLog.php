<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Riwayat perubahan status pesanan portal. */
class OrderStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'note', 'user_id', 'actor_name', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
