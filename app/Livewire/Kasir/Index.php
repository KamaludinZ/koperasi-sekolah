<?php

namespace App\Livewire\Kasir;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Layar Kasir')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.kasir.index', [
            'cashier' => 'Kasir Demo',
        ]);
    }
}
