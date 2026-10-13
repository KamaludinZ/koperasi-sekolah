<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sistem → Uji pemulihan (Admin): pilih cadangan yang berhasil, jalankan uji pemulihan ke basis data uji
 * terpisah (data asli tidak disentuh), lihat langkah-langkahnya, perbandingan jumlah baris per tabel
 * terhadap data asli, dan riwayat uji. Disarankan sebulan sekali. Uji dijalankan RestoreTestService lewat
 * antrean cadangan; riwayat dari tabel restore_tests.
 */
#[Layout('components.layouts.app')]
#[Title('Uji Pemulihan')]
class RestoreTest extends Component
{
    use NotifiesSave;

    /** Uji pemulihan disarankan paling lama tiap sekian hari. */
    public const INTERVAL_DAYS = 30;

    #[Url(as: 'cadangan', except: null)]
    public ?int $backupId = null;

    public bool $confirm = false;

    /** Hasil uji terakhir di layar (simulasi). */
    public ?array $result = null;

    public function mount(): void
    {
        if ($this->backupId && ! collect($this->backups)->contains('id', $this->backupId)) {
            $this->backupId = null;
        }
        $this->backupId ??= $this->backups[0]['id'] ?? null;
    }

    /** Cadangan yang bisa diuji (berhasil). */
    #[Computed]
    public function backups(): array
    {
        return array_values(array_filter(\App\Services\HealthCheckService::backupRows(), fn ($b) => $b['status'] === 'berhasil'));
    }

    #[Computed]
    public function backup(): ?array
    {
        return collect($this->backups)->firstWhere('id', $this->backupId);
    }

    #[Computed]
    public function history(): array
    {
        return \App\Models\RestoreTest::with('runner:id,name')->latest('started_at')->latest('id')->limit(30)->get()->map->toRow()->all();
    }

    #[Computed]
    public function due(): bool
    {
        return self::isDue($this->history);
    }

    /** Belum ada uji berhasil dalam INTERVAL_DAYS hari terakhir? */
    public static function isDue(array $history): bool
    {
        $last = collect($history)->where('status', 'berhasil')->max('started_at');

        return ! $last || $last < now()->subDays(self::INTERVAL_DAYS)->toDateTimeString();
    }

    public function run(): void
    {
        $this->resetErrorBag();
        $b = $this->backup;
        if (! $b) {
            $this->addError('backupId', 'Pilih cadangan yang akan diuji.');

            return;
        }
        if (! $this->confirm) {
            $this->addError('confirm', 'Centang konfirmasi dulu.');

            return;
        }

        \App\Jobs\RunRestoreTest::dispatch($b['id'], auth()->id());
        $test = \App\Models\RestoreTest::where('backup_id', $b['id'])->latest('id')->first();
        $this->result = $test ? [
            'backup' => $test->backup_name, 'backup_at' => $test->backup_at?->toDateTimeString(), 'status' => $test->status, 'duration_s' => $test->duration_s,
            'error' => $test->error, 'steps' => $test->steps ?? [], 'tables' => array_map(fn ($t) => [...$t, 'label' => self::label($t['table']), 'newer' => $t['live'] - $t['backup']], $test->tables ?? []),
        ] : ['status' => 'berjalan', 'backup' => $b['name'], 'backup_at' => $b['created_at'], 'duration_s' => 0, 'error' => null, 'steps' => [], 'tables' => []];
        $this->confirm = false;
        unset($this->history, $this->due);
        $this->toast($this->result['status'] === 'gagal' ? 'error' : 'success', match ($this->result['status']) {
            'berhasil' => 'Uji pemulihan selesai: cadangan bisa dipulihkan.',
            'gagal' => 'Uji pemulihan gagal: '.mb_strimwidth((string) $this->result['error'], 0, 120, '…'),
            default => 'Uji pemulihan berjalan di latar.',
        });
    }

    /** Label tabel yang mudah dibaca. */
    public static function label(string $table): string
    {
        return \App\Services\RestoreTestService::LABELS[$table] ?? str_replace('_', ' ', ucfirst($table));
    }

    public function render()
    {
        return view('livewire.sistem.restore-test');
    }
}
