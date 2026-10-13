<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\ReturnHistory;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnHistoryTest extends TestCase
{
    use RefreshDatabase;

    private TransactionReturn $approved;

    private TransactionReturn $void;

    private TransactionReturn $rejected;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $tx = fn () => Transaction::factory()->withItems([$this->pid('ATK001') => 2])->create();
        $this->approved = TransactionReturn::factory()->approved()->create(['transaction_id' => $tx()->id, 'restock' => true, 'decided_at' => now()->subHour()]);
        $this->void = TransactionReturn::factory()->batal()->approved()->create(['transaction_id' => $tx()->id, 'decided_at' => now()->subDays(10)]);
        $this->rejected = TransactionReturn::factory()->rejected()->create(['transaction_id' => $tx()->id]);
        TransactionReturn::factory()->create(['transaction_id' => $tx()->id]); // menunggu: tidak masuk riwayat
    }

    public function test_page_in_transaction_nav(): void
    {
        $this->get('/transaksi/retur')->assertOk()->assertSee('Riwayat retur')->assertSee(route('transaksi.retur.riwayat'));
        $this->get('/transaksi/retur/riwayat')->assertOk()->assertSee('Dana dikembalikan')->assertSee('Alasan terbanyak')->assertSee('Unduh CSV')
            ->assertSee($this->approved->number)->assertSee($this->void->number);
    }

    public function test_only_decided_requests_with_summary(): void
    {
        $c = Livewire::test(ReturnHistory::class);
        $rows = $c->instance()->rows;
        $this->assertSame([$this->rejected->id, $this->approved->id, $this->void->id], array_column($rows, 'id'));
        $sum = $c->instance()->summary;
        $this->assertSame([2, 1, $this->approved->amount + $this->void->amount], [$sum['approved'], $sum['rejected'], $sum['refunded']]);
        $this->assertSame([$this->approved->quantity + $this->void->quantity, 0], [$sum['restocked'], $sum['written_off']]);
        $this->assertSame($sum['refunded'], array_sum(array_column($sum['reasons'], 'amount')));
        $this->assertSame(3, array_sum(array_map('count', $c->instance()->days)));
    }

    public function test_filters(): void
    {
        $c = Livewire::test(ReturnHistory::class)->set('outcome', 'ditolak');
        $this->assertSame([$this->rejected->id], array_column($c->instance()->rows, 'id'));
        $this->assertSame(0, $c->instance()->summary['refunded']);
        $c->set('outcome', '')->set('kind', 'batal');
        $this->assertSame([$this->void->id], array_column($c->instance()->rows, 'id'));
        $c->set('kind', '')->call('applyPreset', '7-hari');
        $this->assertNotContains($this->void->id, array_column($c->instance()->rows, 'id'));
        $c->set('from', now()->addDay()->toDateString())->assertSet('preset', 'kustom')->assertSee('Belum ada retur atau pembatalan yang diputus pada periode ini.');
        $c->set('to', now()->subDays(3)->toDateString())->assertHasErrors('to');
        $this->assertStringContainsString('dari=', $c->instance()->exportUrl);
    }

    public function test_history_endpoint_and_csv_export(): void
    {
        $this->getJson(route('transaksi.retur.history', ['hasil' => 'disetujui']))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('summary.approved', 2)->assertJsonPath('data.0.id', $this->approved->id);
        $this->getJson(route('transaksi.retur.history', ['dari' => today()->toDateString(), 'sampai' => today()->subDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('sampai');

        $res = $this->get(route('transaksi.retur.export', ['jenis' => 'batal']))->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('riwayat-retur-', $res->headers->get('Content-Disposition'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('"Tanggal keputusan";Nomor;Jenis;Status', $lines[0]);
        $this->assertStringContainsString($this->void->number, $lines[1]);
        $this->assertStringContainsString('Pembatalan', $lines[1]);
        $this->assertTrue(ActivityLog::where('event', 'report.exported')->where('description', 'like', '%retur%')->exists());
    }

    public function test_cashier_without_transaction_menu_cannot_export(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $res = $this->get(route('transaksi.retur.export'));
        auth()->user()->canOpen('transaksi') ? $res->assertOk() : $res->assertForbidden();
    }
}
