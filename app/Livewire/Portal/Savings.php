<?php

namespace App\Livewire\Portal;

use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingsTransaction;
use App\Services\MemberSavingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Simpanan: saldo tabungan + simpanan per jenis (status lunas/tunggakan) dan riwayat
 * mutasi keduanya. Data selalu milik anggota yang sedang masuk (guard `member`), tanpa parameter id.
 */
#[Layout('components.layouts.portal')]
#[Title('Simpanan & Tabungan')]
class Savings extends Component
{
    public const STEP = 20;

    #[Url(as: 'riwayat', except: 'simpanan')] // simpanan | tabungan | potong
    public string $tab = 'simpanan';

    public int $limit = self::STEP;

    public const PERIODS = ['30-hari' => '30 hari', '90-hari' => '3 bulan', 'tahun-ini' => 'Tahun ini', 'semua' => 'Semua'];

    #[Url(as: 'periode', except: 'semua')]
    public string $period = 'semua';

    /** Saring riwayat simpanan per jenis (id jenis; kosong = semua). */
    #[Url(as: 'jenis', except: '')]
    public string $typeFilter = '';

    public function updated(string $property): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = 'semua';
        }
        $this->limit = self::STEP;
    }

    private function from(): ?Carbon
    {
        return match ($this->period) {
            '30-hari' => now()->subDays(29)->startOfDay(),
            '90-hari' => now()->subDays(89)->startOfDay(),
            'tahun-ini' => now()->startOfYear(),
            default => null,
        };
    }

    private function historyQuery()
    {
        $id = $this->member()->id;

        return ($this->tab === 'tabungan'
            ? SavingsTransaction::where('member_id', $id)
            : MemberSavingEntry::where('member_id', $id)->when($this->typeFilter !== '', fn ($q) => $q->where('saving_type_id', (int) $this->typeFilter)))
            ->when($this->from(), fn ($q, $from) => $q->where('created_at', '>=', $from));
    }

    /**
     * Semua potong saldo untuk belanja (lihat BalanceDeductionService::history).
     */
    private function deductions(int $limit): \Illuminate\Support\Collection
    {
        return app(\App\Services\BalanceDeductionService::class)->history($this->member(), $this->from(), $limit);
    }

    /** Total masuk & keluar sesuai saringan riwayat. */
    #[Computed]
    public function totals(): array
    {
        if ($this->tab === 'potong') {
            $rows = $this->deductions(500);

            return ['in' => (int) $rows->where('refunded', true)->sum('amount'), 'out' => (int) $rows->sum('amount')];
        }
        $row = $this->historyQuery()->selectRaw("COALESCE(SUM(CASE WHEN type = 'setor' THEN amount ELSE 0 END), 0) AS masuk,
            COALESCE(SUM(CASE WHEN type <> 'setor' THEN amount ELSE 0 END), 0) AS keluar")->first();

        return ['in' => (int) round((float) $row->masuk), 'out' => (int) round((float) $row->keluar)];
    }

    private function member(): Member
    {
        return Auth::guard('member')->user();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['simpanan', 'tabungan', 'potong'], true) ? $tab : 'simpanan';
        $this->typeFilter = '';
        $this->limit = self::STEP;
    }

    public function more(): void
    {
        $this->limit = min($this->limit + self::STEP, 200);
    }

    #[Computed]
    public function summary(): array
    {
        $m = $this->member();
        $savings = app(MemberSavingService::class)->summary($m);

        return [
            'tabungan' => (int) round((float) $m->balance),
            'simpanan' => $savings['total'],
            'types' => $savings['types'],
        ];
    }

    /** @return array{rows: list<array>, more: bool} */
    #[Computed]
    public function history(): array
    {
        if ($this->tab === 'potong') {
            $rows = $this->deductions($this->limit + 1);

            return ['rows' => $rows->take($this->limit)->values()->all(), 'more' => $rows->count() > $this->limit];
        }
        $rows = $this->tab === 'tabungan'
            ? $this->historyQuery()->latest('created_at')->latest('id')->limit($this->limit + 1)->get()
                ->map(fn (SavingsTransaction $s) => ['id' => 't'.$s->id, 'at' => $s->created_at, 'in' => $s->type === 'setor',
                    'label' => $s->note ?: $s->type_label, 'amount' => (int) round((float) $s->amount), 'balance' => (int) round((float) $s->balance_after), 'type' => null])
            : $this->historyQuery()->with('savingType:id,name')->latest('created_at')->latest('id')->limit($this->limit + 1)->get()
                ->map(fn (MemberSavingEntry $e) => ['id' => 's'.$e->id, 'at' => $e->created_at, 'in' => $e->type === 'setor',
                    'label' => $e->note ?: (MemberSavingEntry::TYPES[$e->type] ?? $e->type), 'amount' => (int) round((float) $e->amount),
                    'balance' => (int) round((float) $e->balance_after), 'type' => $e->savingType?->name]);

        return ['rows' => $rows->take($this->limit)->values()->all(), 'more' => $rows->count() > $this->limit];
    }

    public function render()
    {
        return view('livewire.portal.savings');
    }
}
