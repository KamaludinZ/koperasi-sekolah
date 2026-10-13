<?php

namespace App\Livewire\Kas;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kas Harian: rekap uang laci kasir per hari — modal awal, penjualan tunai/non-tunai,
 * setoran & penarikan kas, kas seharusnya vs hitungan akhir, dan selisih tiap shift.
 * Tahap frontend: angka dari MockData::cashDay (data tiruan, belum dari database).
 */
#[Layout('components.layouts.app')]
#[Title('Kas Harian')]
class Index extends Component
{
    /** Tanggal yang dilihat (Y-m-d); kosong = hari ini. */
    #[Url(as: 'tanggal', except: '')]
    public string $date = '';

    public function mount(): void
    {
        $this->date = $this->normalize($this->date);
    }

    public function updatedDate(): void
    {
        $this->date = $this->normalize($this->date);
    }

    /** Geser tanggal ±1 hari, tidak melewati hari ini. */
    public function shiftDay(int $days): void
    {
        $this->date = $this->normalize($this->day->copy()->addDays($days)->toDateString());
        unset($this->day);
    }

    public function goToday(): void
    {
        $this->date = '';
    }

    #[Computed]
    public function day(): Carbon
    {
        return $this->date === '' ? today() : Carbon::parse($this->date);
    }

    #[Computed]
    public function cash(): array
    {
        return MockData::cashDay($this->day->toDateString());
    }

    /** Tren 7 hari sampai tanggal yang dilihat. */
    #[Computed]
    public function week(): array
    {
        return MockData::cashWeek($this->day->toDateString());
    }

    /** Tanggal tidak valid atau di masa depan → hari ini (string kosong). */
    private function normalize(string $date): string
    {
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            return '';
        }

        return $day->format('Y-m-d') !== $date || $day->gte(today()) ? '' : $date;
    }

    public function render()
    {
        return view('livewire.kas.index');
    }
}
