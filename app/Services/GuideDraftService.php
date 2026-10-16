<?php

namespace App\Services;

use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draf panduan yang menunggu tinjauan (guide_drafts): penyusun otomatis menyimpan draf di sini alih-alih menimpa
 * panduan manual. Pengelola menerapkan draf (isi otomatis panduan diganti, panduan kembali bersumber otomatis,
 * versi & audit dicatat) atau membuangnya (dengan alasan). Draf basi — isi panduan sudah berubah sejak draf dibuat —
 * ditolak saat diterapkan.
 */
class GuideDraftService
{
    public const CONTENT = ['title', 'summary', 'steps', 'tips', 'related'];

    public function __construct(private GuideService $guides) {}

    /** Sidik jari isi otomatis tersimpan satu panduan. */
    public function contentHash(GuideEntry $entry): string
    {
        return hash('sha256', json_encode(array_intersect_key($entry->toGuide(), array_flip(self::CONTENT)), JSON_UNESCAPED_UNICODE));
    }

    /** Simpan/perbarui draf pending untuk satu panduan (satu draf pending per panduan). */
    public function stage(string $key, array $draft, array $changedFields, ?string $reason = null, ?GuideSyncJob $job = null): GuideDraft
    {
        $entry = GuideEntry::where('key', $key)->first();

        return GuideDraft::updateOrCreate(['key' => $key, 'status' => 'pending'], [
            'guide_entry_id' => $entry?->id, 'payload' => $draft, 'changed_fields' => array_values($changedFields),
            'base_hash' => $entry ? $this->contentHash($entry) : null, 'reason' => $reason !== null ? mb_substr($reason, 0, 200) : null,
            'sync_job_id' => $job?->id,
        ]);
    }

    /** Isi panduan sudah berubah sejak draf dibuat? */
    public function isOutdated(GuideDraft $draft): bool
    {
        $entry = $draft->guide_entry_id ? GuideEntry::find($draft->guide_entry_id) : GuideEntry::where('key', $draft->key)->first();

        return $draft->base_hash === null ? $entry !== null : ($entry === null || $this->contentHash($entry) !== $draft->base_hash);
    }

    /** Terapkan draf pending. @throws ValidationException */
    public function apply(GuideDraft $draft, ?User $by, ?string $note = null): GuideEntry
    {
        return DB::transaction(function () use ($draft, $by, $note) {
            $draft = GuideDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'pending') {
                throw ValidationException::withMessages(['draft' => 'Draf ini sudah ditinjau.']);
            }
            if ($this->isOutdated($draft)) {
                throw ValidationException::withMessages(['draft' => 'Isi panduan sudah berubah sejak draf dibuat. Susun ulang draf lebih dulu.']);
            }
            $p = $draft->payload;
            $values = [
                'menu_key' => $p['menu'] ?? null, 'audience' => $p['audience'] ?? 'petugas', 'title' => $p['title'], 'summary' => $p['summary'],
                'steps' => $p['steps'], 'tips' => $p['tips'] ?? [], 'related' => $p['related'] ?? [], 'keywords' => $p['keywords'] ?? null,
                'required_permissions' => $p['required_permissions'] ?? [], 'source' => 'auto', 'generated_at' => now(),
                'is_stale' => false, 'stale_reason' => null, 'stale_at' => null,
            ];
            $entry = GuideEntry::where('key', $draft->key)->lockForUpdate()->first();
            $entry ? $entry->fill($values)->save() : $entry = GuideEntry::create(['key' => $draft->key, 'position' => (int) GuideEntry::max('position') + 1] + $values);
            $version = $this->guides->recordVersion($entry, 'generate', $note ?? "Draf #{$draft->id} diterapkan", $by, $draft->sync_job_id);
            $draft->update(['status' => 'applied', 'reviewed_by' => $by?->id, 'reviewed_at' => now(), 'guide_entry_id' => $entry->id]);
            AuditLog::record('guide.draft_applied', 'panduan', "Draf panduan “{$entry->title}” diterapkan".($version ? " (versi {$version->version})" : ''),
                ['type' => 'guide', 'id' => $entry->id, 'label' => $entry->key], ['draft_id' => $draft->id, 'fields' => $draft->changed_fields],
                action: 'diubah', reason: $note);

            return $entry;
        });
    }

    /** Buang draf pending dengan alasan (5–200 karakter). @throws ValidationException */
    public function discard(GuideDraft $draft, ?User $by, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Alasan 5–200 karakter.']);
        }
        DB::transaction(function () use ($draft, $by, $reason) {
            $draft = GuideDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'pending') {
                throw ValidationException::withMessages(['draft' => 'Draf ini sudah ditinjau.']);
            }
            $draft->update(['status' => 'discarded', 'reviewed_by' => $by?->id, 'reviewed_at' => now()]);
            AuditLog::record('guide.draft_discarded', 'panduan', "Draf panduan {$draft->key} dibuang",
                ['type' => 'guide', 'id' => $draft->guide_entry_id, 'label' => $draft->key], ['draft_id' => $draft->id], action: 'diubah', reason: $reason);
        });
    }
}
