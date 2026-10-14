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

    #[Url(as: 'riwayat', except: 'simpanan')]
    public string $tab = 'simpanan';

    public int $limit = self::STEP;

    private function member(): Member
    {
        return Auth::guard('member')->user();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['simpanan', 'tabungan'], true) ? $tab : 'simpanan';
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
        $id = $this->member()->id;
        $rows = $this->tab === 'tabungan'
            ? SavingsTransaction::where('member_id', $id)->latest('created_at')->latest('id')->limit($this->limit + 1)->get()
                ->map(fn (SavingsTransaction $s) => ['id' => 't'.$s->id, 'at' => $s->created_at, 'in' => $s->type === 'setor',
                    'label' => $s->note ?: $s->type_label, 'amount' => (int) round((float) $s->amount), 'balance' => (int) round((float) $s->balance_after), 'type' => null])
            : MemberSavingEntry::with('savingType:id,name')->where('member_id', $id)->latest('created_at')->latest('id')->limit($this->limit + 1)->get()
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
