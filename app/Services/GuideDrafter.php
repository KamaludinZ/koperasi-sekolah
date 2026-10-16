<?php

namespace App\Services;

use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Support\GuideCatalog;

/**
 * Penyusun draf panduan otomatis dari fakta pemindai (GuideScanner) + isi kurasi katalog bila ada:
 * - judul, ringkasan & langkah: isi kurasi bila ada; menu tanpa kurasi mendapat ringkasan dari deskripsi menu
 *   (+ jumlah halaman & peran pemegangnya) dan langkah: buka menu → halaman untuk semua peran → halaman khusus peran;
 * - tips: isi kurasi; menu tanpa kurasi mendapat tips otomatis (halaman khusus peran, aksi yang butuh izin tambahan);
 * - halaman terkait: SEMUA halaman menu yang terbaca (hingga MAX_RELATED) yang belum ditautkan di langkah;
 * - kata kunci pencarian: kata kunci kurasi/label menu ditambah judul halaman yang terbaca.
 */
class GuideDrafter
{
    public const MAX_STEP_PAGES = 7;

    public const MAX_RELATED = 15;

    /**
     * @param  array{label: string, description: string, route: ?string, roles: list<string>, pages: list<array>, skipped_pages?: int}  $facts
     * @return array{menu: string, audience: string, title: string, summary: string, steps: list<array>, tips: list<string>, related: array<string, string>, keywords: string, required_permissions: list<string>}
     */
    public function draft(string $key, array $facts, ?array $curated = null): array
    {
        $steps = $curated['steps'] ?? $this->steps($facts);
        $linked = array_flip(array_filter(array_column($steps, 'route')));
        $related = array_diff_key($curated['related'] ?? [], $linked); // tanpa tautan yang sudah ada di langkah
        foreach ($facts['pages'] as $p) {
            if (count($related) >= self::MAX_RELATED) {
                break;
            }
            if ($p['name'] !== $facts['route'] && ! isset($related[$p['name']]) && ! isset($linked[$p['name']])) {
                $related[$p['name']] = $p['title'];
            }
        }

        return [
            'menu' => $key, 'audience' => 'petugas',
            'title' => $curated['title'] ?? 'Panduan menu '.$facts['label'],
            'summary' => $curated['summary'] ?? $this->summary($facts),
            'steps' => $steps,
            'tips' => $curated['tips'] ?? $this->tips($facts),
            'related' => $related,
            'keywords' => $this->keywords($key, $facts),
            'required_permissions' => [$key],
        ];
    }

    /** Panduan umum (akun) & anggota dari katalog — tidak bergantung menu. @return array<string, array> */
    public function general(): array
    {
        $out = [];
        foreach (GuideCatalog::entries() as $key => $e) {
            if ($e['menu'] === null) {
                $out[$key] = $e + ['keywords' => GuideCatalog::KEYWORDS[$key] ?? null, 'required_permissions' => []];
            }
        }

        return $out;
    }

    private function roleNames(array $raw): array
    {
        return EnsureRole::parse($raw)['roles'];
    }

    private function labels(array $roles, string $glue = ' / '): string
    {
        return collect($roles)->map(fn ($r) => Role::LABELS[$r] ?? $r)->join(', ', $glue);
    }

    private function summary(array $f): string
    {
        $pages = count($f['pages']);
        $roles = $this->labels($f['roles'], ' dan ');

        return rtrim($f['description'], '.').'.'
            .($pages > 1 ? " Terdiri dari {$pages} halaman." : '')
            .($roles !== '' ? " Menu ini dapat dibuka peran {$roles}." : ' Menu ini belum diberikan ke peran mana pun.');
    }

    /** @return list<array{text: string, route?: string}> */
    private function steps(array $f): array
    {
        $steps = [array_filter(['text' => "Buka menu {$f['label']} di menu samping.", 'route' => $f['route']])];
        $others = array_values(array_filter($f['pages'], fn ($p) => $p['name'] !== $f['route']));
        usort($others, fn ($a, $b) => [count($this->roleNames($a['roles'])) > 0, $a['title']] <=> [count($this->roleNames($b['roles'])) > 0, $b['title']]);
        foreach (array_slice($others, 0, self::MAX_STEP_PAGES) as $p) {
            $roles = $this->roleNames($p['roles']);
            $only = $roles ? ' (khusus '.$this->labels($roles).')' : '';
            $steps[] = ['text' => "Buka halaman {$p['title']}{$only}.", 'route' => $p['name']];
        }
        if (count($steps) === 1) {
            $steps[] = ['text' => 'Ikuti petunjuk di halaman tersebut; perubahan penting tercatat di Audit.'];
        }

        return $steps;
    }

    /** @return list<string> */
    private function tips(array $f): array
    {
        $tips = [];
        $roleOnly = array_filter($f['pages'], fn ($p) => $this->roleNames($p['roles']) !== []);
        if ($roleOnly) {
            $tips[] = 'Sebagian halaman hanya untuk peran tertentu: '.collect($roleOnly)->map(fn ($p) => $p['title'].' ('.$this->labels($this->roleNames($p['roles'])).')')->join(', ').'.';
        }
        if (array_filter($f['pages'], fn ($p) => $p['ability'] ?? null)) {
            $tips[] = 'Beberapa aksi di menu ini butuh izin tambahan; tombolnya tidak tampil bila peran Anda tidak memilikinya.';
        }
        $tips[] = 'Perubahan data di menu ini tercatat di Audit & Riwayat.';

        return array_slice($tips, 0, 5);
    }

    private function keywords(string $key, array $f): string
    {
        $base = GuideCatalog::KEYWORDS[$key] ?? mb_strtolower($f['label'].' '.$f['description']);
        $titles = collect($f['pages'])->pluck('title')->map(fn ($t) => mb_strtolower($t))
            ->reject(fn ($t) => str_contains(mb_strtolower($base), $t))->unique()->take(20)->join(' ');

        return trim($base.' '.$titles);
    }
}
