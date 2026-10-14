<?php

namespace App\Livewire\Simpanan;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\SavingDue;
use App\Models\SavingType;
use App\Services\SavingDueService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Simpanan → Setoran bulanan (potong gaji): pengurus mencatat nominal setoran simpanan bulanan tiap
 * anggota guru/karyawan per periode, lalu memposting → saldo jenis simpanan bertambah (SavingDueService).
 * Siswa tidak termasuk; sistem tidak menghitung gaji.
 */
#[Layout('components.layouts.app')]
#[Title('Setoran Bulanan')]
class MonthlyDues extends Component
{
    use NotifiesSave;

    #[Url(as: 'bulan')]
    public string $period = '';

    #[Url(as: 'jenis')]
    public ?int $typeId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var array<int|string, int|string|null> member_id → nominal yang diisi */
    public array $amounts = [];

    /** @var list<int> */
    public array $selected = [];

    public function mount(): void
    {
        $this->period = SavingDueService::validPeriod($this->period) && $this->period <= now()->format('Y-m') ? $this->period : now()->format('Y-m');
        $types = SavingDueService::types();
        $this->typeId = $types->firstWhere('id', $this->typeId)?->id ?? $types->firstWhere('kind', 'wajib')?->id ?? $types->first()?->id;
        $this->loadAmounts();
    }

    #[Computed]
    public function types()
    {
        return SavingDueService::types();
    }

    #[Computed]
    public function type(): ?SavingType
    {
        return $this->typeId ? SavingType::find($this->typeId) : null;
    }

    #[Computed]
    public function rows(): array
    {
        return $this->type ? app(SavingDueService::class)->sheet($this->period, $this->type, $this->search) : [];
    }

    #[Computed]
    public function summary(): array
    {
        $rows = collect($this->rows);

        return [
            'members' => $rows->count(),
            'posted' => $rows->where('status', 'disetor')->count(),
            'pending' => $rows->where('status', 'belum')->count(),
            'posted_amount' => (int) $rows->where('status', 'disetor')->sum('amount'),
            'pending_amount' => (int) $rows->where('status', 'belum')->sum('amount'),
        ];
    }

    #[Computed]
    public function recap(): array
    {
        return $this->type ? app(SavingDueService::class)->recap($this->type) : [];
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->can('kelola-simpanan');
    }

    /** Pilihan bulan: 12 bulan terakhir s.d. bulan ini. */
    public function months(): array
    {
        return collect(range(0, 11))->mapWithKeys(fn ($i) => [($d = now()->startOfMonth()->subMonths($i))->format('Y-m') => $d->translatedFormat('F Y')])->all();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'typeId', 'search'], true)) {
            if (! SavingDueService::validPeriod($this->period) || $this->period > now()->format('Y-m')) {
                $this->period = now()->format('Y-m');
            }
            $this->selected = [];
            unset($this->type, $this->rows, $this->summary, $this->recap);
            $this->loadAmounts();
        }
        if (str_starts_with($property, 'selected')) {
            $this->selected = array_values(array_unique(array_map('intval', $this->selected)));
        }
    }

    private function loadAmounts(): void
    {
        $this->amounts = collect($this->rows)->mapWithKeys(fn ($r) => [$r['member_id'] => $r['amount']])->all();
    }

    /** Pilih semua yang belum disetor. */
    public function selectOpen(): void
    {
        $this->selected = collect($this->rows)->where('status', '!=', 'disetor')->pluck('member_id')->all();
    }

    public function save(): void
    {
        $this->apply(false);
    }

    public function postSelected(): void
    {
        $this->apply(true);
    }

    private function apply(bool $post): void
    {
        abort_unless($this->canManage && $this->type, 403);
        $this->resetErrorBag();
        $open = collect($this->rows)->where('status', '!=', 'disetor')->pluck('member_id')->all();
        $ids = array_values(array_intersect($this->selected, $open));
        if (! $ids) {
            $this->toast('error', 'Pilih anggota yang belum disetor.');

            return;
        }
        $svc = app(SavingDueService::class);
        try {
            $svc->record($this->period, $this->type, collect($ids)->mapWithKeys(fn ($id) => [$id => (int) ($this->amounts[$id] ?? 0)])->all(), auth()->user());
            $result = $post ? $svc->post($this->period, $this->type, $ids, auth()->user()) : null;
        } catch (ValidationException $e) {
            $this->addError('amounts', collect($e->errors())->flatten()->first());

            return;
        }

        $this->selected = [];
        unset($this->rows, $this->summary, $this->recap);
        $this->loadAmounts();
        $month = Carbon::parse($this->period.'-01')->translatedFormat('F Y');
        if (! $post) {
            $this->toast('success', count($ids)." setoran {$month} dicatat (belum menambah saldo).");

            return;
        }
        $msg = "{$result['posted']} setoran {$month} diposting: ".\App\Support\Rupiah::format($result['amount']).' masuk ke saldo.';
        $this->toast($result['skipped'] ? 'error' : 'success', $msg.($result['skipped'] ? ' Dilewati: '.implode(', ', $result['skipped']).'.' : ''));
    }

    /** Koreksi setoran yang sudah diposting (nominal baru; 0 = batalkan posting). */
    public ?int $correcting = null;

    public ?int $correctAmount = null;

    public string $correctReason = '';

    public function openCorrection(int $dueId): void
    {
        abort_unless($this->canManage, 403);
        $due = SavingDue::find($dueId);
        if (! $due) {
            return;
        }
        $this->correcting = $due->id;
        $this->correctAmount = (int) round((float) $due->amount);
        $this->correctReason = '';
        $this->resetValidation();
    }

    public function saveCorrection(): void
    {
        abort_unless($this->canManage && $this->correcting, 403);
        $this->resetErrorBag();
        try {
            $due = app(SavingDueService::class)->correct(SavingDue::findOrFail($this->correcting), (int) $this->correctAmount, $this->correctReason, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $m) {
                $this->addError(['amount' => 'correctAmount', 'reason' => 'correctReason'][$key] ?? 'correctAmount', $m[0]);
            }

            return;
        }
        $this->correcting = null;
        unset($this->rows, $this->summary, $this->recap);
        $this->loadAmounts();
        $this->toast('success', $due->status === 'disetor' ? 'Setoran dikoreksi; saldo disesuaikan.' : 'Posting dibatalkan; saldo dikembalikan dan catatan menjadi belum disetor.');
    }

    public function discard(int $dueId): void
    {
        abort_unless($this->canManage, 403);
        if ($due = SavingDue::find($dueId)) {
            try {
                app(SavingDueService::class)->discard($due);
            } catch (ValidationException $e) {
                $this->toast('error', collect($e->errors())->flatten()->first());

                return;
            }
        }
        unset($this->rows, $this->summary, $this->recap);
        $this->loadAmounts();
    }

    public function render()
    {
        return view('livewire.simpanan.monthly-dues');
    }
}
