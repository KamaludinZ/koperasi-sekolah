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

    public function render()
    {
        return view('livewire.portal.home');
    }
}
