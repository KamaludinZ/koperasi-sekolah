<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Catalog;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
        Category::create(['name' => 'Perlengkapan Ibadah', 'color' => 'teal']);
    }

    private function cat(string $name): Category
    {
        return Category::where('name', $name)->firstOrFail();
    }

    public function test_page_is_reachable_from_barang_nav_for_menu_holders(): void
    {
        $this->get('/barang')->assertOk()->assertSee(route('barang.kategori'));
        $this->get('/barang/kategori')->assertOk()
            ->assertSee('Kategori barang')->assertSee('Perlengkapan Ibadah')->assertSee('Impor barang dari berkas')
            ->assertSee('Belum pernah mengimpor barang.')->assertSee(route('barang', ['category' => 'Alat Tulis']), false);

        $this->loginAs('pengurus@koperasi.test');
        $this->get('/barang/kategori')->assertOk();
        $this->loginAs('kasir@koperasi.test'); // kasir punya menu Barang: boleh melihat
        $this->get('/barang/kategori')->assertOk()->assertDontSee('Tambah kategori');
    }

    public function test_summary_from_real_categories(): void
    {
        $c = Livewire::test(Catalog::class);
        $s = $c->instance()->summary;
        $this->assertSame([5, 1, 14], [$s['categories'], $s['empty'], $s['products']]);
        $this->assertSame([0, null], [$s['imports_30d'], $s['pending']]);

        $atk = collect($c->instance()->categories)->firstWhere('name', 'Alat Tulis');
        $this->assertSame([5, 5, 2], [$atk['products'], $atk['active_products'], $atk['low_stock']]);
        $this->assertSame((int) Product::where('category_id', $atk['id'])->get()->sum(fn ($p) => $p->stock * $p->cost_price), $atk['stock_value']);
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
        $new = $this->cat('Aksesoris Sekolah');
        $this->assertSame([6, 'rose'], [$new->position, $new->color]);

        $c->call('edit', $new->id)->assertSet('name', 'Aksesoris Sekolah')
            ->set('name', 'Aksesoris')->set('color', 'stone')->call('save')->assertHasNoErrors();
        $this->assertSame(['Aksesoris', 'stone'], [$new->fresh()->name, $new->fresh()->color]);
        $this->assertNotNull(ActivityLog::where('event', 'category.updated')->first());
    }

    public function test_delete_moves_products_to_chosen_category(): void
    {
        $makanan = $this->cat('Makanan');
        $minuman = $this->cat('Minuman');
        $c = Livewire::test(Catalog::class)->call('confirmDelete', $makanan->id)->assertSee('Hapus kategori Makanan?')->assertSee('3 barang')
            ->call('delete')->assertHasErrors('moveTo')
            ->set('moveTo', $makanan->id)->call('delete')->assertHasErrors('moveTo')
            ->set('moveTo', $minuman->id)->call('delete')->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Kategori Makanan dihapus, barangnya dipindah ke Minuman.');

        $this->assertSoftDeleted($makanan);
        $this->assertSame(6, Product::where('category_id', $minuman->id)->count());
        $this->assertSame('Dipindah dari kategori Makanan', ActivityLog::where('event', 'product.updated')->value('reason'));

        $ibadah = $this->cat('Perlengkapan Ibadah');
        $c->call('confirmDelete', $ibadah->id)->call('delete')->assertHasNoErrors();
        $this->assertCount(3, $c->instance()->categories);
    }

    public function test_reorder_and_permissions(): void
    {
        $ibadah = $this->cat('Perlengkapan Ibadah');
        $c = Livewire::test(Catalog::class)->call('startReorder')->assertSet('reordering', true)
            ->call('move', $ibadah->id, -1)->call('move', $ibadah->id, -1)->call('move', $this->cat('Alat Tulis')->id, -1)
            ->call('saveOrder')->assertSet('reordering', false)->assertDispatched('toast', type: 'success');
        $this->assertSame(['Alat Tulis', 'Makanan', 'Perlengkapan Ibadah', 'Minuman', 'Seragam'], Category::ordered()->pluck('name')->all());
        $this->assertSame(1, ActivityLog::where('event', 'category.reordered')->count());

        $c->call('startReorder')->call('move', $this->cat('Alat Tulis')->id, 1)->call('cancelReorder');
        $this->assertSame('Alat Tulis', Category::ordered()->first()->name);

        $this->loginAs('kasir@koperasi.test');
        Livewire::test(Catalog::class)->call('create')->assertSet('formOpen', false)->assertDispatched('toast', type: 'error')
            ->call('confirmDelete', $ibadah->id)->assertSet('deleteId', null);
    }
}
