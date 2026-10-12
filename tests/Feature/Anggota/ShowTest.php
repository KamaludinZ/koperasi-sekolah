<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Models\Member;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MemberSeeder::class);
    }

    public function test_profile_shows_identity_and_balance(): void
    {
        $this->get('/anggota/'.$this->mid('S-2024-002'))
            ->assertOk()
            ->assertSee('Siti Nurhaliza')
            ->assertSee('S-2024-002')
            ->assertSee('Kelas 8B')
            ->assertSee('Rp142.500')
            ->assertSee('Total setoran');
    }

    public function test_savings_ledger_matches_balance(): void
    {
        $this->assertSame(16, Member::count());
        foreach (Member::all() as $m) {
            $last = $m->savings()->latestFirst()->first();
            $this->assertSame((float) $m->balance, (float) ($last->balance_after ?? 0), $m->name);

            $stats = Livewire::test(Show::class, ['member' => $m->id])->instance()->stats;
            $this->assertSame((int) $m->balance, $stats['deposit_total'] - $stats['withdraw_total'], $m->name);
        }
    }

    public function test_list_links_to_profile_and_unknown_is_404(): void
    {
        $this->get('/anggota')->assertSee(route('anggota.show', $this->mid('S-2024-001')), false);
        $this->get('/anggota/999999')->assertNotFound();
    }
}
