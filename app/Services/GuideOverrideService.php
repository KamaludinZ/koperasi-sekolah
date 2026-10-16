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
 * Simpan suntingan manual panduan per bagian (title, summary, steps, tips) di lapisan override:
 * - normalize(): merapikan & memvalidasi isi tiap bagian (spasi, batas panjang, langkah tanpa teks dibuang, tautan
 *   halaman hanya rute yang ada);
 * - saveMany(): beberapa bagian sekaligus dalam SATU transaksi & SATU versi — semua divalidasi dulu (galat per bagian),
 *   lalu dicek bentrok: bila $expected diberikan dan isi efektif bagian sudah berubah sejak dibuka (disunting orang
 *   lain), penyimpanan ditolak; setiap bagian yang isinya berubah dicatat di audit & riwayat perubahan;
 * - revert(): kembalikan satu bagian ke hasil otomatis (versi, audit, riwayat).
 * fingerprint() memberi sidik jari isi efektif satu bagian untuk dipakai sebagai $expected.
 */
class GuideOverrideService
{
    public function __construct(private GuideService $guides) {}

    /** Sidik jari isi efektif satu bagian (untuk deteksi bentrok suntingan). */
    public function fingerprint(GuideEntry $entry, string $field): string
    {
        return GuideOverride::hashOf($this->guides->effective($entry)[$field] ?? null);
    }

    /** @return GuideOverride suntingan bagian itu */
    public function save(GuideEntry $entry, string $field, mixed $value, ?User $by, ?string $note = null, ?string $expected = null): GuideOverride
    {
        $this->saveMany($entry, [$field => $value], $by, $note, $expected !== null ? [$field => $expected] : []);

        return GuideOverride::where('guide_entry_id', $entry->id)->where('field', $field)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $values  bagian → isi baru
     * @param  array<string, string>  $expected  bagian → sidik jari isi efektif saat penyunting membuka
     * @return array{version: ?GuideVersion, changed: list<string>}
     *
     * @throws ValidationException galat per bagian (kunci = nama bagian)
     */
    public function saveMany(GuideEntry $entry, array $values, ?User $by, ?string $note = null, array $expected = []): array
    {
        $clean = [];
        $errors = [];
        foreach ($values as $field => $value) {
            try {
                $clean[$field] = $this->normalize($field, $value);
            } catch (ValidationException $e) {
                $errors[$field] = collect($e->errors())->flatten()->first();
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;

        return DB::transaction(function () use ($entry, $clean, $by, $note, $expected) {
            GuideEntry::whereKey($entry->id)->lockForUpdate()->first(); // serialisasi suntingan panduan yang sama
            $entry->unsetRelation('overrides');
            $before = $this->guides->effective($entry);
            $conflicts = [];
            foreach ($expected as $field => $hash) {
                if (array_key_exists($field, $clean) && GuideOverride::hashOf($before[$field] ?? null) !== $hash) {
                    $conflicts[$field] = 'Bagian ini sudah diubah orang lain sejak Anda membukanya. Muat ulang lalu sunting lagi.';
                }
            }
            if ($conflicts) {
                throw ValidationException::withMessages($conflicts);
            }
            $auto = $entry->toGuide();
            foreach ($clean as $field => $value) {
                GuideOverride::updateOrCreate(['guide_entry_id' => $entry->id, 'field' => $field], [
                    'value' => $value, 'updated_by' => $by?->id, 'note' => $note, 'base_hash' => GuideOverride::hashOf($auto[$field] ?? null),
                ]);
            }
            $entry->unsetRelation('overrides');
            $labels = implode(', ', array_map(fn ($f) => mb_strtolower(GuideOverride::FIELDS[$f]), array_keys($clean)));
            $version = $this->guides->recordVersion($entry, 'override', $note ?? 'Bagian '.$labels.' disunting', $by);
            $changed = [];
            if ($version) {
                foreach ($clean as $field => $value) {
                    $this->audit('guide.overridden', $entry, $field, $before[$field], $value, $version, $note);
                    if ($before[$field] != $value) { // isi bagian benar-benar berubah (bukan hanya ditandai disunting)
                        $this->guides->logChange($entry, 'override', $field, $before[$field], $value, $note, $by, $version);
                        $changed[] = $field;
                    }
                }
            }

            return ['version' => $version, 'changed' => $changed];
        });
    }

    /**
     * Lepas suntingan satu bagian sehingga kembali ke isi otomatis; false bila tidak ada suntingan.
     *
     * @throws ValidationException bila $expected diberikan dan isi bagian sudah diubah orang lain
     */
    public function revert(GuideEntry $entry, string $field, ?User $by, ?string $note = null, ?string $expected = null): bool
    {
        return $this->revertMany($entry, [$field], $by, $note, $expected !== null ? [$field => $expected] : []) !== [];
    }

    /**
     * Kembalikan beberapa bagian (bawaan: semua yang disunting) ke hasil otomatis dalam satu transaksi & SATU versi.
     *
     * @param  ?list<string>  $fields
     * @param  array<string, string>  $expected  bagian → sidik jari isi efektif saat dibuka
     * @return list<string> bagian yang benar-benar dikembalikan
     *
     * @throws ValidationException bentrok (kunci = bagian)
     */
    public function revertMany(GuideEntry $entry, ?array $fields, ?User $by, ?string $note = null, array $expected = []): array
    {
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;

        return DB::transaction(function () use ($entry, $fields, $by, $note, $expected) {
            GuideEntry::whereKey($entry->id)->lockForUpdate()->first();
            $entry->unsetRelation('overrides');
            $before = $this->guides->effective($entry);
            $conflicts = array_filter($expected, fn ($hash, $f) => GuideOverride::hashOf($before[$f] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH);
            if ($conflicts) {
                throw ValidationException::withMessages(array_map(fn () => 'Bagian ini sudah diubah orang lain sejak Anda membukanya. Muat ulang dulu.', $conflicts));
            }
            $query = GuideOverride::where('guide_entry_id', $entry->id)->when($fields !== null, fn ($q) => $q->whereIn('field', $fields));
            $reverted = array_values(array_intersect(array_keys(GuideOverride::FIELDS), $query->pluck('field')->all())); // urutan bagian baku
            if ($reverted === []) {
                return [];
            }
            GuideOverride::where('guide_entry_id', $entry->id)->whereIn('field', $reverted)->delete();
            $entry->unsetRelation('overrides');
            $labels = implode(', ', array_map(fn ($f) => mb_strtolower(GuideOverride::FIELDS[$f] ?? $f), $reverted));
            $version = $this->guides->recordVersion($entry, 'revert', $note ?? 'Bagian '.$labels.' dikembalikan ke hasil otomatis', $by);
            $after = $this->guides->effective($entry);
            foreach ($reverted as $f) {
                $this->audit('guide.reverted', $entry, $f, $before[$f], $after[$f], $version, $note);
                $this->guides->logChange($entry, 'revert', $f, $before[$f], $after[$f], $note, $by, $version);
            }

            return $reverted;
        });
    }

    /**
     * Pulihkan isi panduan ke versi lama: lapisan override disusun ulang agar isi yang tampil sama dengan cuplikan versi
     * itu — bagian yang sama dengan isi otomatis sekarang dilepas suntingannya, sisanya disimpan sebagai suntingan.
     * Satu versi baru berjenis "restore", audit guide.restored & riwayat perubahan per bagian. Alasan wajib (5–200).
     * $expectedHash (sidik jari isi saat dibuka, GuideVersion::hashOf) menolak pemulihan bila isi sudah diubah orang lain.
     *
     * @return array{version: ?GuideVersion, changed: list<string>}
     *
     * @throws ValidationException
     */
    public function restore(GuideEntry $entry, GuideVersion $target, ?User $by, string $reason, ?string $expectedHash = null): array
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan pemulihan (5–200 karakter).']);
        }
        if ($target->guide_entry_id !== $entry->id) {
            throw ValidationException::withMessages(['version' => 'Versi bukan milik panduan ini.']);
        }

        return DB::transaction(function () use ($entry, $target, $by, $reason, $expectedHash) {
            GuideEntry::whereKey($entry->id)->lockForUpdate()->first();
            $entry->unsetRelation('overrides');
            if ($expectedHash !== null && GuideVersion::hashOf($this->guides->snapshot($entry)) !== $expectedHash) {
                throw ValidationException::withMessages(['sidik' => 'Isi panduan sudah berubah sejak Anda membukanya. Muat ulang lalu ulangi pemulihan.']);
            }
            $before = $this->guides->effective($entry);
            $auto = $entry->toGuide();
            $changed = [];
            foreach (array_keys(GuideOverride::FIELDS) as $f) {
                $want = $target->snapshot[$f] ?? $auto[$f];
                if ($want == $before[$f]) {
                    continue;
                }
                $want == $auto[$f]
                    ? GuideOverride::where('guide_entry_id', $entry->id)->where('field', $f)->delete()
                    : GuideOverride::updateOrCreate(['guide_entry_id' => $entry->id, 'field' => $f], [
                        'value' => $want, 'updated_by' => $by?->id, 'note' => mb_substr("Dipulihkan dari versi {$target->version}: {$reason}", 0, 200), 'base_hash' => GuideOverride::hashOf($auto[$f]),
                    ]);
                $changed[] = $f;
            }
            if ($changed === []) {
                return ['version' => null, 'changed' => []];
            }
            $entry->unsetRelation('overrides');
            $version = $this->guides->recordVersion($entry, 'restore', mb_substr("Dipulihkan dari versi {$target->version}: {$reason}", 0, 200), $by, null, $target->version);
            $after = $this->guides->effective($entry);
            foreach ($changed as $f) {
                $this->guides->logChange($entry, 'restore', $f, $before[$f], $after[$f], $reason, $by, $version);
            }
            AuditLog::record('guide.restored', 'panduan', "Panduan “{$entry->title}” dipulihkan ke versi {$target->version}".($version ? " (menjadi versi {$version->version})" : ''),
                ['type' => 'guide', 'id' => $entry->id, 'label' => $entry->key], ['from_version' => $target->version, 'new_version' => $version?->version, 'fields' => $changed],
                action: 'diubah', reason: $reason);

            return ['version' => $version, 'changed' => $changed];
        });
    }

    /** Rapikan & validasi isi satu bagian. @throws ValidationException (kunci 'value') */
    public function normalize(string $field, mixed $value): mixed
    {
        $max = GuideService::MAX;
        $fail = fn (string $m) => throw ValidationException::withMessages(['value' => $m]);
        $text = fn ($v) => is_string($v) ? trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $v))) : null;

        return match ($field) {
            'title' => (($t = $text($value)) !== null && $t !== '' && mb_strlen($t) <= $max['title']) ? $t : $fail('Judul wajib diisi, maksimal '.$max['title'].' karakter.'),
            'summary' => (($t = $text($value)) !== null && mb_strlen($t) >= 10 && mb_strlen($t) <= $max['summary']) ? $t : $fail('Ringkasan 10–'.$max['summary'].' karakter.'),
            'steps' => $this->steps($value, $fail, $text),
            'tips' => $this->tips($value, $fail, $text),
            default => $fail('Bagian panduan tidak dikenal.'),
        };
    }

    private function steps(mixed $value, callable $fail, callable $text): array
    {
        $max = GuideService::MAX;
        if (! is_array($value)) {
            $fail('Langkah harus berupa daftar.');
        }
        $steps = [];
        foreach ($value as $s) {
            $t = $text(is_array($s) ? ($s['text'] ?? null) : $s);
            if ($t === null || $t === '') {
                continue;
            }
            if (mb_strlen($t) > $max['step']) {
                $fail('Satu langkah maksimal '.$max['step'].' karakter.');
            }
            $route = is_array($s) && is_string($s['route'] ?? null) && Route::has($s['route']) ? $s['route'] : null;
            $steps[] = array_filter(['text' => $t, 'route' => $route]);
        }
        if ($steps === [] || count($steps) > $max['steps']) {
            $fail('Isi 1–'.$max['steps'].' langkah.');
        }

        return $steps;
    }

    private function tips(mixed $value, callable $fail, callable $text): array
    {
        $max = GuideService::MAX;
        if (! is_array($value)) {
            $fail('Tips harus berupa daftar.');
        }
        $tips = array_values(array_filter(array_map($text, $value), fn ($t) => $t !== null && $t !== ''));
        if (count($tips) > $max['tips'] || collect($tips)->contains(fn ($t) => mb_strlen($t) > $max['tip'])) {
            $fail('Maksimal '.$max['tips'].' tips, masing-masing '.$max['tip'].' karakter.');
        }

        return $tips;
    }

    /** Jejak audit ber-HMAC (siapa, panduan & bagian, sebelum → sesudah ringkas, versi). */
    private function audit(string $event, GuideEntry $entry, string $field, mixed $before, mixed $after, ?GuideVersion $version, ?string $note): void
    {
        $label = GuideOverride::FIELDS[$field] ?? $field;
        $flat = fn ($v) => is_array($v) ? implode(' | ', array_map(fn ($x) => is_array($x) ? ($x['text'] ?? '') : (string) $x, $v)) : (string) $v;
        AuditLog::record($event, 'panduan',
            ($event === 'guide.overridden' ? "Panduan “{$entry->title}”: {$label} disunting" : "Panduan “{$entry->title}”: {$label} dikembalikan ke hasil otomatis")
                .($version ? " (versi {$version->version})" : ''),
            ['type' => 'guide', 'id' => $entry->id, 'label' => $entry->key],
            ['field' => $field, 'version' => $version?->version],
            action: 'diubah', old: [$field => mb_substr($flat($before), 0, 500)], new: [$field => mb_substr($flat($after), 0, 500)], reason: $note);
    }
}
