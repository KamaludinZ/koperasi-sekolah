<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu versi isi efektif panduan (cuplikan otomatis + suntingan) — untuk riwayat, perbandingan & pemulihan. */
class GuideVersion extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['generate' => 'Disusun otomatis', 'override' => 'Disunting manual', 'revert' => 'Dikembalikan ke otomatis', 'restore' => 'Dipulihkan dari versi lama'];

    protected $fillable = ['guide_entry_id', 'version', 'snapshot', 'change_type', 'change_note', 'changed_by', 'sync_job_id', 'content_hash', 'restored_from_version', 'created_at'];

    /** Sidik jari kanonis cuplikan isi (kunci objek diurutkan; urutan daftar dipertahankan). */
    public static function hashOf(array $snapshot): string
    {
        $canon = function ($v) use (&$canon) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($canon, $v);
        };

        return hash('sha256', json_encode($canon($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(GuideEntry::class, 'guide_entry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
