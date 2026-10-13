<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->cashier = User::where('email', 'kasir@koperasi.test')->first();
        $tx = fn () => Transaction::factory()->withItems()->create(['user_id' => $this->cashier->id]);
        TransactionReturn::factory()->create(['transaction_id' => $tx()->id, 'requested_by' => $this->cashier->id, 'requested_at' => now()->subHours(3)]);
        TransactionReturn::factory()->batal()->create(['transaction_id' => $tx()->id, 'requested_by' => $this->cashier->id, 'requested_at' => now()->subHour()]);
        TransactionReturn::factory()->approved()->create(['transaction_id' => $tx()->id, 'requested_by' => $this->cashier->id]);
        TransactionReturn::factory()->rejected()->create(['transaction_id' => $tx()->id]);
    }

    public function test_page_linked_from_history_nav(): void
    {
        $this->get('/transaksi')->assertOk()->assertSee('Retur & pembatalan')->assertSee(route('transaksi.retur'));
        $this->get('/transaksi/retur')->assertOk()->assertSee('Menunggu persetujuan')->assertSee('Ajukan retur / pembatalan')->assertDontSee('Data contoh');
    }

    public function test_filters_counts_and_detail(): void
    {
        $c = Livewire::test(Returns::class);
        $this->assertSame(['semua' => 4, 'menunggu' => 2, 'disetujui' => 1, 'ditolak' => 1], $c->instance()->counts);
        $this->assertSame(2, $c->instance()->stats['pending']);

        $c->call('setStatus', 'menunggu');
        $this->assertSame(['menunggu'], array_values(array_unique(array_column($c->instance()->rows, 'status'))));
        $c->call('setStatus', 'semua')->set('kind', 'batal');
        $this->assertSame(['batal'], array_values(array_unique(array_column($c->instance()->rows, 'kind'))));
        $this->assertSame(1, $c->instance()->counts['semua']);

        $first = TransactionReturn::with('transaction')->first();
        $c->set('kind', '')->set('search', mb_strtolower($first->transaction->invoice_number));
        $this->assertSame([$first->id], array_column($c->instance()->rows, 'id'));
        $c->set('search', 'tidak-ada-apa')->assertSee('Tidak ada pengajuan yang cocok.');

        $c->call('show', $first->id)->assertSet('selectedId', $first->id)->assertSee($first->number)->assertSee($first->reason)->assertSee('Pengembalian dana');
        $c->call('closeDetail')->assertSet('selectedId', null)->call('show', 999999)->assertSet('selectedId', null);
        $c->call('setStatus', 'aneh')->assertSet('status', 'semua');
    }

    public function test_lookup_finds_transaction_and_flags_expired(): void
    {
        $recent = Transaction::factory()->withItems()->create();
        $old = Transaction::factory()->withItems()->at(now()->subDays(5)->toDateTimeString())->create();

        $c = Livewire::test(Returns::class)->set('lookup', 'xx');
        $this->assertSame([], $c->instance()->lookupResults);
        $c->set('lookup', mb_strtolower($recent->invoice_number));
        $this->assertSame([$recent->id], array_column($c->instance()->lookupResults, 'id'));
        $this->assertFalse($c->instance()->lookupResults[0]['expired']);
        $c->assertSee('Retur barang')->assertSee('Batalkan');
        $c->set('lookup', $old->invoice_number);
        $this->assertTrue($c->instance()->lookupResults[0]['expired']);
        $c->assertSee('Lewat batas 3 hari');
        $c->set('lookup', 'INV-TIDAK-ADA')->assertSee('Transaksi tidak ditemukan.');
    }

    public function test_empty_state(): void
    {
        TransactionReturn::query()->delete();
        Livewire::test(Returns::class)->assertSee('Belum ada retur atau pembatalan.');
    }

    public function test_list_and_queue_endpoints(): void
    {
        $this->getJson(route('transaksi.retur.list', ['status' => 'menunggu']))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('counts.semua', 4)->assertJsonPath('stats.pending', 2);
        $this->getJson(route('transaksi.retur.list', ['jenis' => 'batal']))->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'batal');
        $this->getJson(route('transaksi.retur.list', ['status' => 'aneh']))->assertStatus(422);
        $queue = $this->getJson(route('transaksi.retur.queue'))->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame('retur', $queue[0]['kind']); // terlama dulu

        $this->loginAs('kasir@koperasi.test');
        $this->getJson(route('transaksi.retur.queue'))->assertForbidden();
        $this->getJson(route('transaksi.retur.list', ['saya' => 1]))->assertOk()->assertJsonCount(3, 'data');
    }
}
