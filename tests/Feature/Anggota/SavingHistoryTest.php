<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Models\Member;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SavingHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MemberSeeder::class);
    }

    public function test_history_is_newest_first_with_running_balance(): void
    {
        $c = Livewire::test(Show::class, ['member' => $this->mid('G-2024-002')])->assertSee('Riwayat tabungan');
        $rows = $c->instance()->history['rows'];

        $this->assertCount(Member::findOrFail($this->mid('G-2024-002'))->savings()->count(), $rows);
        $this->assertSame(1250000, $rows[0]['balance_after']);
        $this->assertGreaterThanOrEqual($rows[1]['created_at'], $rows[0]['created_at']);
    }

    public function test_preset_and_custom_range(): void
    {
        $ledger = Member::findOrFail($this->mid('G-2024-002'))->savings()->orderBy('created_at')->get()
            ->map(fn ($s) => ['created_at' => $s->created_at->toDateTimeString()])->all();

        $c = Livewire::test(Show::class, ['member' => $this->mid('G-2024-002')])->call('applyHistoryPreset', '30-hari');
        $expected = collect($ledger)->filter(fn ($r) => Carbon::parse($r['created_at'])->gte(now()->subDays(29)->startOfDay()))->count();
        $this->assertCount($expected, $c->instance()->history['rows']);

        $day = substr($ledger[0]['created_at'], 0, 10);
        $c->set('historyFrom', $day)->set('historyTo', $day)->assertSet('historyPreset', 'kustom');
        $rows = $c->instance()->history['rows'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertStringStartsWith($day, $r['created_at']);
        }
    }

    public function test_type_filter_and_period_totals(): void
    {
        $c = Livewire::test(Show::class, ['member' => $this->mid('G-2024-002')])->set('historyType', 'setor');
        $h = $c->instance()->history;

        $this->assertSame(['setor'], array_values(array_unique(array_column($h['rows'], 'type'))));
        $this->assertSame(0, $h['out']);
        $this->assertSame(array_sum(array_column($h['rows'], 'amount')), $h['in']);
    }

    public function test_invalid_range_and_new_deposit_appears_first(): void
    {
        Livewire::test(Show::class, ['member' => $this->mid('S-2024-002')])
            ->set('historyFrom', '2026-10-10')
            ->set('historyTo', '2026-10-01')
            ->assertHasErrors(['historyTo' => 'after_or_equal']);

        $c = Livewire::test(Show::class, ['member' => $this->mid('S-2024-002')])
            ->call('openSaving', 'setor')->set('amount', 5000)->set('note', 'Setoran baru')->call('saveSaving');
        $this->assertSame('Setoran baru', $c->instance()->history['rows'][0]['note']);
    }
}
