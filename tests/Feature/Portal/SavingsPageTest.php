<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Savings;
use App\Models\Member;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use App\Services\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SavingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 09:00:00');
        $this->andi = Member::factory()->create(['name' => 'Andi', 'joined_at' => '2026-10-01', 'password' => '246810']);
        $budi = Member::factory()->create(['name' => 'Budi', 'joined_at' => '2026-10-01']);
        app(SavingsService::class)->deposit($this->andi, 25000, 'Setoran awal');
        app(MemberSavingService::class)->deposit($this->andi, SavingType::where('code', 'POKOK')->first(), 50000);
        app(SavingsService::class)->deposit($budi, 99000, 'Punya Budi');
        $this->actingAs($this->andi, 'member');
    }

    public function test_shows_only_own_balances_and_history(): void
    {
        $this->get('/portal/simpanan')->assertOk()
            ->assertSee('Rp75.000')->assertSeeHtml('data-balance="tabungan">Rp25.000')->assertSeeHtml('data-balance="simpanan">Rp50.000')
            ->assertSee('Simpanan Pokok')->assertSee('Lunas')->assertSee('Belum disetor 1 bulan')
            ->assertSee('Setoran Simpanan Pokok')->assertDontSee('Punya Budi');

        Livewire::test(Savings::class)->call('setTab', 'tabungan')->assertSee('Setoran awal')->assertDontSee('Punya Budi')
            ->call('setTab', 'lain')->assertSet('tab', 'simpanan');
    }

    public function test_history_filters_and_totals(): void
    {
        app(MemberSavingService::class)->deposit($this->andi, SavingType::where('code', 'SUKARELA')->first(), 8000);
        $this->travelTo('2026-05-01 09:00:00');
        app(SavingsService::class)->deposit($this->andi, 5000, 'Setoran lama');
        $this->travelTo('2026-10-14 09:00:00');

        Livewire::test(Savings::class)
            ->assertSee('+Rp58.000')
            ->set('typeFilter', (string) SavingType::where('code', 'SUKARELA')->value('id'))->assertSee('+Rp8.000')->assertDontSee('Setoran Simpanan Pokok')
            ->call('setTab', 'tabungan')->assertSet('typeFilter', '')->assertSee('Setoran lama')->assertSee('+Rp30.000')
            ->set('period', '30-hari')->assertDontSee('Setoran lama')->assertSee('+Rp25.000')
            ->set('period', 'x')->assertSet('period', 'semua');
    }

    public function test_load_more(): void
    {
        foreach (range(1, 22) as $i) {
            app(SavingsService::class)->deposit($this->andi, 1000, "Setor {$i}");
        }
        Livewire::test(Savings::class)->call('setTab', 'tabungan')->assertSee('Muat lebih banyak')->assertDontSee('Setoran awal')
            ->call('more')->assertSee('Setoran awal')->assertDontSee('Muat lebih banyak');
    }

    public function test_requires_member_login(): void
    {
        auth('member')->logout();
        $this->get('/portal/simpanan')->assertRedirect('/portal/masuk');
    }
}
