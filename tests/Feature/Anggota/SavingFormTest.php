<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SavingFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_updates_balance_and_ledger(): void
    {
        Livewire::test(Show::class, ['member' => 2]) // saldo 142.500
            ->call('openSaving', 'setor')
            ->assertSet('mode', 'setor')
            ->assertSee('Setor tabungan')
            ->set('amount', 20000)
            ->set('note', 'Tabungan mingguan')
            ->call('saveSaving')
            ->assertHasNoErrors()
            ->assertSet('mode', null)
            ->assertSee('Rp162.500');

        $this->assertSame(162500, MockData::findMember(2)['balance']);
        $last = collect(MockData::savings(2))->last();
        $this->assertSame(['setor', 20000, 162500, 'Tabungan mingguan'], [$last['type'], $last['amount'], $last['balance_after'], $last['note']]);
    }

    public function test_withdrawal_cannot_exceed_balance(): void
    {
        Livewire::test(Show::class, ['member' => 7]) // saldo 12.000
            ->call('openSaving', 'tarik')
            ->set('amount', 15000)
            ->call('saveSaving')
            ->assertHasErrors(['amount' => 'max'])
            ->assertSee('Penarikan melebihi saldo')
            ->set('amount', 12000)
            ->call('saveSaving')
            ->assertHasNoErrors();

        $this->assertSame(0, MockData::findMember(7)['balance']);
    }

    public function test_minimum_amount_and_inactive_member(): void
    {
        Livewire::test(Show::class, ['member' => 1])
            ->call('openSaving', 'setor')
            ->set('amount', 500)
            ->call('saveSaving')
            ->assertHasErrors(['amount' => 'min']);

        Livewire::test(Show::class, ['member' => 9]) // nonaktif
            ->call('openSaving', 'setor')
            ->assertSet('mode', null)
            ->assertSee('Anggota nonaktif tidak dapat setor/tarik');
    }

    public function test_list_shows_updated_balance(): void
    {
        MockData::recordSaving(4, 'setor', 3000, null); // 37.000 → 40.000

        $this->get('/anggota')->assertSee('Rp40.000');
    }
}
