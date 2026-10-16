<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Inti Pusat Panduan: isi efektif = isi otomatis guide_entries ditimpa suntingan manual guide_overrides per bagian
 * (title, summary, steps, tips). Memasang/melepas suntingan selalu menulis versi baru di guide_versions (cuplikan isi
 * efektif) — versi yang isinya sama dengan versi terakhir tidak dicatat dua kali.
 */
class GuideService
{
    public const MAX = ['title' => 150, 'summary' => 1000, 'step' => 400, 'steps' => 20, 'tip' => 300, 'tips' => 10];

    /**
     * Isi efektif satu panduan (bentuk sama dengan GuideCatalog) + daftar bagian yang sedang disunting manual.
     *
     * @return array{menu: ?string, audience: string, title: string, summary: string, steps: list<array>, tips: list<string>, related: array, keywords: ?string, overridden: list<string>}
     */
    public function effective(GuideEntry $entry): array
    {
        $guide = $entry->toGuide();
        $overrides = $entry->relationLoaded('overrides') ? $entry->overrides : $entry->overrides()->get();
        foreach ($overrides as $o) {
            if (array_key_exists($o->field, GuideOverride::FIELDS)) {
                $guide[$o->field] = $o->value;
            }
        }
        $guide['overridden'] = $overrides->pluck('field')->values()->all();

        return $guide;
    }

    /** Cuplikan isi yang disimpan sebagai versi (bagian yang bisa berubah). */
    public function snapshot(GuideEntry $entry): array
    {
        return array_intersect_key($this->effective($entry), array_flip(['title', 'summary', 'steps', 'tips', 'overridden']));
    }

    /**
     * Pasang suntingan manual untuk satu bagian (divalidasi & dirapikan), lalu catat versi.
     *
     * @throws ValidationException
     */
    public function setOverride(GuideEntry $entry, string $field, mixed $value, ?User $by, ?string $note = null): GuideOverride
    {
        $value = $this->clean($field, $value);

        return DB::transaction(function () use ($entry, $field, $value, $by, $note) {
            $o = GuideOverride::updateOrCreate(['guide_entry_id' => $entry->id, 'field' => $field], ['value' => $value, 'updated_by' => $by?->id]);
            $entry->unsetRelation('overrides');
            $this->recordVersion($entry, 'override', $note ?? 'Bagian '.mb_strtolower(GuideOverride::FIELDS[$field]).' disunting', $by);

            return $o;
        });
    }

    /** Lepas suntingan satu bagian sehingga kembali ke isi otomatis; catat versi. Tidak ada suntingan → false. */
    public function clearOverride(GuideEntry $entry, string $field, ?User $by, ?string $note = null): bool
    {
        return DB::transaction(function () use ($entry, $field, $by, $note) {
            $deleted = GuideOverride::where('guide_entry_id', $entry->id)->where('field', $field)->delete() > 0;
            if ($deleted) {
                $entry->unsetRelation('overrides');
                $this->recordVersion($entry, 'revert', $note ?? 'Bagian '.mb_strtolower(GuideOverride::FIELDS[$field] ?? $field).' dikembalikan ke hasil otomatis', $by);
            }

            return $deleted;
        });
    }

    /**
     * Catat versi baru bila isi efektif berbeda dari versi terakhir (nomor urut dikunci per panduan).
     */
    public function recordVersion(GuideEntry $entry, string $type, ?string $note, ?User $by): ?GuideVersion
    {
        return DB::transaction(function () use ($entry, $type, $note, $by) {
            GuideEntry::whereKey($entry->id)->lockForUpdate()->first(); // serialisasi penomoran versi
            $last = GuideVersion::where('guide_entry_id', $entry->id)->orderByDesc('version')->first();
            $snap = $this->snapshot($entry);
            if ($last && $last->snapshot == $snap) {
                return null;
            }

            return GuideVersion::create([
                'guide_entry_id' => $entry->id, 'version' => ($last->version ?? 0) + 1, 'snapshot' => $snap,
                'change_type' => $type, 'change_note' => $note !== null ? mb_substr(trim($note), 0, 200) : null,
                'changed_by' => $by?->id, 'created_at' => now(),
            ]);
        });
    }

    /** Rapikan & validasi nilai suntingan per bagian. @throws ValidationException */
    private function clean(string $field, mixed $value): mixed
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['value' => $m]);
        $text = fn ($v) => is_string($v) ? trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $v))) : null;

        return match ($field) {
            'title' => (($t = $text($value)) !== null && $t !== '' && mb_strlen($t) <= self::MAX['title']) ? $t : $fail('Judul wajib diisi, maksimal '.self::MAX['title'].' karakter.'),
            'summary' => (($t = $text($value)) !== null && mb_strlen($t) >= 10 && mb_strlen($t) <= self::MAX['summary']) ? $t : $fail('Ringkasan 10–'.self::MAX['summary'].' karakter.'),
            'steps' => $this->cleanSteps($value, $fail, $text),
            'tips' => $this->cleanTips($value, $fail, $text),
            default => $fail('Bagian panduan tidak dikenal.'),
        };
    }

    private function cleanSteps(mixed $value, callable $fail, callable $text): array
    {
        if (! is_array($value)) {
            $fail('Langkah harus berupa daftar.');
        }
        $steps = [];
        foreach ($value as $s) {
            $t = $text(is_array($s) ? ($s['text'] ?? null) : $s);
            if ($t === null || $t === '') {
                continue;
            }
            if (mb_strlen($t) > self::MAX['step']) {
                $fail('Satu langkah maksimal '.self::MAX['step'].' karakter.');
            }
            $route = is_array($s) && is_string($s['route'] ?? null) && Route::has($s['route']) ? $s['route'] : null;
            $steps[] = array_filter(['text' => $t, 'route' => $route]);
        }
        if ($steps === [] || count($steps) > self::MAX['steps']) {
            $fail('Isi 1–'.self::MAX['steps'].' langkah.');
        }

        return $steps;
    }

    private function cleanTips(mixed $value, callable $fail, callable $text): array
    {
        if (! is_array($value)) {
            $fail('Tips harus berupa daftar.');
        }
        $tips = array_values(array_filter(array_map($text, $value), fn ($t) => $t !== null && $t !== ''));
        if (count($tips) > self::MAX['tips'] || collect($tips)->contains(fn ($t) => mb_strlen($t) > self::MAX['tip'])) {
            $fail('Maksimal '.self::MAX['tips'].' tips, masing-masing '.self::MAX['tip'].' karakter.');
        }

        return $tips;
    }
}
