<?php

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Http\Request;

/**
 * Aturan sesi petugas: batas waktu menganggur (pengaturan Keamanan & retensi, dapat ditimpa
 * KOPERASI_IDLE_MINUTES; 0 = nonaktif) dan pengenalan permintaan "pasif" — polling Livewire
 * (`wire:poll`, mis. lonceng notifikasi) yang tidak boleh dihitung sebagai aktivitas petugas.
 */
class SessionPolicy
{
    public static function idleMinutes(): int
    {
        $override = config('koperasi.idle_minutes');

        return $override !== null ? (int) $override : (int) app(SettingsService::class)->security()['idle_minutes'];
    }

    /** Permintaan latar yang hanya menyegarkan tampilan (bukan tindakan petugas). */
    public static function isPassive(Request $request): bool
    {
        if (! $request->hasHeader('X-Livewire') || ! is_array($components = $request->input('components'))) {
            return false;
        }

        foreach ($components as $component) {
            if (! empty($component['updates'] ?? [])) {
                return false;
            }
            foreach ($component['calls'] ?? [] as $call) {
                if (($call['method'] ?? null) !== '$refresh') {
                    return false;
                }
            }
        }

        return $components !== [];
    }
}
