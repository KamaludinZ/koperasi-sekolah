<?php

namespace App\Livewire\Portal;

use App\Services\ProductCatalog;
use App\Support\PortalCart;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Belanja: katalog barang aktif (cari & kategori) dan keranjang (PortalCart, per sesi
 * anggota). Harga & stok dari data barang; jumlah dibatasi stok. Lanjut ke checkout mandiri.
 */
#[Layout('components.layouts.portal')]
#[Title('Belanja')]
class Shop extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'kategori', except: '')]
    public string $category = '';

    public bool $cartOpen = false;

    #[Computed]
    public function products(): array
    {
        return app(ProductCatalog::class)->search($this->search, $this->category);
    }

    #[Computed]
    public function categories(): array
    {
        return app(ProductCatalog::class)->categories();
    }

    #[Computed]
    public function cart(): array
    {
        return PortalCart::summary();
    }

    public function setCategory(string $category): void
    {
        $this->category = $this->category === $category ? '' : $category;
    }

    public function add(int $productId): void
    {
        $before = PortalCart::items()[$productId] ?? 0;
        $after = PortalCart::set($productId, $before + 1);
        unset($this->cart);
        if ($after === $before) {
            $this->dispatch('toast', type: 'error', message: $after ? 'Jumlah sudah mencapai stok yang tersedia.' : 'Barang ini sedang habis.');
        }
    }

    public function setQty(int $productId, int $qty): void
    {
        $after = PortalCart::set($productId, $qty);
        unset($this->cart);
        if ($qty > $after && $after > 0) {
            $this->dispatch('toast', type: 'error', message: "Stok hanya tersedia {$after}.");
        }
    }

    public function clearCart(): void
    {
        PortalCart::clear();
        unset($this->cart);
        $this->cartOpen = false;
    }

    public function render()
    {
        return view('livewire.portal.shop');
    }
}
