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
 * Riwayat & selisih shift: semua shift kasir per periode, disaring per petugas dan status
 * selisih (pas/kurang/lebih/berjalan), dengan ringkasan selisih tiap petugas.
 * Tahap frontend: data dari MockData::shiftHistory (data tiruan).
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Shift')]
class Shifts extends Component
{
    public const PERIODS = Recap::PERIODS;

    public const STATUSES = [
        '' => 'Semua',
        'kurang' => 'Kurang',
        'lebih' => 'Lebih',
        'pas' => 'Pas',
        'berjalan' => 'Berjalan',
    ];

    #[Url(as: 'periode', except: '7-hari')]
    public string $period = '7-hari';

    #[Url(as: 'petugas', except: '')]
    public string $cashier = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '7-hari';
        }
        if (! array_key_exists($this->status, self::STATUSES)) {
            $this->status = '';
        }
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::PERIODS)) {
            $this->period = $period;
        }
    }

    public function setStatus(string $status): void
    {
        if (array_key_exists($status, self::STATUSES)) {
            $this->status = $status;
        }
    }

    public function resetFilters(): void
    {
        $this->reset('cashier', 'status');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function range(): array
    {
        return Recap::rangeFor($this->period);
    }

    /** Semua shift periode ini (sebelum disaring). */
    #[Computed]
    public function all(): array
    {
        [$from, $to] = $this->range;

        return MockData::shiftHistory($from->toDateString(), $to->toDateString());
    }

    /** Shift setelah disaring petugas & status selisih. */
    #[Computed]
    public function shifts(): array
    {
        return array_values(array_filter($this->all, fn (array $s) => ($this->cashier === '' || $s['cashier'] === $this->cashier)
            && ($this->status === '' || self::statusOf($s) === $this->status)));
    }

    /** Ringkasan per petugas pada periode ini (tanpa saringan), selisih terbesar dulu. */
    #[Computed]
    public function perCashier(): array
    {
        return collect($this->all)->groupBy('cashier')->map(function ($rows, $name) {
            $closed = $rows->where('status', 'closed');

            return [
                'name' => $name,
                'shifts' => $rows->count(),
                'transactions' => $rows->sum('transactions'),
                'difference' => $closed->sum('difference'),
                'short' => $closed->where('difference', '<', 0)->count(),
                'over' => $closed->where('difference', '>', 0)->count(),
                'exact' => $closed->where('difference', 0)->count(),
            ];
        })->sortBy('difference')->values()->all();
    }

    /** Daftar petugas yang muncul di periode ini, untuk saringan. */
    #[Computed]
    public function cashiers(): array
    {
        return collect($this->all)->pluck('cashier')->unique()->sort()->values()->all();
    }

    public static function statusOf(array $shift): string
    {
        return match (true) {
            $shift['status'] === 'open' => 'berjalan',
            $shift['difference'] < 0 => 'kurang',
            $shift['difference'] > 0 => 'lebih',
            default => 'pas',
        };
    }

    public function render()
    {
        return view('livewire.kas.shifts');
    }
}
