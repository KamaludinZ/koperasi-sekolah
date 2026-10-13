<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SoftDeletesWithReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletesWithReason;

    public const AUDIT = ['type' => 'category', 'category' => 'barang', 'noun' => 'kategori', 'fields' => ['name']];

    protected $fillable = ['name'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
