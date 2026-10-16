<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu perubahan panduan per bagian (tabel guide_changes, hanya ditambah). */
class GuideChange extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Panduan dibuat', 'generate' => 'Diperbarui otomatis', 'override' => 'Disunting manual', 'revert' => 'Dikembalikan ke otomatis',
        'draft' => 'Draf diterapkan', 'restore' => 'Dipulihkan dari versi lama', 'stale' => 'Ditandai perlu ditinjau', 'review' => 'Sudah ditinjau',
    ];

    public const FIELD_LABELS = GuideOverride::FIELDS + ['related' => 'Halaman terkait'];

    protected $fillable = ['guide_entry_id', 'guide_version_id', 'field', 'action', 'before', 'after', 'note', 'changed_by', 'sync_job_id', 'created_at'];

    protected function casts(): array
    {
        return ['before' => 'json', 'after' => 'json', 'created_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(GuideEntry::class, 'guide_entry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GuideVersion::class, 'guide_version_id');
    }

    /** Nilai sebagai baris teks (langkah/tips/halaman terkait jadi daftar). @return list<string> */
    public static function lines(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            return [(string) $value];
        }

        return array_values(array_map(fn ($v, $k) => is_array($v) ? (string) ($v['text'] ?? json_encode($v, JSON_UNESCAPED_UNICODE)) : (is_string($k) ? "{$v} ({$k})" : (string) $v), $value, array_keys($value)));
    }
}
