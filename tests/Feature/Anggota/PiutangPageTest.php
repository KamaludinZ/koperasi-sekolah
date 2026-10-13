<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Piutang;
use App\Models\Member;
use App\Models\MemberCredit;
use App\Services\MemberCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PiutangPageTest extends TestCase
{
    use RefreshDatabase;

    private Member $late;

    private Member $soon;

    private Member $later;

    protected function setUp(): void
    {
        parent::setUp();
        $this->late = Member::factory()->create(['name' => 'Budi Telat', 'type' => 'siswa', 'member_code' => 'S-1']);
        $this->soon = Member::factory()->create(['name' => 'Citra Segera', 'type' => 'guru', 'member_code' => 'G-1']);
        $this->later = Member::factory()->create(['name' => 'Dodi Santai', 'type' => 'siswa', 'member_code' => 'S-2']);
        Member::factory()->create(['name' => 'Eka Lunas']);

        $this->credit($this->late, 5000, -2);
        $this->credit($this->late, 3000, 9);
        $this->credit($this->soon, 20000, 4);
        $this->credit($this->later, 7000, 20);
        $paid = $this->credit($this->later, 4000, 25);
        app(MemberCreditService::class)->repay($this->later->id, 7000, 'tunai', 'Dibayar ayah'); // lunasi bon 7.000 (jatuh tempo terdekat)
        $this->assertSame('belum', $paid->fresh()->status);
    }

    private function credit(Member $m, int $amount, int $dueInDays): MemberCredit
    {
        return MemberCredit::create(['number' => 'BON-T-'.(MemberCredit::count() + 1), 'member_id' => $m->id, 'amount' => $amount, 'due_at' => today()->addDays($dueInDays)]);
    }

    public function test_page_reachable_from_member_nav(): void
    {
        $this->get('/anggota')->assertOk()->assertSee('Piutang (bon)')->assertSee(route('anggota.piutang'));
        $this->get('/anggota/piutang')->assertOk()->assertSee('Total piutang')->assertSee('Daftar piutang')->assertSee('Rp32.000');
    }

    public function test_debtor_list_sorted_and_filtered(): void
    {
        $c = Livewire::test(Piutang::class);
        $rows = $c->instance()->debtors;
        $this->assertSame(['Budi Telat', 'Citra Segera', 'Dodi Santai'], array_column($rows, 'name'));
        $this->assertSame(['lewat', 'minggu', 'berjalan'], array_column($rows, 'status'));
        $this->assertSame([8000, 20000, 4000], array_column($rows, 'outstanding'));
        $this->assertSame([2, 1, 1], array_column($rows, 'bills'));
        $this->assertSame([50000, 300000, 50000], array_column($rows, 'limit'));
        $this->assertSame(['total' => 32000, 'members' => 3, 'late_total' => 8000, 'late_members' => 1, 'week_members' => 1], $c->instance()->stats);

        $c->set('status', 'lewat');
        $this->assertSame(['Budi Telat'], array_column($c->instance()->list, 'name'));
        $c->set('status', '')->set('type', 'guru');
        $this->assertSame(['Citra Segera'], array_column($c->instance()->list, 'name'));
        $c->set('type', '')->set('search', 'S-2');
        $this->assertSame(['Dodi Santai'], array_column($c->instance()->list, 'name'));
        $c->set('search', 'zzz')->assertSee('Tidak ada piutang yang cocok.');
    }

    public function test_status_of(): void
    {
        $this->assertSame('lewat', Piutang::statusOf([['due_at' => now()->subDay()->toDateString()]]));
        $this->assertSame('minggu', Piutang::statusOf([['due_at' => now()->addDays(5)->toDateString()]]));
        $this->assertSame('berjalan', Piutang::statusOf([['due_at' => now()->addDays(9)->toDateString()]]));
    }

    public function test_history_tab_filters_kind_and_period(): void
    {
        $c = Livewire::test(Piutang::class)->call('setTab', 'riwayat')->assertSet('tab', 'riwayat')->assertSee('Bon baru');
        $h = $c->instance()->history;
        $this->assertCount(6, $h['rows']); // 5 bon + 1 pelunasan
        $this->assertSame([36000 + 3000, 7000], [$h['bon'], $h['paid']]);
        $this->assertSame('pelunasan', $h['rows'][0]['kind']); // terbaru

        $c->set('kind', 'pelunasan')->assertSee('Pelunasan (tunai)')->assertSee('Dibayar ayah');
        $this->assertSame(['pelunasan'], array_values(array_unique(array_column($c->instance()->history['rows'], 'kind'))));

        $c->set('kind', '')->set('search', 'Budi');
        $this->assertSame(['Budi Telat'], array_values(array_unique(array_column(array_column($c->instance()->history['rows'], 'member'), 'name'))));

        MemberCredit::orderBy('id')->first()->forceFill(['created_at' => now()->subDays(40)])->save();
        $c->set('search', '')->set('period', '30');
        $this->assertCount(5, $c->instance()->history['rows']);
        $c->call('setTab', 'bogus')->assertSet('tab', 'daftar');
    }

    public function test_more_button_extends_limit(): void
    {
        foreach (Member::factory()->count(30)->create() as $m) {
            $this->credit($m, 1000, 30);
        }
        $c = Livewire::test(Piutang::class);
        $this->assertGreaterThan(Piutang::PER_PAGE, count($c->instance()->list));
        $c->assertSee('Tampilkan lebih banyak')->call('more')->assertSet('limit', Piutang::PER_PAGE * 2)->set('type', 'guru')->assertSet('limit', Piutang::PER_PAGE);
    }

    public function test_endpoints(): void
    {
        $this->getJson(route('anggota.piutang.list'))->assertOk()
            ->assertJsonPath('stats.total', 32000)->assertJsonCount(3, 'data')->assertJsonPath('data.0.status', 'lewat');
        $this->getJson(route('anggota.piutang.list', ['status' => 'minggu']))->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Citra Segera');
        $this->getJson(route('anggota.piutang.list', ['status' => 'aneh']))->assertStatus(422);

        $this->getJson(route('anggota.piutang.history', ['catatan' => 'pelunasan']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.method', 'tunai')->assertJsonPath('paid', 7000);
        $this->getJson(route('anggota.piutang.history', ['dari' => today()->toDateString(), 'sampai' => today()->subDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('sampai');
        $this->getJson(route('anggota.piutang.history', ['dari' => today()->addDay()->toDateString()]))->assertJsonCount(0, 'data');
    }

    public function test_inactive_member_debt_still_listed(): void
    {
        $this->soon->update(['is_active' => false]);
        $this->assertContains('Citra Segera', array_column(app(MemberCreditService::class)->debtors(), 'name'));
    }
}
