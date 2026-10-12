<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_shows_identity_and_balance(): void
    {
        $this->get('/anggota/2')
            ->assertOk()
            ->assertSee('Siti Nurhaliza')
            ->assertSee('S-2024-002')
            ->assertSee('Kelas 8B')
            ->assertSee('Rp142.500')
            ->assertSee('Total setoran');
    }

    public function test_savings_ledger_matches_balance(): void
    {
        foreach (MockData::members() as $m) {
            $ledger = MockData::savings($m['id']);
            $this->assertSame($m['balance'], $ledger ? end($ledger)['balance_after'] : 0, $m['name']);

            $stats = Livewire::test(Show::class, ['member' => $m['id']])->instance()->stats;
            $this->assertSame($m['balance'], $stats['deposit_total'] - $stats['withdraw_total'], $m['name']);
        }
    }

    public function test_list_links_to_profile_and_unknown_is_404(): void
    {
        $this->get('/anggota')->assertSee(route('anggota.show', 1), false);
        $this->get('/anggota/999')->assertNotFound();
    }
}
