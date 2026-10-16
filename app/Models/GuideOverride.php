<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Suntingan manual satu bagian panduan (lapisan override di atas isi otomatis guide_entries). */
class GuideOverride extends Model
{
    public const FIELDS = ['title' => 'Judul', 'summary' => 'Ringkasan', 'steps' => 'Langkah', 'tips' => 'Tips'];

    protected $fillable = ['guide_entry_id', 'field', 'value', 'base_hash', 'note', 'updated_by'];

    /** Sidik jari isi otomatis satu bagian (dibandingkan dengan base_hash). */
    public static function hashOf(mixed $autoValue): string
    {
        return hash('sha256', json_encode($autoValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Isi otomatis bagian ini sudah berubah sejak disunting? (null bila tidak diketahui — suntingan lama). */
    public function autoChanged(GuideEntry $entry): ?bool
    {
        return $this->base_hash === null ? null : self::hashOf($entry->toGuide()[$this->field] ?? null) !== $this->base_hash;
    }

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
