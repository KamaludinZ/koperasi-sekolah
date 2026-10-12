<?php

namespace Tests\Feature\Anggota;

use App\Models\Member;
use App\Models\User;
use App\Services\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingsHistoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = Member::factory()->create(['name' => 'Siti']);
        $staff = User::factory()->create(['name' => 'Bu Rina'])->id;
        $svc = app(SavingsService::class);

        foreach ([
            ['2026-01-10 08:00:00', 'setor', 50000],
            ['2026-08-01 08:00:00', 'tarik', 20000],
            ['2026-09-20 08:00:00', 'setor', 10000],
            ['2026-10-10 08:00:00', 'tarik', 5000],
        ] as [$at, $type, $amount]) {
            $this->travelTo($at);
            $svc->record($this->member, $type, $amount, null, $staff);
        }
        $this->travelTo('2026-10-12 09:00:00');
    }

    private function url(string $query = ''): string
    {
        return "/anggota/{$this->member->id}/tabungan".($query ? "?{$query}" : '');
    }

    public function test_balance_stats_and_history_newest_first(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('member.name', 'Siti')
            ->assertJsonPath('stats', [
                'balance' => 35000, 'deposit_total' => 60000, 'deposit_count' => 2,
                'withdraw_total' => 25000, 'withdraw_count' => 2,
                'last_at' => '2026-10-10 08:00:00', 'last_type' => 'tarik',
            ])
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.balance_after', 35000)
            ->assertJsonPath('data.0.user', 'Bu Rina')
            ->assertJsonPath('data.3.balance_after', 50000)
            ->assertJsonPath('meta.in', 60000)
            ->assertJsonPath('meta.out', 25000);
    }

    public function test_filters_by_preset_custom_range_and_type(): void
    {
        $this->getJson($this->url('periode=30-hari'))->assertJsonPath('meta.total', 2)->assertJsonPath('meta.from', '2026-09-13');
        $this->getJson($this->url('periode=tahun-ini'))->assertJsonPath('meta.total', 4);
        $this->getJson($this->url('dari=2026-08-01&sampai=2026-09-30'))
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.in', 10000)
            ->assertJsonPath('meta.out', 20000);
        $this->getJson($this->url('jenis=tarik'))
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.in', 0)
            ->assertJsonPath('stats.deposit_count', 2); // ringkasan tetap seluruh riwayat
    }

    public function test_empty_member_and_validation(): void
    {
        $empty = Member::factory()->create();
        $this->getJson("/anggota/{$empty->id}/tabungan")
            ->assertJsonPath('stats.balance', 0)
            ->assertJsonPath('stats.last_at', null)
            ->assertJsonCount(0, 'data');

        $this->getJson($this->url('periode=minggu'))->assertUnprocessable();
        $this->getJson($this->url('dari=2026-10-10&sampai=2026-10-01'))->assertJsonValidationErrors('sampai');
        $this->getJson('/anggota/9999/tabungan')->assertNotFound();
    }
}
