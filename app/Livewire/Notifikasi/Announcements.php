<?php

namespace App\Livewire\Notifikasi;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\MockData;
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

    public const TARGETS = [
        'semua' => 'Semua (petugas & anggota)',
        'anggota' => 'Anggota (siswa & guru)',
        'kasir' => 'Petugas kasir',
        'pengurus' => 'Pengurus',
    ];

    public string $title = '';

    public string $message = '';

    public string $target = 'semua';

    public bool $show_public = true;

    public string $expires_at = '';

    #[Computed]
    public function announcements(): array
    {
        return MockData::announcements();
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:80'],
            'message' => ['required', 'string', 'min:10', 'max:500'],
            'target' => ['required', 'in:'.implode(',', array_keys(self::TARGETS))],
            'show_public' => ['boolean'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    protected function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'title.min' => 'Judul minimal :min karakter.',
            'message.required' => 'Isi pengumuman wajib diisi.',
            'message.min' => 'Isi minimal :min karakter.',
            'max' => 'Maksimal :max karakter.',
            'expires_at.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum hari ini.',
        ];
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
            $public = $data['show_public'] && in_array($data['target'], ['semua', 'anggota'], true);

            MockData::saveAnnouncement([
                'title' => trim($data['title']),
                'message' => trim($data['message']),
                'target' => $data['target'],
                'show_public' => $public,
                'expires_at' => $data['expires_at'] ?: null,
            ]);

            $this->reset('title', 'message', 'target', 'expires_at');
            $this->show_public = true;
            unset($this->announcements);
            $this->dispatch('notifications-changed');
        }, 'Pengumuman diterbitkan.');
    }

    public function delete(int $id): void
    {
        if (MockData::deleteAnnouncement($id)) {
            unset($this->announcements);
            $this->dispatch('notifications-changed');
            $this->toast('success', 'Pengumuman dihapus.');
        }
    }

    public function render()
    {
        return view('livewire.notifikasi.announcements');
    }
}
