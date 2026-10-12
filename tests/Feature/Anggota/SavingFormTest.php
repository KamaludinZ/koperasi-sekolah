<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Models\Member;
use App\Services\SavingsService;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SavingFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MemberSeeder::class);
    }

    public function test_deposit_updates_balance_and_ledger(): void
    {
        Livewire::test(Show::class, ['member' => $this->mid('S-2024-002')]) // saldo 142.500
            ->call('openSaving', 'setor')
            ->assertSet('mode', 'setor')
            ->assertSee('Setor tabungan')
            ->set('amount', 20000)
            ->set('note', 'Tabungan mingguan')
            ->call('saveSaving')
            ->assertHasNoErrors()
            ->assertSet('mode', null)
            ->assertSee('Rp162.500');

        $m = Member::findOrFail($this->mid('S-2024-002'));
        $this->assertSame('162500.00', $m->balance);
        $last = $m->savings()->latestFirst()->first();
        $this->assertSame(['setor', '20000.00', '162500.00', 'Tabungan mingguan'], [$last->type, $last->amount, $last->balance_after, $last->note]);
    }

    public function test_withdrawal_cannot_exceed_balance(): void
    {
        Livewire::test(Show::class, ['member' => $this->mid('S-2024-007')]) // saldo 12.000
            ->call('openSaving', 'tarik')
            ->set('amount', 15000)
            ->call('saveSaving')
            ->assertHasErrors('amount')
            ->assertSee('Penarikan melebihi saldo')
            ->set('amount', 12000)
            ->call('saveSaving')
            ->assertHasNoErrors();

        $this->assertSame('0.00', Member::findOrFail($this->mid('S-2024-007'))->balance);
    }

    public function test_minimum_amount_and_inactive_member(): void
    {
        Livewire::test(Show::class, ['member' => $this->mid('S-2024-001')])
            ->call('openSaving', 'setor')
            ->set('amount', 500)
            ->call('saveSaving')
            ->assertHasErrors(['amount' => 'min']);

        Livewire::test(Show::class, ['member' => $this->mid('S-2024-009')]) // nonaktif
            ->call('openSaving', 'setor')
            ->assertSet('mode', null)
            ->assertSee('Anggota nonaktif tidak dapat setor/tarik');
    }

    public function test_list_shows_updated_balance(): void
    {
        app(SavingsService::class)->deposit(Member::findOrFail($this->mid('S-2024-004')), 3000); // 37.000 → 40.000

        $this->get('/anggota')->assertSee('Rp40.000');
    }
}
