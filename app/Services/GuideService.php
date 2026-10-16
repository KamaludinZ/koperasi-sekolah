<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\GuideCatalog;
use App\Support\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
     * Semua panduan efektif untuk satu kelompok pembaca (urut posisi), dibaca dari guide_entries + suntingan; bila
     * tabel belum terisi (mis. sebelum seeder dijalankan) memakai katalog bawaan. Isi dirapikan dari kembaran lewat
     * GuideMerger::dedupe.
     *
     * @return array<string, array> kunci → panduan (bentuk GuideCatalog + overridden, stale)
     */
    public function all(string $audience): array
    {
        $rows = GuideEntry::for($audience)->with('overrides')->get();
        $merger = new GuideMerger($this); // isi tampil tanpa kembaran (langkah/tips ganda, tautan terkait yang sudah di langkah)
        if ($rows->isEmpty()) {
            return array_map(fn ($e) => $merger->dedupe($e) + ['overridden' => [], 'stale' => false], GuideCatalog::forAudience($audience));
        }

        return $rows->mapWithKeys(fn (GuideEntry $e) => [$e->key => $merger->dedupe($this->effective($e)) + ['stale' => $e->is_stale]])->all();
    }

    /** Nama peran pratinjau yang sah dari masukan bebas "kasir,pengurus" (dikenal, unik, urut prioritas). @return list<string> */
    public function previewRoles(mixed $raw): array
    {
        $names = is_string($raw) ? array_map('trim', explode(',', mb_substr($raw, 0, 100))) : [];

        return Role::sortNames(array_values(array_unique(array_intersect($names, array_keys(Role::LABELS)))));
    }

    /**
     * Pembaca panduan: petugas yang masuk, atau pengguna tiruan berizin gabungan peran pratinjau (roles.permissions
     * saat ini). Pratinjau hanya dipakai bila diminta & diizinkan pemanggil.
     *
     * @param  ?array  $user  bentuk Petugas::current()
     * @param  list<string>  $previewRoles
     * @return array{role: ?string, roles: list<string>, permissions: list<string>, is_active: bool, mock?: bool}
     */
    public function viewer(?array $user, array $previewRoles = []): array
    {
        if ($previewRoles !== []) {
            $merged = app(PermissionMerger::class)->mergeNames($previewRoles);

            return ['role' => $merged['primary'], 'roles' => $merged['roles'], 'permissions' => $merged['permissions'], 'is_active' => true, 'mock' => true];
        }

        return $user ?? ['role' => null, 'roles' => [], 'permissions' => [], 'is_active' => false];
    }

    /** Peran milik pembaca (urut prioritas). @return list<string> */
    public function rolesOf(array $viewer): array
    {
        return array_values(($viewer['roles'] ?? []) ?: array_filter([$viewer['role'] ?? null]));
    }

    /**
     * Peran pembaca yang membuka menu ini — hanya bila pembaca multi-peran dan tidak semua perannya memiliki menu itu.
     *
     * @param  array<string, list<string>>  $sources  Menu::sourcesFor($viewer)
     * @return list<string>
     */
    public function via(array $viewer, array $sources, ?string $menu): array
    {
        $mine = $this->rolesOf($viewer);
        $from = $menu !== null ? ($sources[$menu] ?? []) : [];

        return count($mine) > 1 && $from && count($from) < count($mine) ? $from : [];
    }

    /** Peran yang membuka halaman langkah ini bila pembaca tidak bisa (null = terbuka/tanpa tautan). @return ?list<string> */
    public function stepLock(array $viewer, ?string $route): ?array
    {
        if ($route === null || $route === '' || Menu::canReach($route, $viewer)) {
            return null;
        }

        return Menu::denial($route, $viewer)['suggest'] ?? [];
    }

    /**
     * Urutkan kunci panduan seperti menu samping: kelompok (Layanan harian → Data koperasi → Pengurus → Lainnya →
     * Umum), lalu urutan menu, menu terbatas di belakang kelompoknya; tidak bergantung kolom position.
     *
     * @param  array<string, array>  $guides
     * @param  callable(string): bool  $allowed
     * @return list<string>
     */
    public function orderedKeys(array $guides, callable $allowed): array
    {
        $groupRank = array_flip([...array_values(Menu::GROUPS), 'Lainnya', 'Umum']);
        $menuRank = array_flip(array_keys(Menu::ITEMS));
        $entryRank = array_flip(array_keys($guides));
        $rank = fn (string $k) => [$groupRank[GuideCatalog::groupFor($guides[$k]['menu'] ?? null)] ?? 99,
            ! $allowed($k), $menuRank[$guides[$k]['menu'] ?? ''] ?? 999, $entryRank[$k]];
        $keys = array_keys($guides);
        usort($keys, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $keys;
    }

    /** Petugas/anggota boleh membuka menu panduan ini (panduan umum & anggota selalu boleh). */
    public function allowed(?array $user, array $guide): bool
    {
        return $guide['menu'] === null || ($user !== null && Menu::userCan($user, $guide['menu']));
    }

    /**
     * Daftar panduan untuk pembaca (urut seperti menu samping): hanya menu yang boleh dibuka (gabungan izin semua
     * peran), opsional juga menu terbatas (ditandai, TANPA langkah/tips/tautan), opsional disaring kata kunci (semua
     * kata harus muncul) dan disaring ke satu peran milik pembaca (menu peran lain disembunyikan; panduan umum tetap).
     *
     * @param  ?array  $viewer  bentuk Petugas::current() / viewer() (null untuk anggota portal)
     * @return list<array> lihat present()
     */
    public function listFor(?array $viewer, string $audience, bool $includeRestricted = false, string $search = '', ?string $role = null): array
    {
        $words = array_filter(explode(' ', Str::lower(Str::ascii(trim($search)))));
        $all = $this->all($audience);
        $sources = $viewer ? Menu::sourcesFor($viewer) : [];
        $grants = $includeRestricted ? $this->grantMap() : [];
        $allowed = array_map(fn ($g) => $this->allowed($viewer, $g), $all);
        $out = [];
        foreach ($this->orderedKeys($all, fn ($k) => $allowed[$k]) as $key) {
            $g = $all[$key];
            if (! $allowed[$key] && ! $includeRestricted) {
                continue;
            }
            if ($role !== null && $role !== '' && $g['menu'] !== null && $allowed[$key] && ! in_array($role, $sources[$g['menu']] ?? [], true)) {
                continue;
            }
            $hay = GuideCatalog::haystack($key, $g);
            if ($words && collect($words)->contains(fn ($w) => ! str_contains($hay, $w))) {
                continue;
            }
            $out[] = $this->present($key, $g, $allowed[$key], $viewer, $sources, $grants);
        }

        return $out;
    }

    /** Peran pemegang tiap menu dari roles.permissions dalam satu kueri (hak bawaan bila tabel belum ada). @return array<string, list<string>> */
    public function grantMap(): array
    {
        $map = array_fill_keys(array_keys(Menu::ITEMS), []);
        try {
            $perms = Role::query()->pluck('permissions', 'name')->map(fn ($p) => is_array($p) ? $p : (json_decode((string) $p, true) ?: []))->all();
        } catch (\Throwable) {
            $perms = Menu::DEFAULT_PERMISSIONS;
        }
        foreach (Role::sortNames(array_keys($perms)) as $role) {
            foreach ((array) $perms[$role] as $menu) {
                if (is_string($menu) && isset($map[$menu])) {
                    $map[$menu][] = $role;
                }
            }
        }

        return $map;
    }

    /**
     * Penanda terbatas: kenapa menu panduan tidak bisa dibuka pembaca, peran yang membukanya, dan pesan siap tampil.
     * Alasan: akun_nonaktif (akun dinonaktifkan), belum_diberikan (tidak ada peran yang memegang menu), peran (menu milik
     * peran lain).
     *
     * @param  list<string>  $required
     * @return array{reason: string, message: string, required_roles: list<string>, required_role_labels: list<string>}
     */
    public function restriction(?array $viewer, string $menu, array $required): array
    {
        $label = Menu::ITEMS[$menu]['label'] ?? $menu;
        $labels = array_map(fn ($r) => Role::LABELS[$r] ?? $r, $required);
        $list = collect($labels)->join(', ', ' dan ');
        [$reason, $message] = match (true) {
            $viewer !== null && ($viewer['is_active'] ?? true) === false => ['akun_nonaktif', 'Akun nonaktif tidak dapat membuka menu apa pun. Hubungi admin koperasi.'],
            $required === [] => ['belum_diberikan', "Menu {$label} belum diberikan ke peran mana pun."],
            default => ['peran', "Menu {$label} hanya untuk peran {$list}.".(($viewer['mock'] ?? false)
                ? ' Menu ini tidak termasuk peran yang ditinjau.'
                : ' Bila Anda memerlukannya, minta admin koperasi menambahkan peran tersebut ke akun Anda.')],
        };

        return ['reason' => $reason, 'message' => $message, 'required_roles' => $required, 'required_role_labels' => $labels];
    }

    /**
     * Satu panduan siap dikirim. Terbatas: tanpa langkah/tips/tautan, dengan penanda restriction (alasan, pesan, peran
     * yang dibutuhkan). Terbuka (petugas): peran pembaca yang membukanya (via), langkah ber-tanda locked_for bila
     * halamannya khusus peran lain (jumlahnya di locked_steps), dan halaman terkait hanya yang bisa dibuka pembaca.
     *
     * @param  array<string, list<string>>  $grants  grantMap() bila sudah dimuat (hemat kueri untuk daftar)
     */
    public function present(string $key, array $g, bool $allowed, ?array $viewer = null, array $sources = [], array $grants = []): array
    {
        $base = [
            'key' => $key, 'menu' => $g['menu'], 'menu_label' => $g['menu'] ? (Menu::ITEMS[$g['menu']]['label'] ?? $g['menu']) : ($g['audience'] === 'anggota' ? 'Portal' : 'Akun saya'),
            'group' => GuideCatalog::groupFor($g['menu']), 'title' => $g['title'], 'summary' => $g['summary'],
            'allowed' => $allowed, 'restricted' => ! $allowed, 'overridden' => $g['overridden'] ?? [], 'stale' => (bool) ($g['stale'] ?? false),
        ];
        if (! $allowed) {
            $required = $grants[$g['menu']] ?? Menu::grantingRoles($g['menu']);

            return $base + ['required_roles' => $required, 'restriction' => $this->restriction($viewer, $g['menu'], $required)];
        }
        $steps = $g['steps'];
        $related = $g['related'] ?? [];
        if ($viewer !== null && $g['audience'] === 'petugas') {
            $steps = array_map(function ($s) use ($viewer) {
                $lock = $this->stepLock($viewer, $s['route'] ?? null);

                return $lock === null ? $s : $s + ['locked_for' => $lock];
            }, $steps);
            $related = array_filter($related, fn ($label, $route) => Menu::canReach($route, $viewer), ARRAY_FILTER_USE_BOTH);
            $base['via'] = $this->via($viewer, $sources, $g['menu']);
        }

        return $base + ['steps' => $steps, 'tips' => $g['tips'], 'related' => $related, 'step_count' => count($steps),
            'locked_steps' => count(array_filter($steps, fn ($s) => isset($s['locked_for'])))];
    }

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
            $before = $this->effective($entry)[$field];
            $o = GuideOverride::updateOrCreate(['guide_entry_id' => $entry->id, 'field' => $field], ['value' => $value, 'updated_by' => $by?->id]);
            $entry->unsetRelation('overrides');
            $version = $this->recordVersion($entry, 'override', $note ?? 'Bagian '.mb_strtolower(GuideOverride::FIELDS[$field]).' disunting', $by);
            if ($version) {
                $this->audit('guide.overridden', $entry, $field, $before, $value, $version, $note);
            }

            return $o;
        });
    }

    /** Lepas suntingan satu bagian sehingga kembali ke isi otomatis; catat versi. Tidak ada suntingan → false. */
    public function clearOverride(GuideEntry $entry, string $field, ?User $by, ?string $note = null): bool
    {
        return DB::transaction(function () use ($entry, $field, $by, $note) {
            $before = $this->effective($entry)[$field] ?? null;
            $deleted = GuideOverride::where('guide_entry_id', $entry->id)->where('field', $field)->delete() > 0;
            if ($deleted) {
                $entry->unsetRelation('overrides');
                $version = $this->recordVersion($entry, 'revert', $note ?? 'Bagian '.mb_strtolower(GuideOverride::FIELDS[$field] ?? $field).' dikembalikan ke hasil otomatis', $by);
                $this->audit('guide.reverted', $entry, $field, $before, $this->effective($entry)[$field], $version, $note);
            }

            return $deleted;
        });
    }

    /** Tandai panduan perlu ditinjau ulang (alasan 5–200 karakter), dicatat di jejak audit. @throws ValidationException */
    public function markStale(GuideEntry $entry, string $reason, ?User $by = null): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Alasan 5–200 karakter.']);
        }
        $entry->forceFill(['is_stale' => true, 'stale_reason' => $reason, 'stale_at' => $entry->is_stale ? ($entry->stale_at ?? now()) : now()])->save();
        AuditLog::record('guide.stale_marked', 'panduan', "Panduan “{$entry->title}” ditandai perlu ditinjau",
            ['type' => 'guide', 'id' => $entry->id, 'label' => $entry->key], [], actor: $by ? ['id' => $by->id, 'name' => $by->name, 'role' => $by->role?->name] : null,
            action: 'diubah', new: ['stale_reason' => $reason], reason: $reason);
    }

    /** Lepas penanda usang (sudah ditinjau), opsional dengan catatan; false bila panduan tidak sedang usang. */
    public function markReviewed(GuideEntry $entry, ?User $by = null, ?string $note = null): bool
    {
        if (! $entry->is_stale) {
            return false;
        }
        $old = $entry->stale_reason;
        $entry->forceFill(['is_stale' => false, 'stale_reason' => null, 'stale_at' => null])->save();
        AuditLog::record('guide.reviewed', 'panduan', "Panduan “{$entry->title}” sudah ditinjau",
            ['type' => 'guide', 'id' => $entry->id, 'label' => $entry->key], [], actor: $by ? ['id' => $by->id, 'name' => $by->name, 'role' => $by->role?->name] : null,
            action: 'diubah', old: ['stale_reason' => $old], reason: $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null);

        return true;
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

    /** Catat perubahan panduan di jejak audit ber-HMAC (siapa, panduan & bagian mana, sebelum → sesudah, versi). */
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
