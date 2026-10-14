<?php

namespace App\Livewire\Pinjaman;

use App\Livewire\Forms\LoanApplicationForm;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use App\Services\LoanService;
use App\Services\MemberService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Pengajuan (Admin & Pengurus): daftar pengajuan pinjaman per tahap keputusan —
 * menunggu keputusan, disetujui (belum cair), ditolak, dibatalkan. Pengajuan terbaru di atas.
 */
#[Layout('components.layouts.app')]
#[Title('Pengajuan Pinjaman')]
class Applications extends Component
{
    use WithPagination;

    /** Tab tahap pengajuan → status pinjaman. */
    public const TABS = ['diajukan' => 'Menunggu keputusan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan'];

    public const PER_PAGE = 20;

    #[Url(as: 'tahap', except: 'diajukan')]
    public string $tab = 'diajukan';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public LoanApplicationForm $form;

    /** Panel form pengajuan terbuka. */
    public bool $creating = false;

    public string $memberSearch = '';

    public ?string $flash = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'diajukan';
        $this->resetPage();
    }

    #[Computed]
    public function applications()
    {
        $status = array_key_exists($this->tab, self::TABS) ? $this->tab : 'diajukan';

        return app(LoanService::class)->query(['search' => mb_substr($this->search, 0, 100), 'status' => $status])
            ->paginate(self::PER_PAGE)->through(fn (Loan $l) => LoanService::toArray($l));
    }

    public function create(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $this->form->blank();
        $this->memberSearch = '';
        $this->flash = null;
        $this->creating = true;
    }

    public function cancel(): void
    {
        $this->creating = false;
    }

    /** @return list<array<string, mixed>> anggota aktif yang cocok (maks. 6) */
    #[Computed]
    public function memberResults(): array
    {
        return mb_strlen(trim($this->memberSearch)) >= 2 ? app(MemberService::class)->search(mb_substr($this->memberSearch, 0, 100), 6) : [];
    }

    /** Anggota terpilih + pinjaman yang masih terbuka (peringatan). */
    #[Computed]
    public function selectedMember(): ?array
    {
        $m = $this->form->member_id ? Member::active()->find($this->form->member_id) : null;

        return $m ? MemberService::toArray($m) + ['loans' => app(LoanApplicationService::class)->memberContext($m)] : null;
    }

    public function selectMember(int $id): void
    {
        $this->form->member_id = Member::active()->whereKey($id)->exists() ? $id : null;
        $this->memberSearch = '';
        $this->resetValidation('form.member_id');
        unset($this->selectedMember, $this->memberResults);
    }

    public function clearMember(): void
    {
        $this->form->member_id = null;
        unset($this->selectedMember);
    }

    public function setTenor(int $months): void
    {
        $this->form->tenor_months = in_array($months, LoanApplicationForm::TENOR_PRESETS, true) ? $months : $this->form->tenor_months;
        $this->resetValidation('form.tenor_months');
    }

    public function updatedFormInterestMode(): void
    {
        $this->form->interest_mode = in_array($this->form->interest_mode, ['nominal', 'persen'], true) ? $this->form->interest_mode : 'nominal';
        $this->resetValidation(['form.interest', 'form.interest_rate']);
    }

    public function save(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $data = $this->form->validated();
        $loan = app(LoanApplicationService::class)->create($data, auth()->id());

        $this->creating = false;
        $this->tab = 'diajukan';
        $this->search = '';
        $this->resetPage();
        $this->flash = "Pengajuan {$loan->code} untuk {$loan->member->name} dicatat dan menunggu keputusan.";
        unset($this->applications, $this->counts);
    }

    /** @return array<string, int> jumlah per tab */
    #[Computed]
    public function counts(): array
    {
        $counts = app(LoanService::class)->stats()['counts'];

        return collect(self::TABS)->map(fn ($label, $status) => $counts[$status] ?? 0)->all();
    }

    public function render()
    {
        return view('livewire.pinjaman.applications');
    }
}
