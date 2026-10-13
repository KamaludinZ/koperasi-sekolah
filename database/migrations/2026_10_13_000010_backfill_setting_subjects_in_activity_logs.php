<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Jejak `setting.updated`/`setting.reset` lama belum punya subjek dan kunci nilainya belum
     * berawalan bagian. Isi subjek (setting, urutan bagian, label bagian) dan ubah kunci
     * old/new_values menjadi "bagian.kolom" agar tampil di Audit → Perubahan data.
     */
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement('ALTER TABLE activity_logs DISABLE TRIGGER activity_logs_no_update_delete');
        }

        try {
            DB::table('activity_logs')->whereIn('event', ['setting.updated', 'setting.reset'])->whereNull('subject_type')
                ->orderBy('id')->lazyById(200)->each(function ($row) {
                    $section = json_decode((string) $row->properties, true)['section'] ?? null;
                    if (! in_array($section, SettingsService::SECTIONS, true)) {
                        return;
                    }
                    $prefix = fn (?string $json) => $json === null ? null : json_encode(
                        collect(json_decode($json, true) ?: [])->mapWithKeys(fn ($v, $k) => [str_contains($k, '.') ? $k : "{$section}.{$k}" => $v])->all(),
                        JSON_UNESCAPED_UNICODE);

                    DB::table('activity_logs')->where('id', $row->id)->update([
                        'subject_type' => 'setting',
                        'subject_id' => array_search($section, SettingsService::SECTIONS, true) + 1,
                        'subject_label' => SettingsService::SECTION_LABELS[$section],
                        'old_values' => $prefix($row->old_values),
                        'new_values' => $prefix($row->new_values),
                    ]);
                });
        } finally {
            if ($pgsql) {
                DB::statement('ALTER TABLE activity_logs ENABLE TRIGGER activity_logs_no_update_delete');
            }
        }
    }

    public function down(): void
    {
        // Subjek hasil pengisian dibiarkan.
    }
};
