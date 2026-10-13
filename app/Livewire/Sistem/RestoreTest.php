<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\MockData;
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
 * terhadap data asli, dan riwayat uji. Disarankan sebulan sekali. Tahap tampilan: hasil uji disimulasikan
 * (jumlah baris cadangan ≈ data asli saat cadangan dibuat); riwayat dari MockData::restoreTests().
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

    /** @var list<array> uji yang dijalankan di layar (simulasi) */
    public array $ran = [];

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
        return array_values(array_filter(MockData::backups(), fn ($b) => $b['status'] === 'berhasil'));
    }

    #[Computed]
    public function backup(): ?array
    {
        return collect($this->backups)->firstWhere('id', $this->backupId);
    }

    #[Computed]
    public function history(): array
    {
        return [...$this->ran, ...MockData::restoreTests()];
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

        // Simulasi: jumlah baris di cadangan = jumlah sekarang dikurangi data yang dibuat setelah cadangan.
        $tables = [];
        foreach (MockData::RESTORE_TABLES as $table => $label) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $live = DB::table($table)->count();
            $after = Schema::hasColumn($table, 'created_at') ? DB::table($table)->where('created_at', '>', $b['created_at'])->count() : 0;
            $tables[] = ['table' => $table, 'label' => $label, 'backup' => $live - $after, 'live' => $live, 'newer' => $after];
        }
        $this->result = [
            'backup' => $b['name'], 'backup_at' => $b['created_at'], 'status' => 'berhasil', 'duration_s' => 30 + count($tables) * 2,
            'steps' => array_map(fn ($label) => ['label' => $label, 'ok' => true], array_values(MockData::RESTORE_STEPS)),
            'tables' => $tables,
        ];
        array_unshift($this->ran, [
            'id' => 100 + count($this->ran), 'backup' => $b['name'], 'backup_at' => $b['created_at'], 'started_at' => now()->toDateTimeString(),
            'duration_s' => $this->result['duration_s'], 'status' => 'berhasil', 'by' => auth()->user()->name, 'tables' => count($tables), 'mismatches' => 0, 'note' => null,
        ]);
        $this->confirm = false;
        unset($this->history, $this->due);
        $this->toast('success', 'Uji pemulihan selesai: cadangan bisa dipulihkan. (simulasi)');
    }

    public function render()
    {
        return view('livewire.sistem.restore-test');
    }
}
