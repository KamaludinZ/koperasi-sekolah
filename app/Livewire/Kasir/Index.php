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
        return view('livewire.kasir.index', [
            'products' => MockData::products(),
            'categories' => MockData::categories(),
            'cashier' => 'Kasir Demo',
        ]);
    }
}
