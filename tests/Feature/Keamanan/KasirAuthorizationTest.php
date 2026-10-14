<?php

namespace Tests\Feature\Keamanan;

use App\Livewire\Anggota\Index as AnggotaIndex;
use App\Livewire\Barang\Index as BarangIndex;
use App\Livewire\Barang\MinStockSettings;
use App\Livewire\Transaksi\VoidForm;
use App\Models\Member;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kasir hanya melihat katalog barang, tidak boleh menghapus anggota atau mengatur batas bon khusus.
 */
class KasirAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_kasir_cannot_modify_catalog_through_json(): void
    {
        $id = $this->pid('ATK001');
        $before = Product::find($id)->only(['name', 'price', 'min_stock']);
        $this->loginAs('kasir');

        $this->postJson('/barang', ['code' => 'MKN010', 'name' => 'Donat', 'category' => 'Makanan', 'price' => 3000,
            'cost_price' => 2000, 'stock' => 1, 'min_stock' => 1, 'unit' => 'pcs', 'is_active' => true])->assertForbidden();
        $this->putJson("/barang/{$id}", ['name' => 'Diubah', 'price' => 1])->assertForbidden();
        $this->putJson('/barang/batas-minimum', ['items' => [['product_id' => $id, 'min_stock' => 99]]])->assertForbidden();

        $this->assertSame($before, Product::find($id)->only(['name', 'price', 'min_stock']));
        $this->assertDatabaseMissing('products', ['code' => 'MKN010']);

        // Melihat tetap boleh.
        $this->getJson("/barang/{$id}")->assertOk();
        $this->get('/barang')->assertOk()->assertDontSee('Tambah barang')->assertDontSee('Atur batas minimum');
    }

    public function test_kasir_cannot_modify_catalog_through_livewire(): void
    {
        $id = $this->pid('ATK001');
        $this->loginAs('kasir');

        Livewire::test(BarangIndex::class)->call('create')->assertForbidden();
        Livewire::test(BarangIndex::class)->call('edit', $id)->assertForbidden();
        Livewire::test(BarangIndex::class)->set('form.id', $id)->set('form.name', 'Diubah')->call('save')->assertForbidden();
        Livewire::test(BarangIndex::class)->call('show', $id)->assertOk()->assertSet('viewing', $id);
        Livewire::test(MinStockSettings::class)->call('show')->assertForbidden();
        Livewire::test(MinStockSettings::class)->set('minStocks', [$id => 99])->set('defaultMinStock', 1)->call('save')->assertForbidden();

        $this->assertNotSame('Diubah', Product::find($id)->name);
        $this->assertNotSame(99, Product::find($id)->min_stock);
    }

    public function test_manager_still_manages_catalog(): void
    {
        $id = $this->pid('ATK001');
        $this->loginAs('pengurus');

        $this->putJson('/barang/batas-minimum', ['items' => [['product_id' => $id, 'min_stock' => 9]]])->assertOk();
        $this->assertSame(9, Product::find($id)->min_stock);
        Livewire::test(BarangIndex::class)->call('edit', $id)->assertOk()->assertSet('editing', true);
        $this->get('/barang')->assertSee('Tambah barang');
    }

    public function test_kasir_cannot_delete_member_or_set_credit_limit(): void
    {
        $member = Member::factory()->create(['class' => '7A', 'credit_limit' => 50000]);
        $this->loginAs('kasir');

        $this->deleteJson("/anggota/{$member->id}")->assertForbidden();
        $this->assertNotSoftDeleted($member);

        $payload = ['member_code' => $member->member_code, 'name' => 'Nama Baru', 'type' => 'siswa', 'class' => '7A', 'is_active' => true, 'credit_limit' => 9000000];
        $this->putJson("/anggota/{$member->id}", $payload)->assertOk()->assertJsonPath('data.credit_limit', 50000);
        $this->assertSame('Nama Baru', $member->fresh()->name);

        $this->postJson('/anggota', [...$payload, 'member_code' => '', 'name' => 'Anggota Lain'])->assertCreated()
            ->assertJsonPath('data.credit_limit', null);

        Livewire::test(AnggotaIndex::class)->call('edit', $member->id)
            ->assertDontSee('Batas bon khusus')
            ->set('form.credit_limit', 1)->call('save')->assertHasNoErrors();
        $this->assertSame(50000, $member->fresh()->credit_limit);
    }

    public function test_manager_can_set_credit_limit_and_delete_member(): void
    {
        $member = Member::factory()->create(['class' => '7A']);
        $this->loginAs('pengurus');

        $this->putJson("/anggota/{$member->id}", ['member_code' => $member->member_code, 'name' => $member->name, 'type' => 'siswa',
            'class' => '7A', 'is_active' => true, 'credit_limit' => 25000])->assertOk()->assertJsonPath('data.credit_limit', 25000);
        $this->deleteJson("/anggota/{$member->id}")->assertOk();
        $this->assertSoftDeleted($member);
    }

    public function test_storage_route_disabled_and_csp_header_present(): void
    {
        $this->loginAs('admin');
        $this->get('/storage/private/anything.txt')->assertNotFound();
        $this->get('/barang')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    }

    public function test_mount_bound_ids_are_locked(): void
    {
        $this->loginAs('admin');
        $tx = \App\Models\Transaction::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(VoidForm::class, ['transaction' => $tx])->set('transactionId', $tx->id + 1);
    }
}
