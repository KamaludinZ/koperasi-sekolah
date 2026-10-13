<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu cuplikan hasil pemeriksaan kesehatan sistem. */
class HealthCheck extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['status', 'results', 'duration_ms', 'created_at'];

    protected function casts(): array
    {
        return ['results' => 'array', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }
}
