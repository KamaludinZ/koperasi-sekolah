<?php

namespace App\Livewire\Anggota;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Profil anggota: identitas, saldo tabungan, dan ringkasan setor/tarik.
 * Tahap frontend: memakai data tiruan (MockData::members & ::savings).
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    public array $profile;

    public function mount(int $member): void
    {
        $found = MockData::findMember($member);
        abort_if(! $found, 404);

        $this->profile = $found;
    }

    public const HISTORY_PRESETS = ['30-hari' => '30 hari', '90-hari' => '3 bulan', 'tahun-ini' => 'Tahun ini', 'semua' => 'Semua'];

    #[Url(as: 'periode', except: 'semua')]
    public string $historyPreset = 'semua';

    #[Url(as: 'dari', except: '')]
    public string $historyFrom = '';

    #[Url(as: 'sampai', except: '')]
    public string $historyTo = '';

    #[Url(as: 'jenis', except: '')]
    public string $historyType = '';

    /** Dialog setor/tarik: null (tertutup), 'setor', atau 'tarik'. */
    public ?string $mode = null;

    public ?int $amount = null;

    public string $note = '';

    public ?array $lastEntry = null;

    public function openSaving(string $mode): void
    {
        if (! in_array($mode, ['setor', 'tarik'], true) || ! $this->profile['is_active']) {
            return;
        }

        $this->resetValidation();
        $this->reset('amount', 'note', 'lastEntry');
        $this->mode = $mode;
    }

    public function closeSaving(): void
    {
        $this->mode = null;
    }

    public function saveSaving(): void
    {
        abort_unless($this->profile['is_active'] && in_array($this->mode, ['setor', 'tarik'], true), 403);

        $balance = $this->profile['balance'];
        $this->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:'.($this->mode === 'tarik' ? $balance : 10_000_000)],
            'note' => ['nullable', 'string', 'max:150'],
        ], [
            'amount.required' => 'Masukkan jumlah uang.',
            'amount.min' => 'Minimal Rp1.000.',
            'amount.max' => $this->mode === 'tarik'
                ? 'Penarikan melebihi saldo (Rp'.number_format($balance, 0, ',', '.').').'
                : 'Maksimal Rp10.000.000 per setoran.',
        ]);

        $this->lastEntry = MockData::recordSaving($this->profile['id'], $this->mode, $this->amount, trim($this->note) ?: null);
        $this->profile = MockData::findMember($this->profile['id']);
        $this->mode = null;
        unset($this->savings, $this->stats, $this->history);
    }

    #[Computed]
    public function savings(): array
    {
        return MockData::savings($this->profile['id']);
    }

    #[Computed]
    public function stats(): array
    {
        $all = collect($this->savings);
        $deposits = $all->where('type', 'setor');
        $withdrawals = $all->where('type', 'tarik');
        $last = $all->last();

        return [
            'deposit_total' => $deposits->sum('amount'),
            'deposit_count' => $deposits->count(),
            'withdraw_total' => $withdrawals->sum('amount'),
            'withdraw_count' => $withdrawals->count(),
            'last_at' => $last['created_at'] ?? null,
            'last_type' => $last['type'] ?? null,
        ];
    }

    public function applyHistoryPreset(string $preset): void
    {
        if (array_key_exists($preset, self::HISTORY_PRESETS)) {
            $this->resetValidation(['historyFrom', 'historyTo']);
            $this->historyPreset = $preset;
            $this->historyFrom = '';
            $this->historyTo = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['historyFrom', 'historyTo'], true)) {
            $this->historyPreset = 'kustom';
            $this->validate([
                'historyFrom' => ['nullable', 'date_format:Y-m-d'],
                'historyTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:historyFrom'],
            ], ['historyTo.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
    }

    /** Mutasi sesuai filter, terbaru dulu, beserta ringkasan periode. */
    #[Computed]
    public function history(): array
    {
        $now = now();
        [$start, $end] = match ($this->historyPreset) {
            '30-hari' => [$now->copy()->subDays(29)->startOfDay(), null],
            '90-hari' => [$now->copy()->subDays(89)->startOfDay(), null],
            'tahun-ini' => [$now->copy()->startOfYear(), null],
            'kustom' => [
                $this->historyFrom !== '' && ! $this->getErrorBag()->has('historyFrom') ? Carbon::parse($this->historyFrom)->startOfDay() : null,
                $this->historyTo !== '' && ! $this->getErrorBag()->has('historyTo') ? Carbon::parse($this->historyTo)->endOfDay() : null,
            ],
            default => [null, null],
        };

        $rows = collect($this->savings)
            ->filter(function ($row) use ($start, $end) {
                $at = Carbon::parse($row['created_at']);

                return (! $start || $at->gte($start)) && (! $end || $at->lte($end));
            })
            ->filter(fn ($row) => $this->historyType === '' || $row['type'] === $this->historyType)
            ->sortByDesc('created_at')
            ->values();

        return [
            'rows' => $rows->all(),
            'in' => $rows->where('type', 'setor')->sum('amount'),
            'out' => $rows->where('type', 'tarik')->sum('amount'),
            'filtered' => $this->historyPreset !== 'semua' || $this->historyType !== '',
        ];
    }

    public function render()
    {
        return view('livewire.anggota.show')->title($this->profile['name'].' · Anggota');
    }
}
