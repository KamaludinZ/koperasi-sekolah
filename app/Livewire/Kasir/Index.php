<?php

namespace App\Livewire\Kasir;

use App\Models\Role;
use App\Support\Petugas;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Layar Kasir')]
class Index extends Component
{
    public function render()
    {
        $user = Petugas::current();

        return view('livewire.kasir.index', [
            'cashier' => $user['name'] ?? 'Kasir Demo',
            'role' => Role::LABELS[$user['role'] ?? 'kasir'] ?? 'Kasir',
        ]);
    }
}
