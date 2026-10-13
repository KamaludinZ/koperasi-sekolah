<?php

namespace Tests\Feature\Pengadaan;

use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    public function test_list_show_create_update_delete(): void
    {
        $this->getJson('/pengadaan/supplier/data?status=aktif&urut=pembelian')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'CV Sinar Ilmu');
        $berkah = Supplier::where('name', 'UD Berkah Pangan')->value('id');
        $this->getJson("/pengadaan/supplier/{$berkah}/data")->assertOk()->assertJsonPath('data.terms_label', 'Tempo 7 hari')
            ->assertJsonCount(2, 'data.orders')->assertJsonCount(1, 'data.payables');

        $id = $this->postJson('/pengadaan/supplier', ['name' => 'Toko Buku Cahaya', 'phone' => '0812 1111 2222', 'terms_days' => 0, 'categories' => ['Alat Tulis']])
            ->assertCreated()->assertJsonPath('data.terms_label', 'Tunai')->json('data.id');
        $this->postJson('/pengadaan/supplier', ['name' => 'toko buku cahaya', 'phone' => '1', 'terms_days' => 200, 'categories' => ['Hilang']])
            ->assertJsonValidationErrors(['name', 'phone', 'terms_days', 'categories.0']);
        $this->putJson("/pengadaan/supplier/{$id}", ['name' => 'Toko Buku Cahaya', 'phone' => '0812 1111 2222', 'terms_days' => 30, 'email' => 'cahaya@contoh.id'])
            ->assertOk()->assertJsonPath('data.terms_days', 30)->assertJsonPath('data.categories', ['Alat Tulis']);

        $this->deleteJson("/pengadaan/supplier/{$berkah}")->assertJsonValidationErrors('supplier');
        $this->deleteJson("/pengadaan/supplier/{$id}", ['alasan' => 'Salah input'])->assertOk();
        $this->assertSame('Salah input', Supplier::withTrashed()->find($id)->delete_reason);
    }

    public function test_permissions(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $this->getJson('/pengadaan/supplier/data')->assertForbidden();
        $this->postJson('/pengadaan/supplier', ['name' => 'X'])->assertForbidden();
        $this->loginAs('pengurus@koperasi.test');
        $this->postJson('/pengadaan/supplier', ['name' => 'Toko Baru', 'phone' => '0812 1111 3333', 'terms_days' => 7])->assertCreated();
    }
}
