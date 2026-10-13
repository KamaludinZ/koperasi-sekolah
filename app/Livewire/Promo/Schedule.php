<?php

namespace App\Livewire\Promo;

use App\Services\PromoService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Jadwal & dampak promo: linimasa periode promo & kupon (4 minggu lalu s.d. 8 minggu ke depan,
 * bisa digeser), tanda hari ini, bentrok promo pada cakupan yang sama, serta dampak tiap promo
 * terhadap penjualan (rata-rata harian sebelum vs selama promo, potongan, efek bersih).
 * Data dari database lewat PromoService.
 */
#[Layout('components.layouts.app')]
#[Title('Jadwal & Dampak Promo')]
class Schedule extends Component
{
    /** Panjang jendela linimasa (hari) dan pergeseran per klik. */
    public const WINDOW_DAYS = 84;

    public const STEP_DAYS = 28;

    /** Pergeseran jendela dalam minggu dari posisi bawaan (0 = 4 minggu lalu s.d. 8 minggu ke depan). */
    #[Url(as: 'geser', except: 0)]
    public int $offset = 0;

    public function move(int $direction): void
    {
        $this->offset = max(-12, min(12, $this->offset + ($direction <=> 0) * (self::STEP_DAYS / 7)));
    }

    public function resetWindow(): void
    {
        $this->offset = 0;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function window(): array
    {
        $start = today()->subDays(28)->addWeeks($this->offset)->startOfWeek();

        return [$start, $start->copy()->addDays(self::WINDOW_DAYS - 1)];
    }

    /** Baris linimasa promo & kupon pada jendela ini (PromoService::timeline). */
    #[Computed]
    public function rows(): array
    {
        [$from, $to] = $this->window;

        return app(PromoService::class)->timeline($from, $to);
    }

    /** Label minggu untuk sumbu linimasa: posisi (%) dan tanggal awal minggu. */
    #[Computed]
    public function weeks(): array
    {
        [$from] = $this->window;

        return collect(range(0, self::WINDOW_DAYS / 7 - 1))->map(fn ($w) => [
            'left' => round($w * 7 / self::WINDOW_DAYS * 100, 2),
            'label' => $from->copy()->addWeeks($w)->translatedFormat('d M'),
        ])->all();
    }

    /** Posisi garis hari ini (%), null bila di luar jendela. */
    #[Computed]
    public function todayAt(): ?float
    {
        [$from, $to] = $this->window;

        return today()->between($from, $to) ? round(($from->diffInDays(today()) + 0.5) / self::WINDOW_DAYS * 100, 2) : null;
    }

    /** Dampak promo yang sudah mulai, efek bersih terbaik dulu (PromoService::impacts). */
    #[Computed]
    public function impacts(): array
    {
        return app(PromoService::class)->impacts();
    }

    public function render()
    {
        return view('livewire.promo.schedule');
    }
}
