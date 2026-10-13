<?php

namespace App\Services;

use App\Jobs\ExportCashRecap;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Permintaan ekspor laporan ke antrean latar. Membatasi rentang (maks. 366 hari) dan
 * jumlah ekspor yang belum selesai per petugas (maks. 3), lalu mengirim job-nya.
 */
class ReportExportService
{
    public const MAX_DAYS = 366;

    public const MAX_PENDING = 3;

    /** @throws ValidationException bila rentang terlalu panjang atau masih banyak ekspor berjalan */
    public function requestCashRecap(User $user, Carbon $from, Carbon $to): ReportExport
    {
        $to = min($to->copy()->startOfDay(), today());
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['dari' => 'Rentang maksimal 366 hari.']);
        }
        if (ReportExport::where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->count() >= self::MAX_PENDING) {
            throw ValidationException::withMessages(['dari' => 'Masih ada ekspor yang sedang dibuat. Tunggu sebentar.'])->status(429);
        }

        $export = ReportExport::create([
            'user_id' => $user->id,
            'type' => 'kas_rekap',
            'format' => 'csv',
            'params' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
        ExportCashRecap::dispatch($export->id);

        return $export->fresh();
    }
}
