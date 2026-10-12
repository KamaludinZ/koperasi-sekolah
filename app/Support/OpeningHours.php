<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Jam buka koperasi per hari ISO (1 = Senin … 7 = Minggu): ['open' => 'HH:MM', 'close' => 'HH:MM'] atau null (tutup).
 * Menyusun teks ringkas untuk tampilan dan status buka/tutup saat ini.
 */
class OpeningHours
{
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public const DEFAULT = [
        1 => ['open' => '07:00', 'close' => '14:00'], 2 => ['open' => '07:00', 'close' => '14:00'],
        3 => ['open' => '07:00', 'close' => '14:00'], 4 => ['open' => '07:00', 'close' => '14:00'],
        5 => ['open' => '07:00', 'close' => '11:00'], 6 => ['open' => '07:00', 'close' => '12:00'],
        7 => null,
    ];

    /**
     * Hari berurutan dengan jam sama digabung: "Senin – Kamis" → "07.00 – 14.00". Hari tutup dilewati.
     *
     * @return list<array{days: string, time: string}>
     */
    public static function group(array $schedule): array
    {
        $rows = [];
        $start = null;
        $prev = null;
        foreach (self::DAYS as $d => $name) {
            $h = $schedule[$d] ?? null;
            $key = $h ? $h['open'].'-'.$h['close'] : null;
            if ($key !== null && $key === $prev) {
                continue;
            }
            if ($prev !== null) {
                $rows[] = self::row($start, $d - 1, $schedule[$start]);
            }
            $start = $d;
            $prev = $key;
        }
        if ($prev !== null) {
            $rows[] = self::row($start, 7, $schedule[$start]);
        }

        return $rows;
    }

    /** @return array{open: bool, label: string, detail: string} */
    public static function status(array $schedule, Carbon $now): array
    {
        $today = $schedule[$now->dayOfWeekIso] ?? null;
        $time = $now->format('H:i');

        if ($today && $time >= $today['open'] && $time < $today['close']) {
            return ['open' => true, 'label' => 'Sedang buka', 'detail' => 'Tutup pukul '.self::dot($today['close'])];
        }

        for ($i = 0; $i <= 7; $i++) {
            $day = $now->copy()->addDays($i);
            $h = $schedule[$day->dayOfWeekIso] ?? null;
            if ($h && ($i > 0 || $time < $h['open'])) {
                $when = $i === 0 ? 'hari ini' : ($i === 1 ? 'besok' : $day->translatedFormat('l'));

                return ['open' => false, 'label' => 'Sedang tutup', 'detail' => "Buka {$when} pukul ".self::dot($h['open'])];
            }
        }

        return ['open' => false, 'label' => 'Sedang tutup', 'detail' => ''];
    }

    /** Hari tutup (untuk keterangan "Minggu & hari libur: Tutup"). */
    public static function closedDays(array $schedule): array
    {
        return array_values(array_filter(self::DAYS, fn ($n, $d) => empty($schedule[$d]), ARRAY_FILTER_USE_BOTH));
    }

    private static function row(int $from, int $to, array $h): array
    {
        return [
            'days' => $from === $to ? self::DAYS[$from] : self::DAYS[$from].' – '.self::DAYS[$to],
            'time' => self::dot($h['open']).' – '.self::dot($h['close']),
        ];
    }

    private static function dot(string $time): string
    {
        return str_replace(':', '.', $time);
    }
}
