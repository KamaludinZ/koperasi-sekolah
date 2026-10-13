<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Livewire\Transaksi\ReturnStatusNotice;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnStatusNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $me = User::where('email', 'kasir@koperasi.test')->first();
        $other = User::factory()->create(['name' => 'Kasir Lain']);
        foreach (range(13, 0) as $h) {
            Transaction::factory()->withItems()->at(now()->subHours($h * 3)->toDateTimeString())->create(['user_id' => $h % 2 ? $other->id : $me->id]);
        }
    }

    public function test_shows_decided_requests_of_current_user_until_seen(): void
    {
        $c = Livewire::test(ReturnStatusNotice::class);
        $mine = $c->instance()->mine;
        $this->assertNotEmpty($mine);
        $this->assertSame(['Bu Rina'], array_values(array_unique(array_column($mine, 'requested_by'))));

        $decided = array_filter($mine, fn ($r) => $r['status'] !== 'menunggu');
        $this->assertNotEmpty($decided);
        $first = array_values($decided)[0];
        $c->assertSee('pengajuan retur/pembatalan Anda sudah diputus')->assertSee($first['number']);
        $rejected = collect($decided)->firstWhere('status', 'ditolak');
        if ($rejected) {
            $c->assertSee('Alasan ditolak: '.$rejected['decision_note']);
        }

        $c->call('markSeen')->assertDontSee('sudah diputus');
        $this->assertNotNull(session(ReturnStatusNotice::SEEN_KEY));
        Livewire::test(ReturnStatusNotice::class)->assertDontSee('sudah diputus');
    }

    public function test_pending_count_when_nothing_new(): void
    {
        session([ReturnStatusNotice::SEEN_KEY => now()->addDay()->toDateTimeString()]);
        $c = Livewire::test(ReturnStatusNotice::class);
        $pending = count(array_filter($c->instance()->mine, fn ($r) => $r['status'] === 'menunggu'));
        $pending
            ? $c->assertSee("{$pending} pengajuan retur/pembatalan Anda masih menunggu persetujuan.")
            : $c->assertDontSee('masih menunggu');
        Livewire::test(ReturnStatusNotice::class, ['compact' => true])->assertDontSee('masih menunggu');
    }

    public function test_kasir_page_and_returns_page_embed_notice(): void
    {
        $this->get('/kasir')->assertOk()->assertSee('sudah diputus');
        $this->get('/transaksi/retur')->assertOk()->assertSee('sudah diputus')->assertSee('Pengajuan saya');
    }

    public function test_mine_filter_on_returns_page(): void
    {
        $c = Livewire::test(Returns::class)->set('mine', true);
        $this->assertNotEmpty($c->instance()->rows);
        $this->assertSame(['Bu Rina'], array_values(array_unique(array_column($c->instance()->rows, 'requested_by'))));
        $this->assertSame(count(array_filter($c->instance()->all, fn ($r) => $r['requested_by'] === 'Bu Rina')), $c->instance()->counts['semua']);
    }
}
