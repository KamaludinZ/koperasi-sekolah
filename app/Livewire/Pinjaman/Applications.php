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

    /** Dialog keputusan: id pengajuan & jenis keputusan (disetujui/ditolak). */
    #[\Livewire\Attributes\Locked]
    public ?int $decidingId = null;

    #[\Livewire\Attributes\Locked]
    public string $decision = '';

    public string $decisionNote = '';

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

    public function openDecision(int $loanId, string $decision): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $loan = Loan::whereKey($loanId)->where('status', 'diajukan')->first();
        if (! $loan || ! in_array($decision, ['disetujui', 'ditolak'], true)) {
            $this->flash = 'Pengajuan ini sudah diputuskan atau tidak ditemukan.';
            unset($this->applications, $this->counts);

            return;
        }
        $this->decidingId = $loan->id;
        $this->decision = $decision;
        $this->decisionNote = '';
        $this->resetValidation('decisionNote');
    }

    public function closeDecision(): void
    {
        $this->decidingId = null;
        $this->decision = '';
    }

    #[Computed]
    public function deciding(): ?array
    {
        $loan = $this->decidingId ? Loan::with('member:id,member_code,name,class,type,deleted_at')->find($this->decidingId) : null;

        return $loan ? LoanService::toArray($loan) : null;
    }

    public function decide(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $loan = $this->decidingId ? Loan::find($this->decidingId) : null;
        if (! $loan) {
            $this->closeDecision();

            return;
        }
        $this->resetValidation('decisionNote');
        try {
            $loan = app(LoanApplicationService::class)->decide($loan, $this->decision, $this->decisionNote, auth()->id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $this->addError('decisionNote', $errors['note'][0] ?? $errors['decision'][0] ?? 'Keputusan gagal disimpan.');

            return;
        }

        $this->closeDecision();
        $this->flash = "Pengajuan {$loan->code} ({$loan->member->name}) ".($loan->status === 'disetujui' ? 'disetujui dan siap dicairkan.' : 'ditolak.');
        unset($this->applications, $this->counts, $this->deciding);
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
