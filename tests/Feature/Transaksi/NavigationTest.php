<?php

namespace Tests\Feature\Transaksi;

use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_rows_link_to_detail(): void
    {
        $first = MockData::transactions()[0];

        $this->get('/transaksi')
            ->assertSee(route('transaksi.show', $first['id']), false)
            ->assertSee('Lihat detail transaksi '.$first['invoice_number']);
    }

    public function test_detail_page_with_newer_and_older_links(): void
    {
        $all = MockData::transactions();
        $middle = $all[1];

        $this->get("/transaksi/{$middle['id']}")
            ->assertOk()
            ->assertSee($middle['invoice_number'])
            ->assertSee(route('transaksi.show', $all[0]['id']), false)
            ->assertSee(route('transaksi.show', $all[2]['id']), false)
            ->assertSee(route('transaksi'), false);
    }

    public function test_newest_has_no_newer_link_and_unknown_is_404(): void
    {
        $newest = MockData::transactions()[0];

        $this->get("/transaksi/{$newest['id']}")->assertOk()->assertDontSee('x-ref="newer"', false);
        $this->get('/transaksi/99999')->assertNotFound();
    }
}
