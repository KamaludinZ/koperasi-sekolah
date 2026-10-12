<?php

namespace App\Livewire\Notifikasi;

use App\Support\MockData;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lonceng notifikasi (sidebar & bar atas ponsel): badge jumlah belum dibaca, panel
 * pratinjau 5 notifikasi terbaru dengan tandai dibaca / tandai semua / buka tautan,
 * diperbarui tiap 60 detik dan saat notifikasi berubah (`notifications-changed`).
 * Data dari tabel notifications lewat NotificationService.
 */
class Bell extends Component
{
    public const PREVIEW = 5;

    public bool $compact = false;

    #[On('notifications-changed')]
    public function refreshCount(): void
    {
        // render ulang
    }

    public function markRead(int $id): void
    {
        MockData::markNotifications([$id]);
        $this->dispatch('notifications-changed');
    }

    public function markAllRead(): void
    {
        $ids = collect(MockData::notifications())->where('is_read', false)->pluck('id')->all();
        MockData::markNotifications($ids);
        $this->dispatch('notifications-changed');
    }

    public function open(int $id)
    {
        $n = collect(MockData::notifications())->firstWhere('id', $id);
        if (! $n) {
            return null;
        }
        MockData::markNotifications([$id]);

        return $this->redirect($n['url'] ?? route('notifikasi'), navigate: true);
    }

    public function render()
    {
        $all = collect(MockData::notifications());

        return view('livewire.notifikasi.bell', [
            'unread' => $all->where('is_read', false)->count(),
            'latest' => $all->take(self::PREVIEW)->all(),
        ]);
    }
}
