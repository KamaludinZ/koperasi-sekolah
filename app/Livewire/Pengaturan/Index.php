<?php

namespace App\Livewire\Pengaturan;

use App\Models\Setting;
use App\Services\MinStockService;
use App\Support\MockData;
use App\Support\PriceCalculator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman utama Pengaturan Koperasi: ringkasan profil, struk, pajak & pembulatan,
 * jam buka, dan stok, dengan tautan ke halaman detail tiap bagian serta daftar
 * kelengkapan profil. Pengaturan dari tabel settings (SettingsService).
 */
#[Layout('components.layouts.app')]
#[Title('Pengaturan Koperasi')]
class Index extends Component
{
    public const ROUNDING = PriceCalculator::ROUNDING;

    #[Computed]
    public function settings(): array
    {
        return MockData::settings();
    }

    /** Daftar kelengkapan profil koperasi (untuk struk & beranda publik). */
    #[Computed]
    public function checklist(): array
    {
        $p = MockData::profile();
        $r = $this->settings['receipt'];

        return [
            ['label' => 'Nama koperasi & madrasah', 'done' => filled($p['name']) && filled($p['school'])],
            ['label' => 'Alamat & nomor kontak', 'done' => filled($p['address']) && filled($p['phone'])],
            ['label' => 'Logo koperasi', 'done' => filled($p['logo'])],
            ['label' => 'Tulisan kaki struk', 'done' => filled($r['footer'])],
            ['label' => 'Jam buka', 'done' => count($p['hours']) > 0],
        ];
    }

    #[Computed]
    public function defaultMinStock(): int
    {
        return app(MinStockService::class)->defaultMinStock();
    }

    /** Contoh perhitungan pajak & pembulatan untuk belanja tunai Rp12.340. */
    #[Computed]
    public function example(): array
    {
        $r = PriceCalculator::compute(12340, $this->settings['tax']);

        return ['subtotal' => $r['subtotal'], 'tax' => $r['tax'], 'total' => $r['total'], 'rounded' => $r['payable']];
    }

    public function render()
    {
        return view('livewire.pengaturan.index');
    }
}
