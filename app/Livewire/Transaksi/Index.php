<?php

namespace App\Livewire\Transaksi;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Riwayat transaksi: semua penjualan dari terbaru ke lama, dikelompokkan per hari,
 * dengan filter tanggal (pilihan cepat atau rentang kustom).
 * Tahap frontend: memakai data tiruan (MockData::transactions).
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Transaksi')]
class Index extends Component
{
    public const PRESETS = [
        'semua' => 'Semua',
        'hari-ini' => 'Hari ini',
        'kemarin' => 'Kemarin',
        '7-hari' => '7 hari terakhir',
        'bulan-ini' => 'Bulan ini',
    ];

    #[Url(as: 'periode', except: 'semua')]
    public string $preset = 'semua';

    /** Rentang kustom (Y-m-d). Diisi otomatis saat memilih preset. */
    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    public function mount(): void
    {
        if ($this->from !== '' || $this->to !== '') {
            $this->preset = 'kustom';
        }
    }

    public function applyPreset(string $preset): void
    {
        if (! array_key_exists($preset, self::PRESETS)) {
            return;
        }

        $this->resetValidation();
        $this->preset = $preset;
        $this->from = '';
        $this->to = '';
    }

    /** Input tanggal diubah → mode kustom. */
    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = 'kustom';
            $this->validate([
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            ], [
                'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
                'date_format' => 'Format tanggal tidak valid.',
            ]);
        }
    }

    public function clearFilter(): void
    {
        $this->applyPreset('semua');
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} rentang aktif (awal hari, akhir hari) */
    #[Computed]
    public function range(): array
    {
        $today = now();

        return match ($this->preset) {
            'hari-ini' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'kemarin' => [$today->copy()->subDay()->startOfDay(), $today->copy()->subDay()->endOfDay()],
            '7-hari' => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            'bulan-ini' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
            'kustom' => [
                $this->parseDate($this->from)?->startOfDay(),
                $this->parseDate($this->to)?->endOfDay(),
            ],
            default => [null, null],
        };
    }

    #[Computed]
    public function transactions(): array
    {
        [$start, $end] = $this->range;

        return array_values(array_filter(MockData::transactions(), function ($t) use ($start, $end) {
            $at = Carbon::parse($t['created_at']);

            return (! $start || $at->gte($start)) && (! $end || $at->lte($end));
        }));
    }

    /** Transaksi dikelompokkan per tanggal (Y-m-d), terbaru dulu, beserta subtotal harian. */
    #[Computed]
    public function days(): array
    {
        return collect($this->transactions)
            ->groupBy(fn ($t) => substr($t['created_at'], 0, 10))
            ->map(fn ($rows, $date) => [
                'date' => Carbon::parse($date),
                'rows' => $rows->all(),
                'count' => $rows->count(),
                'total' => $rows->sum('total'),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->transactions);

        return [
            'count' => $all->count(),
            'total' => $all->sum('total'),
            'items' => $all->sum('item_count'),
            'average' => $all->count() ? (int) round($all->avg('total')) : 0,
        ];
    }

    /** Label rentang aktif untuk ditampilkan, mis. "1–12 Oktober 2026". */
    #[Computed]
    public function rangeLabel(): ?string
    {
        [$start, $end] = $this->range;
        if (! $start && ! $end) {
            return null;
        }
        if ($start && $end && $start->isSameDay($end)) {
            return $start->translatedFormat('d F Y');
        }

        return ($start ? $start->translatedFormat('d M Y') : '…').' – '.($end ? $end->translatedFormat('d M Y') : '…');
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        return view('livewire.transaksi.index');
    }
}
