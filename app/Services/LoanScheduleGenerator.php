<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Generator jadwal angsuran pinjaman (anuitas rata/flat):
 * - total = pokok + bunga dibagi rata per bulan, dibulatkan ke bawah ke rupiah; sisa pembulatan masuk angsuran terakhir,
 *   sehingga jumlah seluruh angsuran selalu tepat sama dengan total;
 * - jatuh tempo ke-n = tanggal cair + n bulan, dihitung dari tanggal cair (bukan berantai) dan tidak melompati akhir
 *   bulan — cair 31 Jan → 28/29 Feb, 31 Mar, 30 Apr, …;
 * - porsi pokok & bunga per angsuran dibagi proporsional (porsi bunga dibulatkan ke bawah, sisanya pokok; selisih
 *   pembulatan bunga masuk angsuran terakhir).
 */
class LoanScheduleGenerator
{
    /**
     * @return list<array{sequence: int, due_date: string, amount: int, principal_part: int, interest_part: int}>
     */
    public static function generate(int $principal, int $interest, int $tenor, Carbon|string $disbursedOn): array
    {
        if ($tenor < 1 || $tenor > LoanApplicationService::MAX_TENOR) {
            throw new InvalidArgumentException('Tenor harus 1–'.LoanApplicationService::MAX_TENOR.' bulan.');
        }
        if ($principal < 1 || $interest < 0) {
            throw new InvalidArgumentException('Pokok harus positif dan bunga tidak boleh negatif.');
        }

        $start = Carbon::parse($disbursedOn)->startOfDay();
        $total = $principal + $interest;
        $per = intdiv($total, $tenor);
        $interestPer = intdiv($interest, $tenor);
        $rows = [];

        for ($n = 1; $n <= $tenor; $n++) {
            $last = $n === $tenor;
            $amount = $last ? $total - $per * ($tenor - 1) : $per;
            $interestPart = $last ? $interest - $interestPer * ($tenor - 1) : $interestPer;
            $rows[] = [
                'sequence' => $n,
                'due_date' => $start->copy()->addMonthsNoOverflow($n)->toDateString(),
                'amount' => $amount,
                'principal_part' => $amount - $interestPart,
                'interest_part' => $interestPart,
            ];
        }

        return $rows;
    }
}
