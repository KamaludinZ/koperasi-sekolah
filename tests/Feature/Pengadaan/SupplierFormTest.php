<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\SupplierForm;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_pages_and_links(): void
    {
        $this->get('/pengadaan/supplier/baru')->assertOk()->assertSee('Supplier baru')->assertSee('Alat Tulis');
        $this->get('/pengadaan/supplier/2/ubah')->assertOk()->assertSee('Ubah supplier');
        $this->get('/pengadaan/supplier/99/ubah')->assertNotFound();
        $this->get('/pengadaan/supplier')->assertSee(route('pengadaan.supplier.buat'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/supplier/baru')->assertForbidden();
    }

    public function test_create_with_validation(): void
    {
        Livewire::test(SupplierForm::class)
            ->call('save')->assertHasErrors(['name' => 'required', 'phone' => 'required'])->assertDispatched('toast', type: 'error')
            ->set('name', 'cv sinar ilmu')->set('phone', 'abc')->call('save')->assertHasErrors(['name', 'phone'])
            ->set('name', '  Toko  Buku Cahaya ')->set('phone', '0812-1111-2222')->set('termsChoice', 'lain')->call('save')->assertHasErrors('customDays')
            ->set('customDays', 21)->set('categories', ['Alat Tulis', 'Seragam'])->call('save')
            ->assertHasNoErrors()->assertRedirect(route('pengadaan.supplier'));

        $s = collect(MockData::suppliers())->firstWhere('name', 'Toko Buku Cahaya');
        $this->assertSame([21, ['Alat Tulis', 'Seragam'], true, 0], [$s['terms_days'], $s['categories'], $s['is_active'], $s['purchases_90d']]);
        $this->assertSame('Supplier Toko Buku Cahaya ditambahkan.', session('toast')['message']);
    }

    public function test_edit_keeps_history_fields(): void
    {
        $c = Livewire::test(SupplierForm::class, ['supplier' => 1])
            ->assertSet('name', 'CV Sinar Ilmu')->assertSet('termsChoice', '30')->assertSet('categories', ['Alat Tulis']);
        $c->set('termsChoice', '14')->set('is_active', false)->call('save')->assertHasNoErrors();

        $s = collect(MockData::suppliers())->firstWhere('id', 1);
        $this->assertSame([14, false, 860_000], [$s['terms_days'], $s['is_active'], $s['open_payable']]);
        Livewire::test(SupplierForm::class, ['supplier' => 1])->set('name', 'CV Sinar Ilmu')->call('save')->assertHasNoErrors();
    }
}
