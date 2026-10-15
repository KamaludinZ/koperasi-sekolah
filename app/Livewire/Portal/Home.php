<?php

namespace App\Livewire\Portal;

use App\Models\Member;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Portal anggota → Beranda: sapaan & ringkasan akun anggota yang masuk. */
#[Layout('components.layouts.portal')]
#[Title('Portal Anggota')]
class Home extends Component
{
    #[Computed]
    public function member(): Member
    {
        return Auth::guard('member')->user();
    }

    /** Ringkasan pinjaman anggota (sisa, tunggakan, angsuran terdekat) untuk kartu di beranda. */
    #[Computed]
    public function loanTotals(): ?array
    {
        if (! \Illuminate\Support\Facades\Route::has('portal.pinjaman')) {
            return null;
        }

        return app(\App\Services\MemberLoanService::class)->outstanding($this->member)['totals'];
    }

    public function render()
    {
        return view('livewire.portal.home');
    }
}
