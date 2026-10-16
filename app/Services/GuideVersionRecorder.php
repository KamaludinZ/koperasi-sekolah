<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pencatatan versi panduan setiap kali isinya disimpan: cuplikan isi EFEKTIF (otomatis + suntingan) jadi versi
 * bernomor urut per panduan (penomoran dikunci per panduan), dengan jenis, catatan, pengubah, proses sinkronisasi &
 * sumber pemulihan. Versi yang isinya sama dengan versi terakhir (sidik jari kanonis) tidak dicatat dua kali.
 *
 * Jaring pengaman (GuideContentObserver): isi panduan atau suntingan yang disimpan di luar service (mis. lewat model
 * langsung) tetap tercatat sebagai versi setelah transaksi selesai — catatan eksplisit dari service yang sudah
 * mencatat isi yang sama lebih dulu membuat pencatatan cadangan itu dilewati.
 */
class GuideVersionRecorder
{
    public function __construct(private GuideService $guides) {}

    public function record(GuideEntry $entry, string $type, ?string $note, ?User $by, ?int $syncJobId = null, ?int $restoredFrom = null): ?GuideVersion
    {
        return DB::transaction(function () use ($entry, $type, $note, $by, $syncJobId, $restoredFrom) {
            GuideEntry::whereKey($entry->id)->lockForUpdate()->first(); // serialisasi penomoran versi
            $entry->unsetRelation('overrides');
            $last = GuideVersion::where('guide_entry_id', $entry->id)->orderByDesc('version')->first();
            $snap = $this->guides->snapshot($entry);
            $hash = GuideVersion::hashOf($snap);
            if ($last && ($last->content_hash ?? GuideVersion::hashOf($last->snapshot)) === $hash) {
                return null; // isi sama dengan versi terakhir → tidak dicatat dua kali
            }

            return GuideVersion::create([
                'guide_entry_id' => $entry->id, 'version' => ($last->version ?? 0) + 1, 'snapshot' => $snap, 'content_hash' => $hash,
                'change_type' => $type, 'change_note' => $note !== null ? mb_substr(trim($note), 0, 200) : null,
                'changed_by' => $by?->id, 'sync_job_id' => $syncJobId, 'restored_from_version' => $restoredFrom, 'created_at' => now(),
            ]);
        });
    }

    /** Catat versi cadangan setelah transaksi selesai (dipakai observer) bila isi efektif belum tercatat. */
    public function recordAfterCommit(int $entryId, string $note): void
    {
        DB::afterCommit(function () use ($entryId, $note) {
            $entry = GuideEntry::find($entryId);
            if ($entry) {
                $this->record($entry, 'generate', $note, null);
            }
        });
    }
}
