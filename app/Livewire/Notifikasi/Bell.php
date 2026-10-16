<?php

namespace App\Livewire\Notifikasi;

use App\Services\NotificationService;
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

    /** Tombol berlabel "Notifikasi" (baris pintasan di menu samping). */
    public bool $labeled = false;

    #[On('notifications-changed')]
    public function refreshCount(): void
    {
        // render ulang
    }

    public function markRead(int $id): void
    {
        $this->notifications()->mark(auth()->user(), [$id]);
        $this->dispatch('notifications-changed');
    }

    public function markAllRead(): void
    {
        $this->notifications()->markAll(auth()->user());
        $this->dispatch('notifications-changed');
    }

    public function open(int $id)
    {
        $n = $this->notifications()->find(auth()->user(), $id);
        if (! $n) {
            return null;
        }
        $this->notifications()->mark(auth()->user(), [$id]);

        return $this->redirect($n->url ?? route('notifikasi'), navigate: true);
    }

    private function notifications(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.notifikasi.bell', [
            'unread' => $user ? $this->notifications()->unreadCount($user) : 0,
            'latest' => $user ? $this->notifications()->list($user, 'semua', self::PREVIEW) : [],
        ]);
    }
}
