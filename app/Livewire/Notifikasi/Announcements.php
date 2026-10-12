<?php

namespace App\Livewire\Notifikasi;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Notification;
use App\Services\AnnouncementService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Buat & kelola pengumuman (admin & pengurus): judul, isi, sasaran, tampil di beranda
 * publik, tanggal berakhir; pratinjau langsung dan daftar pengumuman yang sudah dibuat.
 * Pengumuman masuk ke notifikasi petugas sesuai sasaran. Disimpan di tabel notifications (tipe pengumuman).
 */
#[Layout('components.layouts.app')]
#[Title('Buat Pengumuman')]
class Announcements extends Component
{
    use NotifiesSave;

    public const TARGETS = AnnouncementService::TARGETS;

    public string $title = '';

    public string $message = '';

    public string $target = 'semua';

    public bool $show_public = true;

    public string $expires_at = '';

    #[Computed]
    public function announcements(): array
    {
        return app(AnnouncementService::class)->list();
    }

    /** Admin: semua pengumuman; Pengurus: hanya buatannya. */
    public function canManage(array $announcement): bool
    {
        return auth()->user()?->hasRole('admin') || $announcement['author_id'] === auth()->id();
    }

    protected function rules(): array
    {
        return AnnouncementService::rules();
    }

    protected function messages(): array
    {
        return AnnouncementService::messages();
    }

    public function updatedTarget(): void
    {
        // Pengumuman khusus petugas tidak ditampilkan di beranda publik.
        if (in_array($this->target, ['kasir', 'pengurus'], true)) {
            $this->show_public = false;
        }
    }

    public function publish(): void
    {
        $this->saveWithToast(function () {
            $data = $this->validate();
            app(AnnouncementService::class)->create($data, auth()->user());

            $this->reset('title', 'message', 'target', 'expires_at');
            $this->show_public = true;
            unset($this->announcements);
            $this->dispatch('notifications-changed');
        }, 'Pengumuman diterbitkan.');
    }

    public function delete(int $id): void
    {
        $n = Notification::where('type', 'pengumuman')->whereNotNull('created_by')->find($id);
        if (! $n) {
            return;
        }

        try {
            app(AnnouncementService::class)->delete($n, auth()->user());
        } catch (AuthorizationException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        unset($this->announcements);
        $this->dispatch('notifications-changed');
        $this->toast('success', 'Pengumuman dihapus.');
    }

    public function render()
    {
        return view('livewire.notifikasi.announcements');
    }
}
