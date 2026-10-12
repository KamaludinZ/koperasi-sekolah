<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Support\MockData;
use Livewire\Livewire;
use Tests\TestCase;

class LowStockPanelTest extends TestCase
{
    public function test_panel_lists_out_of_stock_first_then_low(): void
    {
        $codes = array_column(Livewire::test(Index::class)->instance()->lowStock, 'code');

        // Habis (0/5) → Keripik 6/10 → Pensil 12/15 → Topi 4/5
        $this->assertSame(['ATK005', 'MKN003', 'ATK003', 'SRG002'], $codes);
    }

    public function test_panel_renders_with_suggestion_and_action(): void
    {
        Livewire::test(Index::class)
            ->assertSee('4 barang perlu segera ditambah stoknya')
            ->assertSee('Saran restok')
            ->assertSee('+10 pcs') // Penggaris: 5*2 - 0
            ->assertSee('Tambah stok');
    }

    public function test_inactive_products_are_excluded_and_restock_clears_warning(): void
    {
        MockData::saveProduct(['id' => 5, 'is_active' => false]);
        MockData::saveProduct(['id' => 13, 'stock' => 50]);

        $codes = array_column(Livewire::test(Index::class)->instance()->lowStock, 'code');
        $this->assertSame(['MKN003', 'ATK003'], $codes);
    }

    public function test_low_stock_filter(): void
    {
        Livewire::test(Index::class)
            ->call('toggleStatus', 'menipis')
            ->assertSee('Topi Sekolah')
            ->assertDontSee('Buku Tulis 38 Lembar');
    }

    public function test_sidebar_shows_alert_badge(): void
    {
        $this->get('/kasir')->assertSee('4 barang perlu ditambah stoknya');
    }
}
