<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\ReturnHistory;
use App\Models\Transaction;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        foreach (range(13, 0) as $h) {
            Transaction::factory()->withItems()->at(now()->subHours($h * 5)->toDateTimeString())->create();
        }
    }

    public function test_page_in_transaction_nav(): void
    {
        $this->get('/transaksi/retur')->assertOk()->assertSee('Riwayat retur')->assertSee(route('transaksi.retur.riwayat'));
        $this->get('/transaksi/retur/riwayat')->assertOk()->assertSee('Dana dikembalikan')->assertSee('Alasan terbanyak');
    }

    public function test_only_decided_requests_with_summary(): void
    {
        $c = Livewire::test(ReturnHistory::class);
        $decided = array_filter(MockData::transactionReturns(), fn ($r) => $r['status'] !== 'menunggu');
        $rows = $c->instance()->rows;
        $this->assertCount(count($decided), $rows);
        $this->assertNotContains('menunggu', array_column($rows, 'status'));

        $ok = array_filter($rows, fn ($r) => $r['status'] === 'disetujui');
        $sum = $c->instance()->summary;
        $this->assertSame(array_sum(array_column($ok, 'amount')), $sum['refunded']);
        $this->assertSame(count($ok), $sum['approved']);
        $this->assertSame(array_sum(array_column($ok, 'amount')), array_sum(array_column($sum['reasons'], 'amount')));
        $this->assertSame(count($rows), array_sum(array_map('count', $c->instance()->days)));

        $first = $rows[0];
        $c->assertSee($first['number'])->assertSee($first['transaction']['invoice']);
    }

    public function test_filters(): void
    {
        $c = Livewire::test(ReturnHistory::class)->set('outcome', 'ditolak');
        $this->assertSame(['ditolak'], array_values(array_unique(array_column($c->instance()->rows, 'status'))));
        $this->assertSame(0, $c->instance()->summary['refunded']);

        $c->set('outcome', '')->set('kind', 'retur');
        $this->assertSame(['retur'], array_values(array_unique(array_column($c->instance()->rows, 'kind'))));

        $c->set('kind', '')->set('from', now()->addDay()->toDateString());
        $this->assertSame('kustom', $c->get('preset'));
        $this->assertSame([], $c->instance()->rows);
        $c->assertSee('Belum ada retur atau pembatalan yang diputus pada periode ini.');

        $c->set('to', now()->subDays(3)->toDateString())->assertHasErrors('to');
        $c->call('applyPreset', '7-hari')->assertSet('from', '')->assertHasNoErrors();
        $this->assertNotEmpty($c->instance()->rows);
    }
}
