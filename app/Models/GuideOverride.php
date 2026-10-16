<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Suntingan manual satu bagian panduan (lapisan override di atas isi otomatis guide_entries). */
class GuideOverride extends Model
{
    public const FIELDS = ['title' => 'Judul', 'summary' => 'Ringkasan', 'steps' => 'Langkah', 'tips' => 'Tips'];

    protected $fillable = ['guide_entry_id', 'field', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(GuideEntry::class, 'guide_entry_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
