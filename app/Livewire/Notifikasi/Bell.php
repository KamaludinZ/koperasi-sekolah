<?php

namespace App\Livewire\Notifikasi;

use App\Support\MockData;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lonceng notifikasi di sidebar dengan jumlah belum dibaca; ikut diperbarui saat
 * notifikasi ditandai dibaca (event `notifications-changed`).
 */
class Bell extends Component
{
    public bool $compact = false;

    #[On('notifications-changed')]
    public function refreshCount(): void
    {
        // render ulang
    }

    public function render()
    {
        return view('livewire.notifikasi.bell', ['unread' => MockData::unreadNotifications()]);
    }
}
