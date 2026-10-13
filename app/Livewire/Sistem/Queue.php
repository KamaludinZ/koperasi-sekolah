<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use App\Services\QueueService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sistem → Antrean (Admin): pekerjaan latar (ekspor laporan, notifikasi, cadangan) — status pekerja,
 * jumlah menunggu/gagal, daftar tugas menunggu & gagal (dari tabel jobs/failed_jobs) dengan pesan
 * galat singkat, serta pekerjaan selesai terbaru; ulangi/hapus tugas gagal (queue:retry/forget).
 * Data dari QueueService (sama dengan GET /sistem/antrean/data).
 */
#[Layout('components.layouts.app')]
#[Title('Antrean Pekerjaan')]
class Queue extends Component
{
    use NotifiesSave;

    public static function label(?string $class): string
    {
        return QueueService::label($class);
    }

    #[Computed]
    public function pending(): array
    {
        return app(QueueService::class)->pending();
    }

    #[Computed]
    public function failed(): array
    {
        return app(QueueService::class)->failed();
    }

    #[Computed]
    public function worker(): array
    {
        return app(QueueService::class)->worker();
    }

    #[Computed]
    public function recent(): array
    {
        return app(QueueService::class)->recent();
    }

    public function retry(string $uuid): void
    {
        if (app(QueueService::class)->retry($uuid)) {
            unset($this->failed, $this->pending);
            $this->toast('success', 'Tugas dimasukkan lagi ke antrean.');
        }
    }

    public function forget(string $uuid): void
    {
        if (app(QueueService::class)->forget($uuid)) {
            unset($this->failed);
            $this->toast('success', 'Tugas gagal dihapus dari daftar.');
        }
    }

    public function retryAll(): void
    {
        $n = app(QueueService::class)->retry('all');
        unset($this->failed, $this->pending);
        $this->toast('success', $n ? "{$n} tugas gagal dimasukkan lagi ke antrean." : 'Tidak ada tugas gagal.');
    }

    public function render()
    {
        return view('livewire.sistem.queue');
    }
}
