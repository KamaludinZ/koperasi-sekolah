<?php

namespace App\Livewire\Transaksi;

use App\Models\Transaction;
use App\Services\TransactionHistory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Riwayat transaksi: semua penjualan dari terbaru ke lama, dikelompokkan per hari,
 * dengan filter tanggal (pilihan cepat atau rentang kustom), ringkasan periode
 * dan paginasi. Data dari TransactionHistory.
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Transaksi')]
class Index extends Component
{
    use WithPagination;

    public const PRESETS = TransactionHistory::PRESETS;

    public const PER_PAGE = 50;

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
        $this->resetPage();
    }

    /** Input tanggal diubah → mode kustom. */
    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = 'kustom';
            $this->resetPage();
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
        return $this->preset === 'kustom'
            ? TransactionHistory::range('semua', $this->validDate($this->from), $this->validDate($this->to))
            : TransactionHistory::range($this->preset);
    }

    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        [$start, $end] = $this->range;

        return app(TransactionHistory::class)->paginate(['from' => $start, 'to' => $end], self::PER_PAGE, $this->getPage());
    }

    /**
     * Transaksi halaman ini dikelompokkan per tanggal (Y-m-d), terbaru dulu.
     * Jumlah & total harian dihitung dari seluruh transaksi hari itu (bukan hanya halaman ini).
     */
    #[Computed]
    public function days(): array
    {
        $rows = collect($this->transactions->items())->groupBy(fn ($t) => substr($t['created_at'], 0, 10));
        if ($rows->isEmpty()) {
            return [];
        }

        $dates = $rows->keys();
        $totals = Transaction::query()
            ->betweenDates(Carbon::parse($dates->last())->startOfDay(), Carbon::parse($dates->first())->endOfDay())
            ->get(['created_at', 'total'])
            ->groupBy(fn ($t) => $t->created_at->toDateString());

        return $rows->map(fn ($dayRows, $date) => [
            'date' => Carbon::parse($date),
            'rows' => $dayRows->all(),
            'count' => $totals[$date]?->count() ?? $dayRows->count(),
            'total' => (int) round((float) ($totals[$date]?->sum('total') ?? $dayRows->sum('total'))),
        ])->values()->all();
    }

    #[Computed]
    public function summary(): array
    {
        [$start, $end] = $this->range;

        return app(TransactionHistory::class)->summary($start, $end);
    }

    /** Label rentang aktif untuk ditampilkan, mis. "1–12 Oktober 2026". */
    #[Computed]
    public function rangeLabel(): ?string
    {
        return TransactionHistory::rangeLabel(...$this->range);
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    public function render()
    {
        return view('livewire.transaksi.index');
    }
}
