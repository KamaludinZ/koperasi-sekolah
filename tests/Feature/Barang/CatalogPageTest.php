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

    public function test_add_and_edit_category_with_validation(): void
    {
        $c = Livewire::test(Catalog::class)->call('create')->assertSet('formOpen', true)
            ->assertSet('color', 'rose') // warna pertama yang belum dipakai
            ->set('name', '  ')->call('save')->assertHasErrors(['name' => 'required'])
            ->set('name', 'alat tulis')->call('save')->assertHasErrors('name')
            ->set('name', 'Aksesoris  Sekolah')->call('save')->assertHasNoErrors()
            ->assertSet('formOpen', false)
            ->assertDispatched('toast', type: 'success', message: 'Kategori Aksesoris Sekolah ditambahkan.');
        $new = collect($c->instance()->categories)->firstWhere('name', 'Aksesoris Sekolah');
        $this->assertSame(6, $new['position']);

        $c->call('edit', $new['id'])->assertSet('name', 'Aksesoris Sekolah')
            ->set('name', 'Aksesoris')->set('color', 'stone')->call('save')->assertHasNoErrors();
        $this->assertSame('stone', collect($c->instance()->categories)->firstWhere('id', $new['id'])['color']);
        $c->assertSee('Aksesoris');
    }

    public function test_delete_moves_products_to_chosen_category(): void
    {
        $c = Livewire::test(Catalog::class)->call('confirmDelete', 2)->assertSee('Hapus kategori Makanan?')->assertSee('3 barang')
            ->call('delete')->assertHasErrors('moveTo')
            ->set('moveTo', 2)->call('delete')->assertHasErrors('moveTo')
            ->set('moveTo', 3)->call('delete')->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Kategori Makanan dihapus, barangnya dipindah ke Minuman.');

        $cats = collect($c->instance()->categories);
        $this->assertNull($cats->firstWhere('id', 2));
        $this->assertSame(6, $cats->firstWhere('id', 3)['products']);
        $this->assertSame([1, 2, 3, 4], $cats->pluck('position')->all());

        // Kategori kosong langsung dihapus tanpa memilih tujuan.
        $c->call('confirmDelete', 5)->call('delete')->assertHasNoErrors();
        $this->assertCount(3, $c->instance()->categories);
    }

    public function test_reorder_and_permissions(): void
    {
        $c = Livewire::test(Catalog::class)->call('startReorder')->assertSet('reordering', true)
            ->call('move', 5, -1)->call('move', 5, -1)->call('move', 1, -1)
            ->call('saveOrder')->assertSet('reordering', false)->assertDispatched('toast', type: 'success');
        $this->assertSame(['Alat Tulis', 'Makanan', 'Perlengkapan Ibadah', 'Minuman', 'Seragam'], collect($c->instance()->categories)->pluck('name')->all());

        $c->call('startReorder')->call('move', 1, 1)->call('cancelReorder');
        $this->assertSame('Alat Tulis', $c->instance()->categories[0]['name']);

        $this->loginAs('kasir@koperasi.test');
        Livewire::test(Catalog::class)->assertDontSee('Tambah kategori')
            ->call('create')->assertSet('formOpen', false)->assertDispatched('toast', type: 'error')
            ->call('confirmDelete', 1)->assertSet('deleteId', null);
    }
}
