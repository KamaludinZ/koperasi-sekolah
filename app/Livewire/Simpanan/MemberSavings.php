<?php

namespace App\Livewire\Simpanan;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Member;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kartu simpanan per jenis di profil anggota + dialog setor/tarik (Admin & Pengurus).
 * Wajib bulanan disetor per bulan (tunggakan terlama dulu); pokok bisa dicicil; tarik hanya untuk jenis
 * yang boleh ditarik dan tidak melebihi saldo. Logika di MemberSavingService.
 */
class MemberSavings extends Component
{
    use NotifiesSave;

    #[Locked]
    public int $memberId;

    public bool $open = false;

    public string $mode = 'setor';

    public ?int $typeId = null;

    public ?int $amount = null;

    public int $months = 1;

    public string $note = '';

    /** Kunci idempoten per dialog: klik ganda tidak mencatat dua kali. */
    #[Locked]
    public string $ref = '';

    public function mount(int $memberId): void
    {
        $this->memberId = $memberId;
    }

    private function member(): Member
    {
        return Member::findOrFail($this->memberId);
    }

    #[Computed]
    public function summary(): array
    {
        return app(MemberSavingService::class)->summary($this->member());
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->can('kelola-simpanan');
    }

    #[Computed]
    public function selected(): ?array
    {
        return collect($this->summary['types'])->firstWhere('id', $this->typeId);
    }

    /** Bulan wajib yang akan dibayar bila disimpan sekarang (pratinjau). */
    #[Computed]
    public function periods(): array
    {
        $t = $this->selected;
        if (! $t || $t['period'] !== 'bulanan' || ! $t['amount']) {
            return [];
        }
        $periods = app(MemberSavingService::class)->nextPeriods($this->member(), SavingType::find($t['id']), $this->months);

        return array_map(fn ($p) => Carbon::parse($p.'-01')->translatedFormat('M Y'), $periods);
    }

    public function openForm(string $mode, ?int $typeId = null): void
    {
        abort_unless($this->canManage, 403);
        $this->mode = $mode === 'tarik' ? 'tarik' : 'setor';
        $types = collect($this->summary['types'])->filter(fn ($t) => $this->mode === 'tarik' ? $t['withdrawable'] && $t['balance'] > 0 : $t['is_active']);
        $this->typeId = $types->firstWhere('id', $typeId)['id'] ?? $types->first()['id'] ?? null;
        $this->months = 1;
        $this->note = '';
        $this->ref = (string) Str::uuid();
        $this->resetValidation();
        $this->prefill();
        $this->open = true;
    }

    public function updatedTypeId(): void
    {
        $this->months = 1;
        $this->prefill();
    }

    public function updatedMonths(): void
    {
        $this->months = max(1, min(MemberSavingService::MAX_MONTHS, (int) $this->months));
    }

    /** Isi nominal: pokok = sisa yang belum dibayar; jenis lain = nominal jenis (0/bebas → kosong). */
    private function prefill(): void
    {
        $t = $this->selected;
        $this->amount = null;
        if ($t && $this->mode === 'setor' && $t['amount'] > 0) {
            $this->amount = $t['period'] === 'sekali' ? max(0, $t['amount'] - $t['balance']) ?: null : $t['amount'];
        }
    }

    public function save(): void
    {
        abort_unless($this->canManage, 403);
        $this->resetErrorBag();
        $type = $this->typeId ? SavingType::find($this->typeId) : null;
        if (! $type) {
            $this->addError('typeId', 'Pilih jenis simpanan.');

            return;
        }
        $monthly = $this->mode === 'setor' && $type->period === 'bulanan' && (float) $type->amount > 0;
        if (! $monthly && (! $this->amount || $this->amount < 1)) {
            $this->addError('amount', 'Masukkan jumlah uang.');

            return;
        }
        if (mb_strlen($this->note) > 150) {
            $this->addError('note', 'Catatan maksimal 150 karakter.');

            return;
        }

        try {
            $svc = app(MemberSavingService::class);
            $entries = $this->mode === 'setor'
                ? $svc->deposit($this->member(), $type, (int) $this->amount, $this->note ?: null, auth()->id(), $this->months, $this->ref)
                : [$svc->withdraw($this->member(), $type, (int) $this->amount, $this->note ?: null, auth()->id(), $this->ref)];
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(['type' => 'typeId', 'member' => 'typeId', 'client_ref' => 'typeId'][$key] ?? $key, $messages[0]);
            }

            return;
        }

        $total = array_sum(array_map(fn ($e) => (int) $e->amount, $entries));
        $this->open = false;
        unset($this->summary, $this->selected, $this->periods);
        $this->toast('success', ($this->mode === 'setor' ? 'Setoran ' : 'Penarikan ').$type->name.' '.\App\Support\Rupiah::format($total).' tercatat. Saldo '
            .\App\Support\Rupiah::format((int) end($entries)->balance_after).'.');
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.simpanan.member-savings');
    }
}
