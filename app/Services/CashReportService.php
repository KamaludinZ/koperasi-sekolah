<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap kas harian dari data shift, penjualan, dan transaksi kas laci:
 * - day(): shift-shift satu hari (modal, penjualan tunai/non-tunai, kas masuk/keluar,
 *   kas seharusnya, dihitung, selisih), setoran/penarikan, penjualan per metode, ringkasan,
 *   serta penjualan tunai yang terjadi tanpa shift terbuka.
 * - range(): ringkasan per hari untuk satu periode (maks. 92 hari).
 * Angka shift dihitung dengan query terkelompok (bukan per baris). Kas seharusnya shift
 * yang sudah ditutup memakai snapshot saat tutup; shift berjalan dihitung dari data terkini.
 */
class CashReportService
{
    public const MAX_RANGE_DAYS = 92;

    public function __construct(private SettingsService $settings, private CashMovementService $movements) {}

    public function day(Carbon|string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $shifts = CashShift::onDate($day)->with('user:id,name', 'closer:id,name')->orderBy('opened_at')->get();
        $rows = $this->shiftRows($shifts);

        $sales = Transaction::query()
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->get(['payment_method', 'total', 'cash_shift_id']);
        $byMethod = fn (string $m) => (int) round((float) $sales->where('payment_method', $m)->sum('total'));

        return [
            'date' => $day->toDateString(),
            'is_today' => $day->isToday(),
            'closed_day' => $shifts->isEmpty() && $this->isClosedDay($day),
            'shifts' => $rows,
            'movements' => $this->movements->forDate($day)['data'],
            'sales' => [
                'tunai' => $byMethod('tunai'),
                'qris' => $byMethod('qris'),
                'transfer' => 0, // QRIS & transfer dicatat bersama sebagai "qris"
                'count' => $sales->count(),
            ],
            'unassigned_cash_sales' => (int) round((float) $sales->whereNull('cash_shift_id')->where('payment_method', 'tunai')->sum('total')),
            'summary' => self::summarize($rows, $byMethod('tunai') + $byMethod('qris'), $byMethod('qris')),
        ];
    }

    /**
     * Ringkasan per hari (lama → baru) dari $from sampai $to (tidak melewati hari ini).
     *
     * @return list<array{date: string, closed_day: bool, shifts: int, open_shifts: int, transactions: int, opening_cash: int, cash_sales: int, non_cash: int, revenue: int, cash_in: int, cash_out: int, expected: int, counted: int, difference: int, cashiers: list<string>}>
     */
    public function range(Carbon|string $from, Carbon|string $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = min(Carbon::parse($to)->startOfDay(), today());
        if ($from->gt($to)) {
            return [];
        }

        $shiftsByDay = $this->shiftRows(
            CashShift::whereBetween('opened_at', [$from, $to->copy()->endOfDay()])->with('user:id,name')->orderBy('opened_at')->get()
        );
        $shiftsByDay = collect($shiftsByDay)->groupBy(fn ($s) => substr($s['opened_at'], 0, 10));
        $sales = Transaction::whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get(['created_at', 'payment_method', 'total'])
            ->groupBy(fn ($t) => $t->created_at->toDateString());

        $rows = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $shifts = $shiftsByDay->get($key, collect())->values()->all();
            $daySales = $sales->get($key, collect());
            $revenue = (int) round((float) $daySales->sum('total'));
            $nonCash = (int) round((float) $daySales->where('payment_method', '!=', 'tunai')->sum('total'));
            $s = self::summarize($shifts, $revenue, $nonCash);

            $rows[] = [
                'date' => $key,
                'closed_day' => $shifts === [] && $daySales->isEmpty() && $this->isClosedDay($d),
                'shifts' => count($shifts),
                'open_shifts' => $s['open_shifts'],
                'transactions' => $daySales->count(),
                ...collect($s)->only(['opening_cash', 'cash_sales', 'non_cash', 'revenue', 'cash_in', 'cash_out', 'expected', 'counted', 'difference'])->all(),
                'cashiers' => array_values(array_unique(array_filter(array_column($shifts, 'cashier')))),
            ];
        }

        return $rows;
    }

    /**
     * Baris shift lengkap (lihat ShiftService::toArray) untuk banyak shift sekaligus, dengan
     * penjualan & kas laci dijumlahkan lewat dua query terkelompok.
     *
     * @param  Collection<int, CashShift>  $shifts
     */
    private function shiftRows(Collection $shifts): array
    {
        if ($shifts->isEmpty()) {
            return [];
        }
        $ids = $shifts->pluck('id');
        $sales = Transaction::whereIn('cash_shift_id', $ids)
            ->groupBy('cash_shift_id', 'payment_method')
            ->selectRaw('cash_shift_id, payment_method, COUNT(*) AS n, COALESCE(SUM(total), 0) AS total')
            ->get()->groupBy('cash_shift_id');
        $moves = CashMovement::whereIn('cash_shift_id', $ids)
            ->groupBy('cash_shift_id', 'type')
            ->selectRaw('cash_shift_id, type, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')
            ->get()->groupBy('cash_shift_id');

        return $shifts->map(function (CashShift $shift) use ($sales, $moves) {
            $s = $sales->get($shift->id, collect());
            $m = $moves->get($shift->id, collect());
            $cash = (int) round((float) $s->where('payment_method', 'tunai')->sum('total'));
            $in = (int) round((float) $m->where('type', CashMovement::TYPE_IN)->sum('total'));
            $out = (int) round((float) $m->where('type', CashMovement::TYPE_OUT)->sum('total'));
            $open = $shift->isOpen();

            return [
                'id' => $shift->id,
                'name' => $shift->name,
                'status' => $shift->status,
                'cashier' => $shift->user?->name,
                'closed_by' => $shift->closer?->name,
                'opened_at' => $shift->opened_at->toDateTimeString(),
                'closed_at' => $shift->closed_at?->toDateTimeString(),
                'opening_cash' => (int) $shift->opening_cash,
                'transactions' => (int) $s->sum('n'),
                'cash_sales' => $cash,
                'qris_sales' => (int) round((float) $s->where('payment_method', '!=', 'tunai')->sum('total')),
                'transfer_sales' => 0,
                'cash_in' => $in,
                'cash_out' => $out,
                'movements' => (int) $m->sum('n'),
                'expected' => $open ? (int) $shift->opening_cash + $cash + $in - $out : (int) $shift->expected_cash,
                'counted' => $open ? null : (int) $shift->counted_cash,
                'difference' => $open ? null : (int) $shift->difference,
                'note' => $shift->close_note,
            ];
        })->all();
    }

    /** Ringkasan kas dari baris shift (format sama dengan rekap tahap frontend). */
    private static function summarize(array $shifts, int $revenue, int $nonCash): array
    {
        $sum = fn (string $key) => array_sum(array_column($shifts, $key));
        $closed = array_filter($shifts, fn ($s) => $s['status'] === CashShift::STATUS_CLOSED);

        return [
            'opening_cash' => $sum('opening_cash'),
            'cash_sales' => $sum('cash_sales'),
            'cash_in' => $sum('cash_in'),
            'cash_out' => $sum('cash_out'),
            'expected' => $sum('expected'),
            'counted' => array_sum(array_column($closed, 'counted')),
            'difference' => array_sum(array_column($closed, 'difference')),
            'non_cash' => $nonCash,
            'revenue' => $revenue,
            'open_shifts' => count($shifts) - count($closed),
            'closed_shifts' => count($closed),
        ];
    }

    /** Hari tutup menurut jadwal buka di pengaturan profil. */
    private function isClosedDay(Carbon $day): bool
    {
        return empty($this->settings->profile()['schedule'][$day->dayOfWeekIso] ?? null);
    }
}
