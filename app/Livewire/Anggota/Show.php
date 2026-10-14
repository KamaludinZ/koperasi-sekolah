<?php

namespace App\Livewire\Anggota;

use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\MemberService;
use App\Services\SavingsService;
use App\Services\MemberCreditService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Profil anggota: identitas, saldo tabungan, ringkasan & riwayat setor/tarik,
 * dialog setor/tarik, serta bon (piutang) anggota dengan form pelunasan. Data dari MemberService,
 * SavingsService, dan MemberCreditService (pelunasan tunai/potong tabungan, jatuh tempo terlama dulu).
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    public array $profile;

    public function mount(Member $member): void
    {
        $this->profile = MemberService::toArray($member);
        $this->bills = app(MemberCreditService::class)->openBills($member);
    }

    public const REPAY_METHODS = ['tunai' => 'Tunai', 'tabungan' => 'Potong saldo tabungan'];

    /** Bon belum lunas (jatuh tempo terlama dulu). */
    public array $bills = [];

    public bool $repaying = false;

    public ?int $repayAmount = null;

    public string $repayMethod = 'tunai';

    public string $repayNote = '';

    public ?array $lastRepayment = null;

    #[Computed]
    public function savingTypes(): ?array
    {
        // Rincian simpanan per jenis hanya untuk pengurus/admin (kasir tidak punya menu Simpanan).
        return auth()->user()?->can('menu', 'simpanan') ? app(\App\Services\MemberSavingService::class)->summary($this->member()) : null;
    }

    #[Computed]
    public function credit(): array
    {
        return app(MemberCreditService::class)->summary($this->member());
    }

    /**
     * Bagi pembayaran ke bon mulai jatuh tempo terlama.
     *
     * @return array<string, int> id bon → jumlah yang dilunasi
     */
    public static function allocate(array $bills, int $amount): array
    {
        $out = [];
        usort($bills, fn ($a, $b) => [$a['due_at'], $a['id']] <=> [$b['due_at'], $b['id']]);
        foreach ($bills as $b) {
            if ($amount <= 0) {
                break;
            }
            $pay = min($amount, $b['remaining']);
            $out[$b['id']] = $pay;
            $amount -= $pay;
        }

        return $out;
    }

    public function openRepay(): void
    {
        if ($this->credit['outstanding'] === 0) {
            return;
        }
        $this->resetValidation();
        $this->reset('repayNote', 'lastRepayment');
        $this->repayMethod = 'tunai';
        $this->repayAmount = $this->credit['outstanding'];
        $this->repaying = true;
    }

    public function closeRepay(): void
    {
        $this->repaying = false;
    }

    public function saveRepay(): void
    {
        $outstanding = $this->credit['outstanding'];
        abort_unless($this->repaying && $outstanding > 0, 403);

        $max = $this->repayMethod === 'tabungan' ? min($outstanding, $this->profile['balance']) : $outstanding;
        $this->validate([
            'repayMethod' => ['required', 'in:'.implode(',', array_keys(self::REPAY_METHODS))],
            'repayAmount' => ['required', 'integer', 'min:'.min(500, $outstanding), 'max:'.$max],
            'repayNote' => ['nullable', 'string', 'max:150'],
        ], [
            'repayAmount.required' => 'Masukkan jumlah pelunasan.',
            'repayAmount.min' => 'Pelunasan minimal Rp500.',
            'repayAmount.max' => $this->repayMethod === 'tabungan' && $this->profile['balance'] < $outstanding
                ? 'Saldo tabungan hanya Rp'.number_format($this->profile['balance'], 0, ',', '.').'.'
                : 'Melebihi sisa bon Rp'.number_format($outstanding, 0, ',', '.').'.',
            'repayNote.max' => 'Catatan maksimal 150 karakter.',
        ]);

        try {
            $payment = app(MemberCreditService::class)->repay($this->profile['id'], (int) $this->repayAmount, $this->repayMethod, trim($this->repayNote) ?: null, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'method' ? 'repayMethod' : 'repayAmount', $messages[0]);
            }

            return;
        }

        $member = $this->member();
        $this->bills = app(MemberCreditService::class)->openBills($member);
        $this->profile = MemberService::toArray($member);
        $this->lastRepayment = [
            'number' => $payment->number,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'settled' => $payment->allocations->filter(fn ($a) => $a->credit->status === 'lunas')->count(),
            'remaining' => array_sum(array_column($this->bills, 'remaining')),
        ];
        unset($this->stats, $this->history);
        $this->repaying = false;
        unset($this->credit);
    }

    public const HISTORY_PRESETS = SavingsService::HISTORY_PRESETS;

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

        $this->validate([
            'amount' => ['required', 'integer', 'min:'.SavingsService::MIN_AMOUNT, 'max:'.SavingsService::MAX_DEPOSIT],
            'note' => ['nullable', 'string', 'max:150'],
        ], [
            'amount.required' => 'Masukkan jumlah uang.',
            'amount.min' => SavingsService::messages()['amount.min'],
            'amount.max' => SavingsService::messages()['amount.max'],
        ]);

        // Saldo dicek ulang di dalam transaksi (baris anggota dikunci); bila kurang, error `amount`.
        $member = $this->member();
        $entry = app(SavingsService::class)->record($member, $this->mode, $this->amount, trim($this->note) ?: null, auth()->id());

        $this->lastEntry = SavingsService::toArray($entry);
        $this->profile = MemberService::toArray($member);
        $this->mode = null;
        unset($this->stats, $this->history);
    }

    private function member(): Member
    {
        return Member::findOrFail($this->profile['id']);
    }

    #[Computed]
    public function stats(): array
    {
        return app(SavingsService::class)->stats($this->member());
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
        [$start, $end] = $this->historyPreset === 'kustom'
            ? [
                $this->historyFrom !== '' && ! $this->getErrorBag()->has('historyFrom') ? Carbon::parse($this->historyFrom)->startOfDay() : null,
                $this->historyTo !== '' && ! $this->getErrorBag()->has('historyTo') ? Carbon::parse($this->historyTo)->endOfDay() : null,
            ]
            : SavingsService::historyRange($this->historyPreset);

        // Riwayat satu anggota kecil (puluhan mutasi per tahun) → tampilkan semua tanpa paginasi.
        $rows = $this->member()->savings()
            ->betweenDates($start, $end)
            ->ofType($this->historyType ?: null)
            ->with('user:id,name')
            ->latestFirst()
            ->get()
            ->map(fn (SavingsTransaction $s) => SavingsService::toArray($s));

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
