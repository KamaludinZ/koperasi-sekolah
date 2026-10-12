<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use Livewire\Livewire;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    public function test_first_page_shows_ten_of_fourteen(): void
    {
        $page = Livewire::test(Index::class)
            ->assertSee('1–10')
            ->assertSee('Halaman berikutnya');

        $this->assertCount(10, $page->instance()->paginated->items());
    }

    public function test_next_page_shows_remaining_items(): void
    {
        $page = Livewire::test(Index::class)->call('nextPage');

        $this->assertSame(2, $page->instance()->paginated->currentPage());
        $this->assertCount(4, $page->instance()->paginated->items());
    }

    public function test_search_resets_to_first_page(): void
    {
        $page = Livewire::test(Index::class)
            ->call('gotoPage', 2)
            ->set('search', 'pulpen');

        $this->assertSame(1, $page->instance()->paginated->currentPage());
        $this->assertSame(['ATK002'], array_column($page->instance()->paginated->items(), 'code'));
    }

    public function test_per_page_option(): void
    {
        $page = Livewire::test(Index::class)->set('perPage', 25)->assertDontSee('Halaman berikutnya');

        $this->assertCount(14, $page->instance()->paginated->items());
    }
}
