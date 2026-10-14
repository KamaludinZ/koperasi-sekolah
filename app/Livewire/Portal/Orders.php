<?php

namespace App\Livewire\Portal;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Portal anggota → Pesanan: daftar pesanan checkout mandiri milik anggota (yang aktif dulu). */
#[Layout('components.layouts.portal')]
#[Title('Pesanan Saya')]
class Orders extends Component
{
    #[Computed]
    public function orders(): array
    {
        return Order::with('savingType:id,name')->where('member_id', Auth::guard('member')->id())
            ->orderByRaw("CASE WHEN status IN ('selesai','dibatalkan') THEN 1 ELSE 0 END")
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(fn (Order $o) => OrderService::toArray($o))->all();
    }

    public function render()
    {
        return view('livewire.portal.orders');
    }
}
