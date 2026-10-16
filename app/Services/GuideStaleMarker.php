<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideMenuSnapshot;
use App\Support\Menu;

/**
 * Penanda panduan usang otomatis dari perubahan menu/halaman/izin (GuideMetadataService::diff). Hanya panduan yang
 * TIDAK bisa diperbaiki penyusunan ulang otomatis yang ditandai:
 * - panduan manual yang menunya berubah (label/rute, halaman, atau peran pemegang);
 * - panduan otomatis yang bagian langkah/ringkasannya disunting admin sementara menu/halamannya berubah
 *   (suntingan bisa tidak sesuai lagi; perubahan peran saja tidak memengaruhi isi suntingan);
 * - panduan yang menunya sudah tidak ada di sistem.
 * Panduan otomatis tanpa suntingan cukup disusun ulang (penanda usangnya dilepas penyusun). Penandaan yang sama tidak
 * diulang; setiap penandaan baru tercatat di audit (GuideService::markStale).
 */
class GuideStaleMarker
{
    public const ASPECT_TEXT = ['menu' => 'label/rute menu', 'pages' => 'halaman', 'roles' => 'peran pemegang'];

    public function __construct(private GuideService $guides) {}

    /**
     * @param  array{added: list<string>, removed: list<string>, changed: array<string, list<string>>, first_scan?: bool}  $diff
     * @return list<string> kunci panduan yang baru ditandai
     */
    public function mark(array $diff): array
    {
        if ($diff['first_scan'] ?? false) {
            return [];
        }
        $marked = [];
        $entries = GuideEntry::with('overrides')->whereIn('menu_key', [...array_keys($diff['changed']), ...$diff['removed']])->get();
        foreach ($entries as $entry) {
            $label = Menu::ITEMS[$entry->menu_key]['label'] ?? GuideMenuSnapshot::where('menu_key', $entry->menu_key)->value('label') ?? $entry->menu_key;
            $reason = in_array($entry->menu_key, $diff['removed'], true)
                ? "Menu {$label} sudah tidak ada di sistem."
                : $this->reasonFor($entry, $label, $diff['changed'][$entry->menu_key] ?? []);
            if ($reason === null || ($entry->is_stale && $entry->stale_reason === $reason)) {
                continue;
            }
            $this->guides->markStale($entry, $reason);
            $marked[] = $entry->key;
        }

        return $marked;
    }

    private function reasonFor(GuideEntry $entry, string $label, array $aspects): ?string
    {
        $what = collect($aspects)->map(fn ($a) => self::ASPECT_TEXT[$a] ?? $a)->join(', ', ' dan ');
        if ($entry->source === 'manual') {
            return "Panduan manual: {$what} menu {$label} berubah sejak panduan ditulis.";
        }
        $edited = $entry->overrides->pluck('field')->intersect(['steps', 'summary'])->values()->all();
        $contentChanged = array_intersect($aspects, ['menu', 'pages']) !== [];
        if ($edited && $contentChanged) {
            return 'Suntingan admin pada '.implode(' & ', array_map(fn ($f) => $f === 'steps' ? 'langkah' : 'ringkasan', $edited))
                ." mungkin tidak sesuai lagi: {$what} menu {$label} berubah.";
        }

        return null;
    }
}
