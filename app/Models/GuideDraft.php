<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Draf panduan otomatis yang menunggu ditinjau (tabel guide_drafts). */
class GuideDraft extends Model
{
    public const STATUSES = ['pending' => 'Menunggu tinjauan', 'applied' => 'Diterapkan', 'discarded' => 'Dibuang'];

    protected $fillable = ['key', 'guide_entry_id', 'payload', 'changed_fields', 'base_hash', 'reason', 'status', 'sync_job_id', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'changed_fields' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(GuideEntry::class, 'guide_entry_id');
    }

    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(GuideSyncJob::class, 'sync_job_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
