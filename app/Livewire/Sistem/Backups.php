<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\BackupStatus;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sistem → Cadangan data (Admin): jadwal cadangan otomatis, ringkasan (cadangan terakhir berhasil,
 * total ukuran, gagal 30 hari), dan daftar cadangan terbaru dengan status, isi, ukuran, durasi, dan
 * pemicu (terjadwal/manual), plus tombol "Cadangkan sekarang" (dikerjakan di antrean cadangan) dan
 * unduh arsip. Data dari tabel backups (sama dengan GET /sistem/cadangan/data).
 */
#[Layout('components.layouts.app')]
#[Title('Cadangan Data')]
class Backups extends Component
{
    use NotifiesSave;

    public const FILTERS = ['semua' => 'Semua', 'berhasil' => 'Berhasil', 'gagal' => 'Gagal', 'manual' => 'Manual'];

    public const TYPES = \App\Models\Backup::TYPES;

    #[Url(as: 'tampil', except: 'semua')]
    public string $filter = 'semua';

    public bool $creating = false;

    public string $newType = 'lengkap';

    public string $newNote = '';

    public ?int $selectedId = null;

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'semua';
    }

    #[Computed]
    public function all(): array
    {
        return \App\Services\HealthCheckService::backupRows();
    }

    #[Computed]
    public function rows(): array
    {
        return array_values(array_filter($this->all, fn ($b) => match ($this->filter) {
            'berhasil', 'gagal' => $b['status'] === $this->filter,
            'manual' => $b['trigger'] === 'manual',
            default => true,
        }));
    }

    #[Computed]
    public function schedule(): array
    {
        return \App\Http\Controllers\Sistem\BackupController::schedule();
    }

    #[Computed]
    public function stats(): array
    {
        $ok = collect($this->all)->where('status', 'berhasil');
        $last = $ok->sortByDesc('created_at')->first();
        $next = now()->setTimeFromTimeString($this->schedule['time']);

        return [
            'last' => $last,
            'next_at' => ($next->isPast() ? $next->addDay() : $next)->toDateTimeString(),
            'count' => $ok->count(),
            'total_size' => (int) $ok->sum('size'),
            'failed_30d' => collect($this->all)->where('status', 'gagal')->filter(fn ($b) => $b['created_at'] >= now()->subDays(30)->toDateTimeString())->count(),
        ];
    }

    #[Computed]
    public function status(): array
    {
        return BackupStatus::summarize($this->all);
    }

    /** Ulangi cadangan setelah kegagalan (lengkap, terjadwal ulang). */
    public function retry(): void
    {
        $this->newType = 'lengkap';
        $this->newNote = 'Ulang setelah cadangan gagal';
        $this->create();
    }

    #[Computed]
    public function selected(): ?array
    {
        return $this->selectedId ? collect($this->all)->firstWhere('id', $this->selectedId) : null;
    }

    public function show(int $id): void
    {
        $this->selectedId = collect($this->all)->contains('id', $id) ? $id : null;
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public function openCreate(): void
    {
        $this->reset('newType', 'newNote');
        $this->resetValidation();
        $this->creating = true;
    }

    public function create(): void
    {
        $this->validate([
            'newType' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'newNote' => ['nullable', 'string', 'max:120'],
        ], ['newNote.max' => 'Catatan maksimal 120 karakter.']);

        if (\App\Models\Backup::where('status', 'berjalan')->where('started_at', '>=', now()->subHour())->exists()) {
            $this->addError('newType', 'Masih ada cadangan yang sedang dibuat.');

            return;
        }
        $b = app(\App\Services\BackupService::class)->start($this->newType, 'manual', trim($this->newNote) ?: null, auth()->id());
        \App\Jobs\RunBackup::dispatch($b->id);
        $this->creating = false;
        unset($this->all, $this->rows, $this->stats, $this->status);
        $done = $b->fresh();
        $this->toast($done->status === 'gagal' ? 'error' : 'success', match ($done->status) {
            'berhasil' => 'Cadangan dibuat.',
            'gagal' => 'Cadangan gagal: '.mb_strimwidth((string) $done->error, 0, 120, '…'),
            default => 'Cadangan sedang dibuat di latar.',
        });
    }

    public function render()
    {
        return view('livewire.sistem.backups');
    }
}
