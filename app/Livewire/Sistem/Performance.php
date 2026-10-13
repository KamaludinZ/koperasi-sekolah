<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\DatabaseIndexes;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sistem → Performa & cache (Admin): cache laporan (umur, masa berlaku, rasio kena) dengan tombol
 * kosongkan, pemeriksaan indeks kolom pencarian/laporan per tabel (dari skema basis data) beserta jumlah
 * baris, dan halaman lambat (> 1 detik, 7 hari). Cache dari ReportCache (kena/meleset, terakhir dihitung);
 * kosongkan = naikkan versi data laporan sehingga semua laporan dihitung ulang.
 */
#[Layout('components.layouts.app')]
#[Title('Performa & Cache')]
class Performance extends Component
{
    use NotifiesSave;

    #[Computed]
    public function caches(): array
    {
        return collect(\App\Support\ReportCache::stats())->map(fn ($st, $key) => [
            'key' => 'laporan:'.$key, 'label' => \App\Support\ReportCache::LABELS[$key], 'ttl_min' => $key === 'today' ? 1 : (int) (\App\Support\ReportCache::TTL / 60),
            'age_min' => $st['at'] ? (int) floor(\Illuminate\Support\Carbon::parse($st['at'])->diffInMinutes(now(), true)) : null,
            'hits' => $st['hits'], 'misses' => $st['misses'],
        ])->values()->all();
    }

    #[Computed]
    public function slow(): array
    {
        return \App\Http\Middleware\TrackSlowRequests::report();
    }

    #[Computed]
    public function indexes(): array
    {
        return DatabaseIndexes::report();
    }

    #[Computed]
    public function missing(): int
    {
        return collect($this->indexes)->sum(fn ($t) => collect($t['checks'])->where('ok', false)->count());
    }

    public static function hitRate(array $c): int
    {
        $total = $c['hits'] + $c['misses'];

        return $total ? (int) round($c['hits'] / $total * 100) : 0;
    }

    /** Kosongkan cache laporan: versi data dinaikkan sehingga semua laporan dihitung ulang saat dibuka. */
    public function clearAll(): void
    {
        \Illuminate\Support\Facades\Cache::add(\App\Support\ReportCache::VERSION_KEY, 1);
        \Illuminate\Support\Facades\Cache::increment(\App\Support\ReportCache::VERSION_KEY);
        foreach (array_keys(\App\Support\ReportCache::LABELS) as $k) {
            \Illuminate\Support\Facades\Cache::forget("laporan:stat:{$k}:at");
        }
        unset($this->caches);
        \App\Services\AuditLog::record('cache.cleared', 'pengaturan', 'Mengosongkan cache laporan');
        $this->toast('success', 'Cache laporan dikosongkan; dihitung ulang saat dibuka berikutnya.');
    }

    public function render()
    {
        return view('livewire.sistem.performance');
    }
}
