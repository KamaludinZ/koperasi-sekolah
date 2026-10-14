<?php

namespace Tests\Feature\Simpanan;

use App\Livewire\Simpanan\Entries;
use App\Models\Member;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EntriesPageTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    private Member $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 09:00:00');
        $this->andi = Member::factory()->create(['name' => 'Andi', 'joined_at' => '2026-10-01']);
        $this->budi = Member::factory()->create(['name' => 'Budi', 'joined_at' => '2026-10-01']);
        $svc = app(MemberSavingService::class);
        $sukarela = SavingType::where('code', 'SUKARELA')->first();
        $svc->deposit($this->andi, $sukarela, 20000);
        $svc->withdraw($this->andi, $sukarela, 5000);
        $svc->deposit($this->budi, SavingType::where('code', 'WAJIB')->first(), 0);
        $this->travelTo('2026-06-01 09:00:00');
        $svc->deposit($this->budi, $sukarela, 1000); // di luar 30 hari terakhir
        $this->travelTo('2026-10-14 09:00:00');
    }

    public function test_lists_and_filters(): void
    {
        $this->get('/simpanan/mutasi')->assertOk()->assertSee('Mutasi')->assertSee('Andi')->assertSee('Simpanan wajib Oktober 2026');

        Livewire::test(Entries::class)
            ->assertSet('period', '30-hari')->assertSee('+Rp30.000')->assertSee('−Rp5.000')
            ->set('kind', 'tarik')->assertSee('Penarikan Simpanan Sukarela')->assertDontSee('Budi')
            ->set('kind', '')->set('type', (string) SavingType::where('code', 'WAJIB')->value('id'))->assertSee('Budi')->assertDontSee('Andi')
            ->set('type', '')->set('search', 'and')->assertSee('Andi')->assertDontSee('Budi')
            ->set('search', '')->set('period', 'semua')->assertSee('+Rp31.000');
    }

    public function test_member_filter_from_profile(): void
    {
        Livewire::withQueryParams(['anggota' => $this->budi->id, 'periode' => 'semua'])->test(Entries::class)
            ->assertSee('Anggota:')->assertSee('+Rp11.000')->assertDontSee('Andi')
            ->call('clearMember')->assertSet('memberId', null)->assertSee('Andi');
    }

    public function test_kasir_cannot_open(): void
    {
        $this->seed(UserSeeder::class);
        $this->loginAs('kasir@koperasi.test');
        $this->get('/simpanan/mutasi')->assertForbidden();
    }
}
