<?php

namespace App\Livewire\Pengaturan;

use App\Models\Setting;
use App\Services\MinStockService;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman utama Pengaturan Koperasi: ringkasan profil, struk, pajak & pembulatan,
 * jam buka, dan stok, dengan tautan ke halaman detail tiap bagian serta daftar
 * kelengkapan profil. Tahap frontend: pengaturan dari data tiruan (MockData::settings).
 */
#[Layout('components.layouts.app')]
#[Title('Pengaturan Koperasi')]
class Index extends Component
{
    public const ROUNDING = [
        'none' => 'Tanpa pembulatan',
        'up' => 'Selalu ke atas',
        'nearest' => 'Ke nilai terdekat',
        'down' => 'Selalu ke bawah',
    ];

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

    /** Contoh perhitungan pajak & pembulatan untuk belanja Rp12.340. */
    #[Computed]
    public function example(): array
    {
        $t = $this->settings['tax'];
        $subtotal = 12340;
        $tax = $t['enabled'] && ! $t['included'] ? (int) round($subtotal * $t['rate'] / 100) : 0;
        $total = $subtotal + $tax;
        $unit = max(1, (int) $t['rounding_unit']);
        $rounded = match ($t['rounding']) {
            'up' => (int) (ceil($total / $unit) * $unit),
            'down' => (int) (floor($total / $unit) * $unit),
            'nearest' => (int) (round($total / $unit) * $unit),
            default => $total,
        };

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total, 'rounded' => $rounded];
    }

    public function render()
    {
        return view('livewire.pengaturan.index');
    }
}
