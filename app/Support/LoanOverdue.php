<?php

namespace App\Support;

use App\Models\LoanInstallment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Aturan tunggal "menunggak" untuk angsuran pinjaman, dipakai konsol petugas, portal anggota, filter daftar
 * angsuran, dan hitung ulang status:
 * - angsuran belum lunas (status ≠ dibayar, termasuk yang baru dibayar sebagian) menunggak bila jatuh temponya sudah
 *   lewat lebih dari masa toleransi — jatuh tempo HARI INI belum menunggak;
 * - masa toleransi (hari) dari config koperasi.loan_grace_days (env KOPERASI_PINJAMAN_TOLERANSI_HARI, 0–30, bawaan 0);
 * - "terlambat N hari" dihitung dari tanggal jatuh tempo (bukan dari akhir toleransi).
 */
class LoanOverdue
{
    public const MAX_GRACE_DAYS = 30;

    public static function graceDays(): int
    {
        return max(0, min(self::MAX_GRACE_DAYS, (int) config('koperasi.loan_grace_days', 0)));
    }

    /** Tanggal batas (Y-m-d): angsuran dengan jatuh tempo SEBELUM tanggal ini dan belum lunas = menunggak. */
    public static function cutoff(?Carbon $today = null): string
    {
        return ($today ?? today())->copy()->startOfDay()->subDays(self::graceDays())->toDateString();
    }

    public static function isLate(LoanInstallment $i, ?Carbon $today = null): bool
    {
        return $i->status !== 'dibayar' && $i->due_date->toDateString() < self::cutoff($today);
    }

    public static function daysLate(LoanInstallment $i, ?Carbon $today = null): int
    {
        return self::isLate($i, $today) ? (int) $i->due_date->copy()->startOfDay()->diffInDays(($today ?? today())->copy()->startOfDay()) : 0;
    }

    /** Status angsuran saat ini: dibayar | menunggak | belum (tidak bergantung pada kolom status yang mungkin belum disegarkan). */
    public static function status(LoanInstallment $i, ?Carbon $today = null): string
    {
        return $i->status === 'dibayar' ? 'dibayar' : (self::isLate($i, $today) ? 'menunggak' : 'belum');
    }

    /** Saring query angsuran ke yang menunggak per hari ini. */
    public static function scope(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->where('status', '!=', 'dibayar')->where('due_date', '<', self::cutoff($today));
    }
}
