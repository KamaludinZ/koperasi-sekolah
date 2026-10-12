<?php

namespace App\Livewire\Kasir;

use App\Support\MockData;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Layar Kasir')]
class Index extends Component
{
    public function render()
    {
        $user = MockData::currentUser();

        return view('livewire.kasir.index', [
            'cashier' => $user['name'] ?? 'Kasir Demo',
            'role' => MockData::ROLES[$user['role'] ?? 'kasir'] ?? 'Kasir',
        ]);
    }
}
