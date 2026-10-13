<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Models\Transaction;
use App\Models\User;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $cashier = User::factory()->create(['name' => 'Bu Rina']);
        foreach (range(13, 0) as $h) {
            Transaction::factory()->withItems()->at(now()->subHours($h * 3)->toDateTimeString())->create(['user_id' => $cashier->id]);
        }
    }

    public function test_page_linked_from_history_nav(): void
    {
        $this->get('/transaksi')->assertOk()->assertSee('Retur & pembatalan')->assertSee(route('transaksi.retur'));
        $this->get('/transaksi/retur')->assertOk()->assertSee('Menunggu persetujuan')->assertSee('Ajukan retur / pembatalan')->assertSee('Data contoh');
    }

    public function test_mock_returns_reference_real_transactions(): void
    {
        $rows = MockData::transactionReturns();
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $tx = Transaction::find($r['transaction']['id']);
            $this->assertNotNull($tx);
            $this->assertSame($tx->invoice_number, $r['transaction']['invoice']);
            $this->assertContains($r['kind'], ['retur', 'batal']);
            $this->assertSame($r['kind'] === 'batal' ? (int) $tx->total : $r['items'][0]['amount'], $r['amount']);
        }
        $this->assertSame([], array_diff(['menunggu', 'disetujui', 'ditolak'], array_column($rows, 'status')));
    }

    public function test_filters_counts_and_detail(): void
    {
        $c = Livewire::test(Returns::class);
        $all = $c->instance()->all;
        $this->assertSame(count($all), $c->instance()->counts['semua']);

        $c->call('setStatus', 'menunggu');
        $pending = $c->instance()->rows;
        $this->assertNotEmpty($pending);
        $this->assertSame(['menunggu'], array_values(array_unique(array_column($pending, 'status'))));
        $this->assertSame(count($pending), $c->instance()->stats['pending']);

        $c->call('setStatus', 'semua')->set('kind', 'batal');
        $this->assertSame(['batal'], array_values(array_unique(array_column($c->instance()->rows, 'kind'))));
        $this->assertSame(count($c->instance()->rows), $c->instance()->counts['semua']);

        $first = $all[0];
        $c->set('kind', '')->set('search', $first['transaction']['invoice']);
        $this->assertContains($first['id'], array_column($c->instance()->rows, 'id'));
        $c->set('search', 'tidak-ada-apa')->assertSee('Tidak ada pengajuan yang cocok.');

        $c->call('show', $first['id'])->assertSet('selectedId', $first['id'])->assertSee($first['number'])->assertSee($first['reason'])->assertSee('Pengembalian dana');
        $c->call('closeDetail')->assertSet('selectedId', null)->call('show', 999999)->assertSet('selectedId', null);
        $c->call('setStatus', 'aneh')->assertSet('status', 'semua');
    }

    public function test_lookup_finds_transaction_and_flags_expired(): void
    {
        $recent = Transaction::latest('id')->first();
        $old = Transaction::factory()->withItems()->at(now()->subDays(5)->toDateTimeString())->create();

        $c = Livewire::test(Returns::class)->set('lookup', 'xx');
        $this->assertSame([], $c->instance()->lookupResults);

        $c->set('lookup', mb_strtolower($recent->invoice_number));
        $hit = $c->instance()->lookupResults;
        $this->assertSame([$recent->id], array_column($hit, 'id'));
        $this->assertFalse($hit[0]['expired']);
        $c->assertSee('Retur barang')->assertSee('Batalkan');

        $c->set('lookup', $old->invoice_number);
        $this->assertTrue($c->instance()->lookupResults[0]['expired']);
        $c->assertSee('Lewat batas 3 hari');

        $c->set('lookup', 'INV-TIDAK-ADA')->assertSee('Transaksi tidak ditemukan.');
    }

    public function test_empty_state_without_transactions(): void
    {
        Transaction::query()->each(fn ($t) => $t->items()->delete() || true);
        Transaction::query()->delete();
        Livewire::test(Returns::class)->assertSee('Belum ada retur atau pembatalan.');
    }
}
