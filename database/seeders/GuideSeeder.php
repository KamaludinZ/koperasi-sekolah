<?php

namespace Database\Seeders;

use App\Models\GuideEntry;
use App\Support\GuideCatalog;
use Illuminate\Database\Seeder;

/**
 * Isi awal Pusat Panduan dari katalog bawaan (GuideCatalog). Aman dijalankan berulang: panduan baru ditambahkan,
 * panduan bersumber otomatis diperbarui, panduan yang sudah disunting manual tidak disentuh.
 */
class GuideSeeder extends Seeder
{
    public function run(): void
    {
        $pos = 0;
        foreach (GuideCatalog::entries() as $key => $e) {
            $pos++;
            $existing = GuideEntry::where('key', $key)->first();
            if ($existing && $existing->source === 'manual') {
                continue;
            }
            GuideEntry::updateOrCreate(['key' => $key], [
                'menu_key' => $e['menu'], 'audience' => $e['audience'], 'title' => $e['title'], 'summary' => $e['summary'],
                'steps' => $e['steps'], 'tips' => $e['tips'], 'related' => $e['related'], 'keywords' => GuideCatalog::KEYWORDS[$key] ?? null,
                'required_permissions' => $e['menu'] ? [$e['menu']] : [], 'source' => 'auto', 'is_stale' => false, 'stale_reason' => null,
                'generated_at' => now(), 'position' => $pos,
            ]);
        }
    }
}
