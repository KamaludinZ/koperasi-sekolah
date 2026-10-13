<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sistem → Antrean (Admin): pekerjaan latar (ekspor laporan, notifikasi, cadangan) — status pekerja,
 * jumlah menunggu/gagal, daftar tugas menunggu & gagal (dari tabel jobs/failed_jobs) dengan pesan
 * galat singkat, serta pekerjaan selesai terbaru. Ulangi/hapus tugas gagal disimulasikan di tahap ini;
 * status pekerja & pekerjaan selesai dari MockData.
 */
#[Layout('components.layouts.app')]
#[Title('Antrean Pekerjaan')]
class Queue extends Component
{
    use NotifiesSave;

    /** @var list<string> uuid tugas gagal yang sudah diulang/dihapus di layar (simulasi) */
    public array $handled = [];

    public static function label(?string $class): string
    {
        return MockData::JOB_LABELS[$class] ?? class_basename((string) $class);
    }

    /** @return list<array{id: int, name: string, queue: string, attempts: int, waiting_min: int, created_at: string}> */
    #[Computed]
    public function pending(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')->orderBy('id')->limit(100)->get()->map(function ($j) {
            $payload = json_decode($j->payload, true) ?: [];

            return [
                'id' => $j->id,
                'name' => self::label($payload['displayName'] ?? null),
                'queue' => $j->queue,
                'attempts' => (int) $j->attempts,
                'waiting_min' => (int) floor((time() - (int) $j->created_at) / 60),
                'created_at' => Carbon::createFromTimestamp((int) $j->created_at)->toDateTimeString(),
            ];
        })->all();
    }

    /** @return list<array{uuid: string, name: string, queue: string, failed_at: string, error: string}> */
    #[Computed]
    public function failed(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')->latest('failed_at')->limit(50)->get()
            ->reject(fn ($f) => in_array($f->uuid, $this->handled, true))
            ->map(function ($f) {
                $payload = json_decode($f->payload, true) ?: [];

                return [
                    'uuid' => $f->uuid,
                    'name' => self::label($payload['displayName'] ?? null),
                    'queue' => $f->queue,
                    'failed_at' => Carbon::parse($f->failed_at)->toDateTimeString(),
                    'error' => mb_strimwidth(trim(strtok((string) $f->exception, "\n")), 0, 160, '…'),
                ];
            })->values()->all();
    }

    #[Computed]
    public function worker(): array
    {
        return MockData::queueWorker();
    }

    #[Computed]
    public function recent(): array
    {
        return MockData::recentJobs();
    }

    public function retry(string $uuid): void
    {
        $this->handle($uuid, 'Tugas dimasukkan lagi ke antrean.');
    }

    public function forget(string $uuid): void
    {
        $this->handle($uuid, 'Tugas gagal dihapus dari daftar.');
    }

    public function retryAll(): void
    {
        foreach ($this->failed as $f) {
            $this->handled[] = $f['uuid'];
        }
        unset($this->failed);
        $this->toast('success', 'Semua tugas gagal dimasukkan lagi ke antrean. (simulasi)');
    }

    private function handle(string $uuid, string $message): void
    {
        if (! collect($this->failed)->contains('uuid', $uuid)) {
            return;
        }
        $this->handled[] = $uuid; // Simulasi: backend menjalankan queue:retry / queue:forget.
        unset($this->failed);
        $this->toast('success', $message.' (simulasi)');
    }

    public function render()
    {
        return view('livewire.sistem.queue');
    }
}
