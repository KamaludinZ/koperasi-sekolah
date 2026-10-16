<?php

namespace App\Services;

use App\Support\Menu;
use Illuminate\Support\Str;

/**
 * Gabungan panduan untuk petugas multi-peran tanpa duplikasi:
 * - merge(): satu panduan per menu walau menu itu dimiliki beberapa peran pembaca — daftar gabungan (urut seperti
 *   menu samping, termasuk panduan umum), rincian panduan menu per peran, dan menu yang dipakai bersama (dimiliki
 *   ≥ 2 peran pembaca) beserta jumlah salinan yang dihindari;
 * - dedupe(): isi satu panduan dirapikan — langkah & tips kembar (beda huruf besar/spasi saja) dibuang, dan halaman
 *   terkait yang sudah ditautkan di langkah tidak diulang.
 */
class GuideMerger
{
    public function __construct(private GuideService $guides) {}

    /**
     * @param  array  $viewer  bentuk Petugas::current() / GuideService::viewer()
     * @param  array<string, array>  $guides  kunci → panduan (GuideService::all)
     * @return array{keys: list<string>, by_role: array<string, list<string>>, shared: list<string>, sources: array<string, list<string>>, duplicates: int}
     */
    public function merge(array $viewer, array $guides): array
    {
        $roles = $this->guides->rolesOf($viewer);
        $sources = Menu::sourcesFor($viewer);
        $allowed = fn (string $k) => $this->guides->allowed($viewer, $guides[$k]);
        $keys = array_values(array_filter($this->guides->orderedKeys($guides, $allowed), $allowed));
        $byRole = array_fill_keys($roles, []);
        $shared = [];
        $duplicates = 0;
        foreach ($keys as $k) {
            $menu = $guides[$k]['menu'];
            if ($menu === null) {
                continue; // panduan umum milik semua peran — tidak dihitung per peran
            }
            $from = array_values(array_intersect($sources[$menu] ?? [], $roles));
            foreach ($from as $r) {
                $byRole[$r][] = $k;
            }
            if (count($from) > 1) {
                $shared[] = $k;
                $duplicates += count($from) - 1; // salinan yang akan muncul bila panduan per peran ditumpuk begitu saja
            }
        }

        return ['keys' => $keys, 'by_role' => $byRole, 'shared' => $shared, 'sources' => $sources, 'duplicates' => $duplicates];
    }

    /** Rapikan isi satu panduan dari kembaran (langkah, tips, halaman terkait yang sudah ada di langkah). */
    public function dedupe(array $guide): array
    {
        $norm = fn (string $t) => Str::lower(preg_replace('/\s+/u', ' ', trim($t)));
        $seen = [];
        $steps = [];
        foreach ($guide['steps'] ?? [] as $s) {
            $id = $norm($s['text'] ?? '').'|'.($s['route'] ?? '');
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $steps[] = $s;
            }
        }
        $tips = [];
        foreach ($guide['tips'] ?? [] as $t) {
            $tips[$norm((string) $t)] ??= $t;
        }
        $linked = array_flip(array_filter(array_column($steps, 'route')));

        return ['steps' => $steps, 'tips' => array_values($tips), 'related' => array_diff_key($guide['related'] ?? [], $linked)] + $guide;
    }
}
