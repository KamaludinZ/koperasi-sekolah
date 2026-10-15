<?php

namespace App\Livewire\Portal;

use App\Services\MemberLoanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Pinjaman Saya: pinjaman atas nama anggota yang sedang masuk (guard `member`) beserta status,
 * sisa, dan angsuran berikutnya. Tidak ada parameter id — data orang lain tidak bisa diminta.
 */
#[Layout('components.layouts.portal')]
#[Title('Pinjaman Saya')]
class Loans extends Component
{
    public const TABS = MemberLoanService::TABS;

    #[Url(as: 'tampil', except: 'semua')]
    public string $tab = 'semua';

    public function mount(): void
    {
        $this->setTab($this->tab);
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'semua';
    }

    /** @return list<array> pinjaman milik anggota yang masuk (lihat MemberLoanService::forMember) */
    #[Computed]
    public function loans(): array
    {
        $member = Auth::guard('member')->user();
        abort_unless($member, 401);

        return app(MemberLoanService::class)->forMember($member);
    }

    /** @return list<array> pinjaman yang lolos tab aktif */
    #[Computed]
    public function shown(): array
    {
        return MemberLoanService::filter($this->loans, $this->tab);
    }

    /** @return array<string,int> jumlah pinjaman per tab */
    #[Computed]
    public function counts(): array
    {
        return MemberLoanService::counts($this->loans);
    }

    #[Computed]
    public function totals(): array
    {
        return MemberLoanService::totals($this->loans);
    }

    /** Jumlah pinjaman anggota yang menunggak — untuk tanda di navigasi portal. */
    public static function overdueCount(int $memberId): int
    {
        return MemberLoanService::overdueCount($memberId);
    }

    public function render()
    {
        return view('livewire.portal.loans');
    }
}
