<?php

namespace Tests\Feature\Transaksi;

use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionListEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-12 10:00:00');
    }

    public function test_paginates_latest_first_with_meta(): void
    {
        foreach (range(1, 25) as $i) {
            Transaction::factory()->withItems()->at(now()->subHours($i)->toDateTimeString())->create();
        }
        $latest = Transaction::factory()->withItems([$this->pid('MNM001') => 2])->at(now()->toDateTimeString())
            ->create(['user_id' => User::factory()->create(['name' => 'Bu Rina'])->id]);

        $this->getJson('/transaksi/daftar')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.0.cashier', 'Bu Rina')
            ->assertJsonPath('data.0.item_count', 2)
            ->assertJsonPath('data.0.payment_method_label', 'Tunai')
            ->assertJsonMissingPath('data.0.items');

        $this->getJson('/transaksi/daftar?page=2')->assertJsonCount(6, 'data');
        $this->getJson('/transaksi/daftar?page=9')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/transaksi/daftar?per_halaman=50')->assertJsonCount(26, 'data');
    }

    public function test_filters_by_date_method_and_invoice(): void
    {
        $today = Transaction::factory()->withItems()->at('2026-10-12 08:00:00')->create(['invoice_number' => 'INV-20261012-0001']);
        Transaction::factory()->withItems()->qris()->at('2026-10-11 23:59:59')->create(['invoice_number' => 'INV-20261011-0001']);
        Transaction::factory()->withItems()->at('2026-10-01 07:00:00')->create(['invoice_number' => 'INV-20261001-0001']);

        $this->getJson('/transaksi/daftar?dari=2026-10-12&sampai=2026-10-12')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $today->id);
        $this->getJson('/transaksi/daftar?dari=2026-10-11')->assertJsonPath('meta.total', 2);
        $this->getJson('/transaksi/daftar?sampai=2026-10-11')->assertJsonPath('meta.total', 2);
        $this->getJson('/transaksi/daftar?metode=qris')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.invoice_number', 'INV-20261011-0001');
        $this->getJson('/transaksi/daftar?q=1001-0001')->assertJsonPath('data.0.invoice_number', 'INV-20261001-0001');
        $this->getJson('/transaksi/daftar?q=%25')->assertJsonPath('meta.total', 0);
    }

    public function test_validates_query(): void
    {
        $this->getJson('/transaksi/daftar?dari=12-10-2026')->assertUnprocessable();
        $this->getJson('/transaksi/daftar?dari=2026-10-12&sampai=2026-10-01')->assertJsonValidationErrors('sampai');
        $this->getJson('/transaksi/daftar?metode=kartu')->assertUnprocessable();
        $this->getJson('/transaksi/daftar?per_halaman=7')->assertUnprocessable();
    }
}
