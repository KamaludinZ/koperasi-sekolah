<?php

namespace App\Livewire\Sistem;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\DatabaseIndexes;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sistem → Performa & cache (Admin): cache laporan (umur, masa berlaku, rasio kena) dengan tombol
 * kosongkan, pemeriksaan indeks kolom pencarian/laporan per tabel (dari skema basis data) beserta jumlah
 * baris, dan kueri lambat terbaru. Cache & kueri lambat tahap ini dari MockData; kosongkan disimulasikan.
 */
#[Layout('components.layouts.app')]
#[Title('Performa & Cache')]
class Performance extends Component
{
    use NotifiesSave;

    /** @var list<string> kunci cache yang dikosongkan di layar (simulasi) */
    public array $cleared = [];

    #[Computed]
    public function caches(): array
    {
        return array_map(fn ($c) => in_array($c['key'], $this->cleared, true) ? [...$c, 'age_min' => null] : $c, MockData::reportCaches());
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

    public function clear(string $key): void
    {
        if (! collect(MockData::reportCaches())->contains('key', $key)) {
            return;
        }
        $this->cleared[] = $key;
        unset($this->caches);
        $this->toast('success', 'Cache dikosongkan; dihitung ulang saat dibuka berikutnya. (simulasi)');
    }

    public function clearAll(): void
    {
        $this->cleared = array_column(MockData::reportCaches(), 'key');
        unset($this->caches);
        $this->toast('success', 'Semua cache laporan dikosongkan. (simulasi)');
    }

    public function render()
    {
        return view('livewire.sistem.performance');
    }
}
