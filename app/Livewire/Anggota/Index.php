<?php

namespace App\Livewire\Anggota;

use App\Livewire\Forms\MemberForm;
use App\Models\Member;
use App\Services\MemberService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar anggota koperasi (siswa & guru) beserta saldo tabungan.
 * Data dari MemberService (sama dengan GET /anggota/daftar).
 */
#[Layout('components.layouts.app')]
#[Title('Anggota & Tabungan')]
class Index extends Component
{
    use WithPagination;

    /** Anggota per halaman — daftar tidak dimuat seluruhnya (sekolah bisa punya ribuan anggota). */
    public const PER_PAGE = 50;

    public const TYPES = Member::TYPES;

    public MemberForm $form;

    public bool $editing = false;

    public ?string $flash = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'jenis', except: '')]
    public string $type = '';

    #[Url(as: 'kelas', except: '')]
    public string $class = '';

    #[Url(as: 'nonaktif', except: false)]
    public bool $showInactive = false;

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'type', 'class', 'showInactive'], true)) {
            $this->resetPage(); // saringan berubah → kembali ke halaman 1
        }
    }

    public function updatedType(): void
    {
        if ($this->type === 'guru') {
            $this->class = '';
        }
    }

    #[Computed]
    public function page(): LengthAwarePaginator
    {
        return app(MemberService::class)->paginate([
            'search' => $this->search,
            'type' => $this->type,
            'class' => $this->class,
            'include_inactive' => $this->showInactive,
        ], self::PER_PAGE, $this->getPage());
    }

    /** Anggota di halaman ini (bentuk MemberService::toArray). @return list<array> */
    #[Computed]
    public function members(): array
    {
        return $this->page->items();
    }

    #[Computed]
    public function stats(): array
    {
        return app(MemberService::class)->stats();
    }

    /** @return list<string> */
    #[Computed]
    public function classes(): array
    {
        return app(MemberService::class)->classes();
    }

    public function create(): void
    {
        $this->form->blank($this->type === 'guru' ? 'guru' : 'siswa');
        $this->flash = null;
        $this->editing = true;
    }

    public function edit(int $memberId): void
    {
        $member = Member::find($memberId);
        if (! $member) {
            return;
        }

        $this->form->load(MemberService::toArray($member));
        $this->flash = null;
        $this->editing = true;
    }

    public function updatedFormType(): void
    {
        $this->form->switchType();
    }

    public function save(): void
    {
        $isNew = $this->form->id === null;
        $member = $this->form->save();

        $this->editing = false;
        $this->flash = $isNew ? "{$member['name']} terdaftar sebagai anggota." : "Data {$member['name']} diperbarui.";
        unset($this->page, $this->members, $this->stats, $this->classes);
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'type', 'class', 'showInactive');
    }

    public function render()
    {
        return view('livewire.anggota.index');
    }
}
