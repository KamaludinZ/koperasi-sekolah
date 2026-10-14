<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Format tanggal seragam berbahasa Indonesia: "5 Mei 2026" (singkat: "5 Mei 2026" dengan bulan 3 huruf).
 * Nilai kosong/tidak valid tampil sebagai "—" (tidak pernah jatuh ke tanggal hari ini).
 * Di Blade: @tanggal($d) / @tanggal($d, true) untuk menyertakan jam.
 */
class Tanggal
{
    public static function format(\DateTimeInterface|string|null $date, bool $withTime = false): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        try {
            $d = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        } catch (\Throwable) {
            return '—';
        }

        return $d->locale('id')->translatedFormat($withTime ? 'j M Y H.i' : 'j M Y');
    }
}
