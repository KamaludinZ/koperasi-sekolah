<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Ringkasan status cadangan untuk panel status: strip N malam terakhir (berhasil/gagal/tidak ada),
 * rangkaian berhasil berturut-turut, umur cadangan terakhir yang berhasil, kegagalan terbaru beserta
 * saran tindakan, dan status keseluruhan (ok | peringatan | gangguan).
 */
class BackupStatus
{
    /** Cadangan berhasil terakhir lebih tua dari ini (jam) = peringatan; dua kali lipatnya = gangguan. */
    public const STALE_HOURS = 26;

    /** Saran tindakan per penyebab kegagalan (dicocokkan dari catatan). */
    public const ADVICE = [
        'penyimpanan' => 'Bersihkan berkas lama atau tambah ruang disk, lalu coba lagi.',
        'koneksi' => 'Periksa koneksi ke basis data, lalu coba lagi.',
        'izin' => 'Periksa izin tulis folder penyimpanan di server.',
    ];

    /**
     * @param  list<array{created_at: string, status: string, trigger: string, note: ?string}>  $backups  terbaru dulu
     */
    public static function summarize(array $backups, int $nights = 14): array
    {
        $byDay = collect($backups)->groupBy(fn ($b) => substr($b['created_at'], 0, 10));
        $strip = [];
        for ($d = $nights; $d >= 1; $d--) {
            $date = today()->subDays($d)->toDateString();
            $day = $byDay->get($date, collect());
            $strip[] = [
                'date' => $date,
                'status' => $day->isEmpty() ? 'kosong' : ($day->contains('status', 'berhasil') ? 'berhasil' : 'gagal'),
            ];
        }

        $streak = 0;
        foreach (array_reverse($strip) as $s) {
            if ($s['status'] !== 'berhasil') {
                break;
            }
            $streak++;
        }

        $lastOk = collect($backups)->where('status', 'berhasil')->sortByDesc('created_at')->first();
        $age = $lastOk ? (int) floor(Carbon::parse($lastOk['created_at'])->diffInHours(now(), true)) : null;
        $lastFail = collect($backups)->where('status', 'gagal')->sortByDesc('created_at')->first();
        $failedAfterOk = $lastFail && (! $lastOk || $lastFail['created_at'] > $lastOk['created_at']);

        $status = match (true) {
            $age === null || $age >= self::STALE_HOURS * 2 => 'gangguan',
            $age >= self::STALE_HOURS || $failedAfterOk => 'peringatan',
            default => 'ok',
        };

        return [
            'status' => $status,
            'strip' => $strip,
            'streak' => $streak,
            'failed' => collect($strip)->where('status', 'gagal')->count(),
            'last_ok' => $lastOk,
            'age_hours' => $age,
            'last_failure' => $lastFail ? [...$lastFail, 'advice' => self::advice($lastFail['note'] ?? ''), 'unresolved' => $failedAfterOk] : null,
        ];
    }

    public static function advice(?string $note): string
    {
        $note = mb_strtolower((string) $note);
        foreach (self::ADVICE as $needle => $advice) {
            if (str_contains($note, $needle)) {
                return $advice;
            }
        }

        return 'Lihat catatan kesalahan di log server, lalu coba lagi.';
    }
}
