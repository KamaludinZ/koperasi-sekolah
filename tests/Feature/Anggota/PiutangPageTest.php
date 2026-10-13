<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Piutang;
use App\Models\Member;
use App\Support\MockData;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PiutangPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MemberSeeder::class);
    }

    public function test_page_reachable_from_member_nav(): void
    {
        $this->get('/anggota')->assertOk()->assertSee('Piutang (bon)')->assertSee(route('anggota.piutang'));
        $this->get('/anggota/piutang')->assertOk()->assertSee('Total piutang')->assertSee('Daftar piutang')->assertSee('Data contoh');
    }

    public function test_debtor_list_matches_mock_credit_and_sorts_overdue_first(): void
    {
        $c = Livewire::test(Piutang::class);
        $rows = $c->instance()->debtors;
        $expected = Member::active()->get()->filter(fn ($m) => MockData::memberCredit($m->id, $m->type)['outstanding'] > 0);

        $this->assertCount($expected->count(), $rows);
        $this->assertSame($expected->sum(fn ($m) => MockData::memberCredit($m->id, $m->type)['outstanding']), $c->instance()->stats['total']);
        $ranks = array_map(fn ($r) => ['lewat' => 0, 'minggu' => 1, 'berjalan' => 2][$r['status']], $rows);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);

        $late = array_values(array_filter($rows, fn ($r) => $r['status'] === 'lewat'));
        $this->assertNotEmpty($late);
        $c->assertSee($late[0]['name'])->set('status', 'lewat');
        $this->assertSame(array_column($late, 'id'), array_column($c->instance()->list, 'id'));
        $this->assertSame(count($late), $c->instance()->stats['late_members']);

        $c->set('status', '')->set('search', $rows[0]['member_code']);
        $this->assertSame([$rows[0]['id']], array_column($c->instance()->list, 'id'));
        $c->set('search', 'zzz-tidak-ada')->assertSee('Tidak ada piutang yang cocok.');
    }

    public function test_status_of(): void
    {
        $this->assertSame('lewat', Piutang::statusOf([['due_at' => now()->subDay()->toDateString(), 'overdue' => true]]));
        $this->assertSame('minggu', Piutang::statusOf([['due_at' => now()->addDays(5)->toDateString(), 'overdue' => false]]));
        $this->assertSame('berjalan', Piutang::statusOf([['due_at' => now()->addDays(9)->toDateString(), 'overdue' => false]]));
    }

    public function test_history_tab_filters_kind_and_period(): void
    {
        $c = Livewire::test(Piutang::class)->call('setTab', 'riwayat')->assertSet('tab', 'riwayat')->assertSee('Bon baru');
        $rows = $c->instance()->history['rows'];
        $this->assertNotEmpty($rows);
        $dates = array_column($rows, 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);

        $c->set('kind', 'pelunasan');
        $paid = $c->instance()->history;
        $this->assertNotEmpty($paid['rows']);
        $this->assertSame(['pelunasan'], array_values(array_unique(array_column($paid['rows'], 'kind'))));
        $this->assertSame(0, $paid['bon']);
        $c->assertSee('Pelunasan (');

        $c->set('kind', '')->set('period', '30');
        $this->assertTrue(collect($c->instance()->history['rows'])->every(fn ($e) => $e['date'] >= now()->subDays(30)->toDateString()));
        $c->call('setTab', 'bogus')->assertSet('tab', 'daftar');
    }

    public function test_more_button_extends_limit(): void
    {
        Member::factory()->count(60)->create();
        $c = Livewire::test(Piutang::class);
        $total = count($c->instance()->list);
        $this->assertGreaterThan(Piutang::PER_PAGE, $total);
        $c->assertSee('Tampilkan lebih banyak')->call('more')->assertSet('limit', Piutang::PER_PAGE * 2)->set('type', 'guru')->assertSet('limit', Piutang::PER_PAGE);
    }
}
