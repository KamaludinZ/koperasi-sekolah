<?php

namespace App\Livewire\Kas;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rekap kas harian per periode: tiap hari modal awal, penjualan tunai/non-tunai, kas masuk
 * & keluar, kas seharusnya vs hitungan, dan selisih; plus total periode dan hari bermasalah.
 * Tahap frontend: angka dari MockData::cashRecap (data tiruan).
 */
#[Layout('components.layouts.app')]
#[Title('Rekap Kas Harian')]
class Recap extends Component
{
    public const PERIODS = [
        '7-hari' => '7 hari',
        '30-hari' => '30 hari',
        'bulan-ini' => 'Bulan ini',
        'bulan-lalu' => 'Bulan lalu',
    ];

    #[Url(as: 'periode', except: '7-hari')]
    public string $period = '7-hari';

    /** Tampilkan hanya hari yang ada selisih kas. */
    #[Url(as: 'selisih', except: false)]
    public bool $onlyDifferences = false;

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '7-hari';
        }
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::PERIODS)) {
            $this->period = $period;
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function range(): array
    {
        return self::rangeFor($this->period);
    }

    /**
     * Rentang tanggal (awal, akhir) sebuah periode; dipakai juga oleh Riwayat Shift.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rangeFor(string $period): array
    {
        return match ($period) {
            '30-hari' => [today()->subDays(29), today()],
            'bulan-ini' => [today()->startOfMonth(), today()],
            'bulan-lalu' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [today()->subDays(6), today()],
        };
    }

    /** Hari dalam periode, terbaru dulu. */
    #[Computed]
    public function rows(): array
    {
        [$from, $to] = $this->range;

        return array_reverse(MockData::cashRecap($from->toDateString(), $to->toDateString()));
    }

    #[Computed]
    public function totals(): array
    {
        $open = collect($this->rows)->where('closed_day', false);
        $sum = fn (string $key) => $open->sum($key);

        return [
            'days' => $open->where('shifts', '>', 0)->count(),
            'transactions' => $sum('transactions'),
            'revenue' => $sum('revenue'),
            'cash_sales' => $sum('cash_sales'),
            'non_cash' => $sum('non_cash'),
            'cash_in' => $sum('cash_in'),
            'cash_out' => $sum('cash_out'),
            'difference' => $sum('difference'),
            'short' => $open->where('difference', '<', 0)->sum('difference'),
            'over' => $open->where('difference', '>', 0)->sum('difference'),
            'days_short' => $open->where('difference', '<', 0)->count(),
            'days_over' => $open->where('difference', '>', 0)->count(),
        ];
    }

    public function render()
    {
        return view('livewire.kas.recap');
    }
}
