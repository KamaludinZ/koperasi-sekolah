<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_page_is_reachable_from_barang_nav_for_menu_holders(): void
    {
        $this->get('/barang')->assertOk()->assertSee(route('barang.kategori'));
        $this->get('/barang/kategori')->assertOk()
            ->assertSee('Kategori barang')->assertSee('Perlengkapan Ibadah')->assertSee('Impor barang dari berkas')
            ->assertSee('harga-baru-oktober.csv')->assertSee(route('barang', ['category' => 'Alat Tulis']), false);

        $this->loginAs('pengurus@koperasi.test');
        $this->get('/barang/kategori')->assertOk();
        $this->loginAs('kasir@koperasi.test'); // kasir punya menu Barang: boleh melihat
        $this->get('/barang/kategori')->assertOk();
    }

    public function test_summary_and_pending_import(): void
    {
        $s = Livewire::test(Catalog::class)->instance()->summary;
        $this->assertSame(5, $s['categories']);
        $this->assertSame(1, $s['empty']);
        $this->assertSame(14, $s['products']);
        $this->assertSame(4, $s['imports_30d']);   // satu impor 31 hari lalu
        $this->assertSame(40, $s['imported_rows_30d']);
        $this->assertSame(6, $s['failed_rows_30d']);
        $this->assertSame('barang-semester-ganjil.xlsx', $s['pending']['file_name']);

        Livewire::test(Catalog::class)->assertSee('menunggu diperiksa')->assertSee('Sebagian tersimpan')->assertSee('berkas tidak terbaca');
    }

    public function test_links_to_pages_not_yet_built_are_marked_soon(): void
    {
        $this->assertNull(Catalog::link('barang.halaman-yang-belum-ada'));
        $this->assertSame(route('barang'), Catalog::link('barang'));
    }
}
