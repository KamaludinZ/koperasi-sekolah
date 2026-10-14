<?php

namespace Tests\Feature\Simpanan;

use App\Models\Member;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingTypeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_and_stats(): void
    {
        $m = Member::factory()->create();
        app(MemberSavingService::class)->deposit($m, SavingType::where('code', 'SUKARELA')->first(), 12000);

        $this->getJson('/simpanan/jenis/data')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.code', 'SUKARELA')->assertJsonPath('data.2.members', 1)->assertJsonPath('data.2.total_balance', 12000);
        $this->get('/simpanan')->assertSee('Rp12.000');

        $id = $this->postJson('/simpanan/jenis', ['code' => 'qurban', 'name' => 'Simpanan Qurban', 'kind' => 'lainnya', 'amount' => 0, 'period' => 'bebas', 'withdrawable' => true])
            ->assertCreated()->assertJsonPath('data.code', 'QURBAN')->json('data.id');
        $this->postJson('/simpanan/jenis', ['code' => 'X', 'name' => 'Wajib baru', 'kind' => 'wajib', 'amount' => 0, 'period' => 'bulanan'])->assertJsonValidationErrors('amount');
        $this->putJson("/simpanan/jenis/{$id}", ['code' => 'QURBAN', 'name' => 'Tabungan Qurban', 'kind' => 'lainnya', 'amount' => 50000, 'period' => 'bulanan'])
            ->assertOk()->assertJsonPath('data.amount', 50000);
        $this->patchJson("/simpanan/jenis/{$id}/status", ['aktif' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/simpanan/jenis/{$id}")->assertOk();
        $this->assertNull(SavingType::find($id));

        $sukarela = SavingType::where('code', 'SUKARELA')->value('id');
        $this->deleteJson("/simpanan/jenis/{$sukarela}")->assertJsonValidationErrors('type');
    }

    public function test_kasir_forbidden(): void
    {
        $this->loginAs('kasir');
        $this->getJson('/simpanan/jenis/data')->assertForbidden();
        $this->postJson('/simpanan/jenis', [])->assertForbidden();
    }
}
