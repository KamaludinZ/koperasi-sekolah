<?php

namespace Tests\Feature\Simpanan;

use App\Models\Member;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use App\Services\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingBalanceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 09:00:00');
        $this->m = Member::factory()->create(['joined_at' => '2026-09-01', 'password' => 'rahasia123']);
        app(MemberSavingService::class)->deposit($this->m, SavingType::where('code', 'POKOK')->first(), 50000);
        app(MemberSavingService::class)->deposit($this->m, SavingType::where('code', 'SUKARELA')->first(), 7000);
        app(SavingsService::class)->deposit($this->m, 3000);
    }

    public function test_staff_endpoint(): void
    {
        $res = $this->getJson("/simpanan/anggota/{$this->m->id}/saldo")->assertOk()
            ->assertJsonPath('member.id', $this->m->id)->assertJsonPath('data.total', 57000);
        $types = collect($res->json('data.types'))->keyBy('code');
        $this->assertSame(['lunas', 'tunggak', null], [$types['POKOK']['status'], $types['WAJIB']['status'], $types['SUKARELA']['status']]);
        $this->assertSame(2, $types['WAJIB']['arrears_months']);
        $this->getJson('/simpanan/anggota/999999/saldo')->assertNotFound();
        $this->loginAs('kasir');
        $this->getJson("/simpanan/anggota/{$this->m->id}/saldo")->assertForbidden();
    }

    public function test_member_portal_endpoint(): void
    {
        $this->actingAsMember($this->m);
        $this->getJson('/portal/simpanan/data')->assertOk()->assertJsonPath('data.total', 57000)->assertJsonPath('data.tabungan', 3000);
        auth('member')->logout();
        $this->getJson('/portal/simpanan/data')->assertUnauthorized();
    }
}
