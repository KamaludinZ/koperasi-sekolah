<?php

namespace Tests\Feature\Pengadaan;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    public function test_list_show_create_send_cancel(): void
    {
        $this->getJson('/pengadaan/pesanan/data?status=berjalan')->assertOk()->assertJsonCount(3, 'data');
        $partial = PurchaseOrder::where('status', 'sebagian')->first();
        $this->getJson("/pengadaan/pesanan/{$partial->id}/data")->assertOk()->assertJsonCount(5, 'data.lines')
            ->assertJsonCount(1, 'data.receipts')->assertJsonPath('data.late', true);

        $sinar = Supplier::where('name', 'CV Sinar Ilmu')->value('id');
        $id = $this->postJson('/pengadaan/pesanan', ['supplier_id' => $sinar, 'expected_at' => '2026-10-20',
            'items' => [['product_id' => $this->pid('ATK003'), 'qty' => 30, 'cost' => 2000], ['product_id' => $this->pid('ATK005'), 'qty' => 10, 'cost' => 2800]]])
            ->assertCreated()->assertJsonPath('data.status', 'draf')->assertJsonPath('data.number', 'PO-202610-0007')->assertJsonPath('data.total', 88_000)->json('data.id');

        $this->postJson('/pengadaan/pesanan', ['supplier_id' => $sinar, 'expected_at' => '2026-10-20',
            'items' => [['product_id' => $this->pid('ATK003'), 'qty' => 1, 'cost' => 1], ['product_id' => $this->pid('ATK003'), 'qty' => 1, 'cost' => 1]]])
            ->assertJsonValidationErrors('items.0.product_id');
        $this->postJson('/pengadaan/pesanan', ['supplier_id' => Supplier::where('is_active', false)->value('id'), 'expected_at' => '2026-10-20', 'items' => []])
            ->assertJsonValidationErrors(['supplier_id', 'items']);

        $this->postJson("/pengadaan/pesanan/{$id}/kirim")->assertOk()->assertJsonPath('data.status', 'dipesan');
        $this->postJson("/pengadaan/pesanan/{$id}/kirim")->assertJsonValidationErrors('status');
        $this->postJson("/pengadaan/pesanan/{$id}/batal")->assertJsonValidationErrors('alasan');
        $this->postJson("/pengadaan/pesanan/{$id}/batal", ['alasan' => 'Salah supplier'])->assertOk()->assertJsonPath('data.status', 'dibatalkan');
        $this->postJson("/pengadaan/pesanan/{$partial->id}/batal", ['alasan' => 'Coba'])->assertJsonValidationErrors('status');
    }

    public function test_permissions(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $this->getJson('/pengadaan/pesanan/data')->assertForbidden();
        $this->postJson('/pengadaan/pesanan', [])->assertForbidden();
    }
}
