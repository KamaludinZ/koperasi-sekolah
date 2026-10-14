<?php

namespace App\Livewire\Kasir;

use App\Services\ProductCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Pencarian & pemilihan barang di layar kasir.
 * Memilih barang mengirim event `product-picked` (payload: productId)
 * yang akan ditangkap oleh komponen keranjang.
 */
class ProductPicker extends Component
{
    public string $search = '';

    public string $category = '';

    public ?int $lastPicked = null;

    /** Umpan balik pencarian/scan terakhir: ['type' => success|error|info, 'text' => ...] */
    public ?array $scan = null;

    public function setCategory(string $category): void
    {
        $this->category = $this->category === $category ? '' : $category;
    }

    #[Computed]
    public function products(): array
    {
        return app(ProductCatalog::class)->search($this->search, $this->category);
    }

    /** Stok berubah setelah transaksi — muat ulang daftar barang. */
    #[On('transaction-completed')]
    public function refreshStock(): void
    {
        unset($this->products);
        $this->lastPicked = null;
    }

    public function pick(int $productId): void
    {
        $product = app(ProductCatalog::class)->find($productId);

        if (! $product || $product['stock'] < 1) {
            return;
        }

        $this->lastPicked = $productId;
        $this->dispatch('product-picked', productId: $productId);
    }

    /**
     * Enter di kolom cari: kode yang cocok persis (scan barcode) atau
     * satu-satunya hasil langsung dipilih, lalu kolom cari dikosongkan.
     * Selalu memberi umpan balik (ditambahkan / tidak ditemukan / stok habis / beberapa hasil)
     * agar scan yang gagal tidak lewat tanpa disadari.
     */
    public function pickFromSearch(): void
    {
        $raw = trim($this->search);
        if ($raw === '') {
            return;
        }
        if ($this->addByCode($raw, $this->products)) {
            $this->search = '';
            unset($this->products);
        }
    }

    /** Pilih dari pencarian cadangan di panel kamera, lalu kosongkan kolom cari. */
    public function pickFallback(int $productId): void
    {
        $product = app(ProductCatalog::class)->find($productId);
        if (! $product || $product['stock'] < 1) {
            return;
        }
        $this->pick($productId);
        $this->scan = ['type' => 'success', 'text' => "Ditambahkan: {$product['name']}"];
        $this->search = '';
        unset($this->products);
    }

    /**
     * Hasil pindai kamera (atau barcode reader): hanya kode yang cocok persis yang dimasukkan ke
     * keranjang; kolom cari tidak disentuh supaya ketikan kasir tidak hilang.
     */
    public function scanCode(string $code): void
    {
        $code = mb_substr(trim($code), 0, 64);
        if ($code !== '') {
            $this->addByCode($code, []);
        }
    }

    /** @param  list<array>  $results  hasil pencarian untuk cadangan bila kode tidak cocok persis */
    private function addByCode(string $raw, array $results): bool
    {
        $match = app(ProductCatalog::class)->findByCode(mb_strtolower($raw))
            ?? (count($results) === 1 ? $results[0] : null);

        if (! $match) {
            $this->scan = count($results) > 1
                ? ['type' => 'info', 'text' => count($results).' barang cocok dengan “'.$raw.'” — pilih salah satu.']
                : ['type' => 'error', 'text' => 'Barcode/kode “'.$raw.'” tidak dikenali. Periksa label atau cari dengan kode/nama barang.', 'unknown' => $raw];
            $this->dispatch('scan-feedback', type: $this->scan['type'], text: $this->scan['text']);
            if (isset($this->scan['unknown'])) {
                $this->dispatch('scan-unknown', code: $raw); // tampilan memindahkan kursor ke pencarian
            }

            return false;
        }
        if ($match['stock'] < 1) {
            $this->scan = ['type' => 'error', 'text' => "{$match['name']} stoknya habis."];
            $this->dispatch('scan-feedback', type: 'error', text: $this->scan['text']);

            return false;
        }

        $this->pick($match['id']);
        // Bunyi & pesan sukses dikirim keranjang (Cart::add) setelah jumlah benar-benar bertambah.
        $this->scan = ['type' => 'success', 'text' => "Ditambahkan: {$match['name']}"];

        return true;
    }

    /** Ketikan baru menghapus umpan balik scan sebelumnya. */
    public function updatedSearch(): void
    {
        if ($this->search !== '') {
            $this->scan = null;
        }
    }

    public function render()
    {
        return view('livewire.kasir.product-picker', [
            'categories' => app(ProductCatalog::class)->categories(),
        ]);
    }
}
